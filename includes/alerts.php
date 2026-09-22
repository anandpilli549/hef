<?php
/**
 * Alert checking, triggered on admin page load instead of relying on
 * cron. Throttled so it only actually runs the checks once every 15
 * minutes overall (tracked via a marker row), regardless of how many
 * people are browsing the site in that window.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/global.php';
require_once __DIR__ . '/mail-templates.php';
require_once __DIR__ . '/permissions.php'; // Pro-access check, used when emailing team-shared reminders
require_once __DIR__ . '/payroll.php'; // salary-due section of the daily summary

/**
 * Notifies every active user in a company across every channel that's
 * configured: email; SMS if the user has a phone number and the
 * company has MSG91 set up; WhatsApp if a template name is configured
 * for this notification kind ('alert' or 'booking').
 * Users who switched off "Email me each alert as it happens" (My Profile)
 * skip the email for 'alert' notifications only; booking emails always go
 * out, and SMS / WhatsApp are unaffected.
 */
function hef_notify_company(
    PDO $pdo,
    int $companyId,
    string $subject,
    string $bodyHtml,
    ?string $smsMessage = null,
    ?array $whatsappParams = null,
    string $whatsappKind = 'alert'
): void {
    $stmt = $pdo->prepare("SELECT id, email, phone, alerts_immediate FROM users WHERE company_id = ? AND status = 'active'");
    $stmt->execute([$companyId]);
    $users = $stmt->fetchAll();

    $settings = hef_get_notification_settings($pdo, $companyId);
    $waTemplate = $whatsappKind === 'booking'
        ? ($settings['whatsapp_booking_template'] ?? null)
        : ($settings['whatsapp_alert_template'] ?? null);
    if (is_array($settings) && array_key_exists('whatsapp_enabled', $settings) && ! $settings['whatsapp_enabled']) {
        $waTemplate = null; // WhatsApp API sending is switched off in Settings
    }

    foreach ($users as $user) {
        if ($whatsappKind !== 'alert' || (int) $user['alerts_immediate'] === 1) {
            hef_send_mail($pdo, $user['email'], $subject, $bodyHtml, $companyId, $user['id']);
        }

        if ($smsMessage && ! empty($user['phone'])) {
            hef_send_sms($pdo, $user['phone'], $smsMessage, $companyId, $user['id']);
        }

        if ($whatsappParams && $waTemplate && ! empty($user['phone'])) {
            hef_send_whatsapp($pdo, $user['phone'], $waTemplate, $whatsappParams, $companyId, $user['id']);
        }
    }
}

const ALERT_CHECK_INTERVAL_MINUTES = 15;

function hef_maybe_run_alert_checks(PDO $pdo): void
{
    $stmt = $pdo->query(
        "SELECT MAX(triggered_at) as last_run FROM alerts_log
         WHERE type IN ('vaccination_due','feed_low','subscription_expiring')"
    );
    $lastRun = $stmt->fetch()['last_run'] ?? null;

    if ($lastRun && strtotime($lastRun) > strtotime('-' . ALERT_CHECK_INTERVAL_MINUTES . ' minutes')) {
        return; // checked recently, skip
    }

    hef_check_vaccinations_due($pdo);
    hef_check_feed_low($pdo);
    hef_check_subscriptions_expiring($pdo);
    hef_expire_lapsed_subscriptions($pdo);
    hef_send_due_reminders($pdo);
}

/**
 * SQL pieces that fetch the name of the record a note is linked to.
 * Use as: SELECT n.*, {select} FROM user_notes n {joins} ...
 * link_label is NULL when the note has no link or the record was deleted.
 */
function hef_note_link_sql(): array
{
    return [
        'select' => "CASE n.link_type WHEN 'batch' THEN lb.batch_code WHEN 'customer' THEN lc.name WHEN 'supplier' THEN ls.name END AS link_label",
        'joins' => "LEFT JOIN batches lb ON n.link_type = 'batch' AND lb.id = n.link_id AND lb.company_id = n.company_id
                    LEFT JOIN customers lc ON n.link_type = 'customer' AND lc.id = n.link_id AND lc.company_id = n.company_id
                    LEFT JOIN suppliers ls ON n.link_type = 'supplier' AND ls.id = n.link_id AND ls.company_id = n.company_id",
    ];
}

/**
 * ['label' => 'Batch B-001', 'url' => '/hef/admin/batch-view.php?id=12', 'icon' => 'bi-egg-fried']
 * for a note row fetched with hef_note_link_sql(), or null when it has no
 * (surviving) link. Customers and suppliers open their list page, and are
 * hidden (null) when $canSeeCustomers is false.
 */
function hef_note_link_info(array $row, bool $canSeeCustomers = true): ?array
{
    if (empty($row['link_type']) || ($row['link_label'] ?? null) === null) {
        return null;
    }
    // Customers and suppliers are Pro-owner pages; don't reveal their names
    // to teammates who can't open them (shared notes, team emails).
    if (! $canSeeCustomers && in_array($row['link_type'], ['customer', 'supplier'], true)) {
        return null;
    }
    switch ($row['link_type']) {
        case 'batch':
            return ['label' => 'Batch ' . $row['link_label'], 'url' => '/hef/admin/batch-view.php?id=' . (int) $row['link_id'], 'icon' => 'bi-egg-fried'];
        case 'customer':
            return ['label' => 'Customer ' . $row['link_label'], 'url' => '/hef/admin/customers.php', 'icon' => 'bi-person-badge'];
        default:
            return ['label' => 'Supplier ' . $row['link_label'], 'url' => '/hef/admin/suppliers.php', 'icon' => 'bi-truck'];
    }
}

/**
 * "Today" as a Y-m-d string in the given time zone (a company's setting,
 * Asia/Kolkata if missing or invalid). Reminders are plain dates, so they
 * must be judged in the user's own time zone, not the web server's.
 */
function hef_company_today(?string $timezone): string
{
    try {
        $tz = new DateTimeZone($timezone ?: 'Asia/Kolkata');
    } catch (Exception $e) {
        $tz = new DateTimeZone('Asia/Kolkata');
    }

    return (new DateTimeImmutable('now', $tz))->format('Y-m-d');
}

/**
 * Next date after $today for a repeating reminder, stepping forward from
 * its current date. Steps repeatedly, so a missed run doesn't cause a
 * burst of catch-up reminders. Monthly reminders keep their day of month,
 * except that one on the 29th-31st moves to the last day of a shorter
 * month and stays on that day afterwards.
 */
function hef_next_reminder_date(string $current, string $rule, string $today): ?string
{
    if (! in_array($rule, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
        return null;
    }

    $date = new DateTimeImmutable($current);
    $day = (int) $date->format('j');
    $guard = 0;

    while ($date->format('Y-m-d') <= $today && $guard++ < 4000) {
        switch ($rule) {
            case 'daily':
                $date = $date->modify('+1 day');
                break;
            case 'weekly':
                $date = $date->modify('+7 days');
                break;
            case 'monthly':
                $first = $date->modify('first day of next month');
                $date = $first->setDate(
                    (int) $first->format('Y'),
                    (int) $first->format('n'),
                    min($day, (int) $first->format('t'))
                );
                break;
            default: // yearly
                $date = $date->modify('+1 year');
        }
    }

    return $date->format('Y-m-d');
}

/**
 * Emails reminders that have come due (admin/notes.php). A reminder is due
 * once its date is today or earlier in the company's time zone, and it hasn't
 * been emailed for that date yet. A private reminder goes to the user who
 * made it; one shared with the team goes to every active user in the company.
 * One-time reminders are emailed once and stay on the list until someone
 * marks them done. Repeating reminders move to their next date after each
 * email. A reminder is only marked as sent when at least one email actually
 * went out, so a total failure is retried on the next run.
 *
 * @return int number of reminders emailed
 */
function hef_send_due_reminders(PDO $pdo): int
{
    // Selected a day ahead of the server date, then narrowed per company
    // below, because a company's own "today" can be ahead of the server's.
    $link = hef_note_link_sql();
    $rows = $pdo->query(
        "SELECT n.id, n.title, n.body, n.remind_on, n.repeat_rule, n.company_id, n.link_type, n.link_id, n.is_shared,
                u.id AS user_id, u.name AS user_name, u.email, u.role AS user_role, c.timezone, {$link['select']}
         FROM user_notes n
         JOIN users u ON u.id = n.user_id AND (u.status = 'active' OR n.is_shared = 1)
         JOIN companies c ON c.id = n.company_id
         {$link['joins']}
         WHERE n.status = 'open'
           AND n.remind_on IS NOT NULL
           AND n.remind_on <= DATE_ADD(CURDATE(), INTERVAL 1 DAY)
           AND (n.last_reminded_on IS NULL OR n.last_reminded_on < n.remind_on)
         ORDER BY n.remind_on, n.id
         LIMIT 500"
    )->fetchAll();

    $teamCache = []; // company_id => active users with an email
    $sent = 0;
    foreach ($rows as $row) {
        try {
            $today = hef_company_today($row['timezone']);
            if ($row['remind_on'] > $today) {
                continue; // not due yet where this user is
            }

            $companyId = (int) $row['company_id'];
            if ((int) $row['is_shared'] === 1) {
                if (! isset($teamCache[$companyId])) {
                    $stmt = $pdo->prepare("SELECT id, name, email, role, company_id FROM users WHERE company_id = ? AND status = 'active' AND email != ''");
                    $stmt->execute([$companyId]);
                    $teamCache[$companyId] = $stmt->fetchAll();
                }
                $recipients = $teamCache[$companyId];
            } else {
                $recipients = [[
                    'id' => $row['user_id'], 'name' => $row['user_name'], 'email' => $row['email'],
                    'role' => $row['user_role'], 'company_id' => $companyId,
                ]];
            }

            $when = $row['remind_on'] === $today
                ? 'Today'
                : 'Was set for ' . date('d M Y', strtotime($row['remind_on']));

            $anySent = false;
            foreach ($recipients as $rcpt) {
                $inner = hef_email_alert_badge($when, '#2f7d4f')
                    . '<p style="margin:12px 0 4px; color:#222; font-size:17px; font-weight:bold;">'
                    . htmlspecialchars($row['title']) . '</p>';
                if (! empty($row['body'])) {
                    $inner .= '<p style="margin:0 0 12px; color:#444; font-size:14px; line-height:1.5;">'
                        . nl2br(htmlspecialchars($row['body'])) . '</p>';
                }
                $details = ['Reminder date' => date('d M Y', strtotime($row['remind_on']))];
                if ((int) $row['is_shared'] === 1 && (int) $rcpt['id'] !== (int) $row['user_id']) {
                    $details['Shared by'] = $row['user_name'];
                }
                if ($row['repeat_rule'] !== 'none') {
                    $details['Repeats'] = ucfirst($row['repeat_rule']);
                }
                // Each person only sees a linked customer / supplier if they could open it.
                $linked = hef_note_link_info($row, hef_user_can_access_pro_pages($pdo, $rcpt));
                if ($linked) {
                    $details['Related to'] = $linked['label'];
                }
                $inner .= hef_email_details_table($details)
                    . hef_email_button(
                        'https://cthkennels.com' . ($linked ? $linked['url'] : '/hef/admin/notes.php'),
                        $linked ? 'Open ' . $linked['label'] : 'Open Notes & Reminders'
                    );

                $html = hef_email_wrap('⏰ Reminder', $inner, $row['title']);
                if (hef_send_mail($pdo, $rcpt['email'], 'Reminder: ' . $row['title'], $html, $companyId, (int) $rcpt['id'])) {
                    $anySent = true;
                }
            }
            if (! $anySent) {
                continue;
            }

            if ($row['repeat_rule'] !== 'none') {
                $next = hef_next_reminder_date($row['remind_on'], $row['repeat_rule'], $today);
                $pdo->prepare('UPDATE user_notes SET remind_on = ?, last_reminded_on = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$next, $today, $row['id']]);
            } else {
                $pdo->prepare('UPDATE user_notes SET last_reminded_on = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$today, $row['id']]);
            }
            $sent++;
        } catch (Throwable $e) {
            error_log('HEFarm reminder ' . $row['id'] . ' failed: ' . $e->getMessage());
        }
    }

    return $sent;
}

/**
 * Flips a company to 'expired' status when its active paid subscription
 * has passed its end date without renewal. Trial expiry is handled
 * separately by the billing banner (doesn't need a status flip since
 * "trial" already reads correctly past its end date).
 */
function hef_expire_lapsed_subscriptions(PDO $pdo): void
{
    $stmt = $pdo->query(
        "SELECT id, company_id FROM company_subscriptions WHERE status = 'active' AND ends_at < NOW() AND company_id != " . HEF_OWNER_COMPANY_ID
    );
    foreach ($stmt->fetchAll() as $row) {
        $pdo->prepare("UPDATE company_subscriptions SET status = 'expired', updated_at = NOW() WHERE id = ?")
            ->execute([$row['id']]);
        $pdo->prepare("UPDATE companies SET status = 'expired', updated_at = NOW() WHERE id = ?")
            ->execute([$row['company_id']]);
    }
}

function hef_check_vaccinations_due(PDO $pdo): void
{
    // Batches whose age matches a species vaccination_schedule.age_in_days,
    // with no matching vaccination_record yet. Pulls full batch context
    // so the notification email can show real details, not just an ID.
    $sql = "
        SELECT
            b.id AS batch_id, b.company_id, b.batch_code, b.date_acquired, b.age_at_acquisition_days,
            b.current_count_male, b.current_count_female, b.current_count_unknown,
            s.name AS species_name, r.name AS room_name,
            vs.id AS schedule_id, vs.name AS vaccine_name, vs.age_in_days AS due_at_days
        FROM batches b
        JOIN species s ON s.id = b.species_id
        LEFT JOIN rooms r ON r.id = b.room_id
        JOIN vaccination_schedules vs ON vs.species_id = b.species_id
        WHERE (DATEDIFF(CURDATE(), b.date_acquired) + b.age_at_acquisition_days) >= vs.age_in_days
          AND b.status = 'active'
          AND NOT EXISTS (
              SELECT 1 FROM vaccination_records vr
              WHERE vr.batch_id = b.id AND vr.vaccination_schedule_id = vs.id
          )
          AND NOT EXISTS (
              SELECT 1 FROM alerts_log al
              WHERE al.type = 'vaccination_due'
                AND al.reference_type = 'batch_vaccination_schedule'
                AND al.reference_id = b.id
                AND al.status != 'failed'
                AND al.resolved_at IS NULL
          )
    ";
    foreach ($pdo->query($sql) as $row) {
        $message = "Batch {$row['batch_code']} is due for vaccination: {$row['vaccine_name']}";

        $stmt = $pdo->prepare(
            "INSERT INTO alerts_log (company_id, type, reference_type, reference_id, message, status, triggered_at, created_at, updated_at)
             VALUES (?, 'vaccination_due', 'batch_vaccination_schedule', ?, ?, 'pending', NOW(), NOW(), NOW())"
        );
        $stmt->execute([$row['company_id'], $row['batch_id'], $message]);
        $alertId = (int) $pdo->lastInsertId();

        $currentAgeDays = (int) (strtotime('today') - strtotime($row['date_acquired'])) / 86400 + (int) $row['age_at_acquisition_days'];
        $daysOverdue = max(0, $currentAgeDays - (int) $row['due_at_days']);
        $totalAlive = $row['current_count_male'] + $row['current_count_female'] + $row['current_count_unknown'];

        $inner = '<p style="margin:0 0 8px; color:#444; font-size:14px;">A vaccination is due for one of your batches.</p>'
            . hef_email_alert_badge($daysOverdue > 0 ? "{$daysOverdue} day(s) overdue" : 'Due today', '#c0392b')
            . hef_email_details_table([
                'Vaccine' => $row['vaccine_name'],
                'Batch' => $row['batch_code'],
                'Species' => $row['species_name'],
                'Room' => $row['room_name'] ?? 'Unassigned',
                'Animals alive' => $totalAlive,
                'Date acquired' => date('d M Y', strtotime($row['date_acquired'])),
                'Current age' => "{$currentAgeDays} days",
                'Due at age' => "{$row['due_at_days']} days",
            ])
            . hef_email_button("https://cthkennels.com/hef/admin/batch-view.php?id={$row['batch_id']}", 'Open This Batch');

        $html = hef_email_wrap('💉 Vaccination Due', $inner, $message);
        $smsText = "HEFarm Alert: {$row['vaccine_name']} due for batch {$row['batch_code']} ({$row['species_name']}). "
            . ($daysOverdue > 0 ? "{$daysOverdue} day(s) overdue." : 'Due today.');
        hef_notify_company(
            $pdo, $row['company_id'], "Vaccination due: {$row['vaccine_name']} ({$row['batch_code']})", $html,
            $smsText, [$row['vaccine_name'], $row['batch_code']], 'alert'
        );
        $pdo->prepare("UPDATE alerts_log SET status = 'sent' WHERE id = ?")->execute([$alertId]);
    }
}

function hef_check_feed_low(PDO $pdo): void
{
    // Real stock balance now available (purchases minus consumption, per
    // species+unit) — flag any species whose balance has run out or gone
    // negative.
    $sql = "
        SELECT s.id AS species_id, s.company_id, s.name, fp.unit,
               COALESCE(SUM(fp.quantity), 0) AS purchased,
               COALESCE((
                   SELECT SUM(fc.quantity) FROM feed_consumption fc
                   JOIN batches b2 ON b2.id = fc.batch_id
                   WHERE b2.species_id = s.id AND fc.unit = fp.unit
               ), 0) AS consumed
        FROM species s
        JOIN feed_purchases fp ON fp.species_id = s.id
        GROUP BY s.id, s.company_id, s.name, fp.unit
        HAVING (purchased - consumed) <= 0
    ";
    foreach ($pdo->query($sql) as $row) {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM alerts_log
             WHERE type = 'feed_low' AND reference_type = 'species' AND reference_id = ?
               AND triggered_at >= (NOW() - INTERVAL 3 DAY)"
        );
        $stmt->execute([$row['species_id']]);
        if ($stmt->fetch()) {
            continue; // already alerted recently
        }

        $balance = $row['purchased'] - $row['consumed'];
        $message = "Feed stock for {$row['name']} is out ({$balance} {$row['unit']} remaining) — log a purchase.";

        $stmt = $pdo->prepare(
            "INSERT INTO alerts_log (company_id, type, reference_type, reference_id, message, status, triggered_at, created_at, updated_at)
             VALUES (?, 'feed_low', 'species', ?, ?, 'pending', NOW(), NOW(), NOW())"
        );
        $stmt->execute([$row['company_id'], $row['species_id'], $message]);
        $alertId = (int) $pdo->lastInsertId();

        // Batches of this species, so the owner knows who's affected
        $stmt = $pdo->prepare(
            "SELECT batch_code FROM batches WHERE species_id = ? AND status = 'active' ORDER BY batch_code"
        );
        $stmt->execute([$row['species_id']]);
        $affectedBatches = implode(', ', $stmt->fetchAll(PDO::FETCH_COLUMN)) ?: 'None active';

        $inner = '<p style="margin:0 0 8px; color:#444; font-size:14px;">Feed stock has run out for a species you\'re raising.</p>'
            . hef_email_alert_badge('Out of stock', '#c0392b')
            . hef_email_details_table([
                'Species' => $row['name'],
                'Stock remaining' => "{$balance} {$row['unit']}",
                'Total purchased' => "{$row['purchased']} {$row['unit']}",
                'Total consumed' => "{$row['consumed']} {$row['unit']}",
                'Affected batches' => $affectedBatches,
            ])
            . hef_email_button('https://cthkennels.com/hef/admin/feed.php', 'Log a Feed Purchase');

        $html = hef_email_wrap('🌾 Feed Stock Low', $inner, $message);
        $smsText = "HEFarm Alert: Feed stock for {$row['name']} is out ({$balance} {$row['unit']} remaining). Batches: {$affectedBatches}.";
        hef_notify_company(
            $pdo, $row['company_id'], "Feed stock low: {$row['name']}", $html,
            $smsText, [$row['name'], (string) $balance], 'alert'
        );
        $pdo->prepare("UPDATE alerts_log SET status = 'sent' WHERE id = ?")->execute([$alertId]);
    }
}

function hef_check_subscriptions_expiring(PDO $pdo): void
{
    $sql = "
        SELECT cs.id, cs.company_id, cs.ends_at, sp.name AS plan_name
        FROM company_subscriptions cs
        JOIN subscription_plans sp ON sp.id = cs.subscription_plan_id
        WHERE cs.status = 'active'
          AND cs.ends_at BETWEEN NOW() AND (NOW() + INTERVAL 7 DAY)
          AND cs.company_id != " . HEF_OWNER_COMPANY_ID . "
          AND NOT EXISTS (
              SELECT 1 FROM alerts_log al
              WHERE al.type = 'subscription_expiring' AND al.reference_type = 'company_subscription'
                AND al.reference_id = cs.id AND al.triggered_at >= (NOW() - INTERVAL 7 DAY)
          )
    ";
    foreach ($pdo->query($sql) as $row) {
        $message = "Subscription expiring on {$row['ends_at']}.";
        $stmt = $pdo->prepare(
            "INSERT INTO alerts_log (company_id, type, reference_type, reference_id, message, status, triggered_at, created_at, updated_at)
             VALUES (?, 'subscription_expiring', 'company_subscription', ?, ?, 'pending', NOW(), NOW(), NOW())"
        );
        $stmt->execute([$row['company_id'], $row['id'], $message]);
        $alertId = (int) $pdo->lastInsertId();

        $daysLeft = max(0, (int) ((strtotime($row['ends_at']) - time()) / 86400));

        $inner = '<p style="margin:0 0 8px; color:#444; font-size:14px;">Your HEFarm subscription is expiring soon.</p>'
            . hef_email_alert_badge("{$daysLeft} day(s) left", '#b8860b')
            . hef_email_details_table([
                'Plan' => $row['plan_name'],
                'Expires on' => date('d M Y', strtotime($row['ends_at'])),
                'Days remaining' => $daysLeft,
            ])
            . hef_email_button('https://cthkennels.com/hef/admin/settings.php', 'Manage Subscription');

        $html = hef_email_wrap('⏳ Subscription Expiring', $inner, $message);
        $smsText = "HEFarm Alert: Your {$row['plan_name']} subscription expires in {$daysLeft} day(s) on " . date('d M Y', strtotime($row['ends_at'])) . '.';
        hef_notify_company($pdo, $row['company_id'], 'Your HEFarm subscription is expiring soon', $html, $smsText);
        $pdo->prepare("UPDATE alerts_log SET status = 'sent' WHERE id = ?")->execute([$alertId]);
    }
}

/**
 * Everything in a company that needs attention right now, for the daily
 * summary email. Read live, not from the one-time alerts already sent, so
 * an item that is still outstanding shows up again each morning.
 */
function hef_digest_company_data(PDO $pdo, int $companyId): array
{
    // Vaccinations still due (resolved when the vaccination is recorded).
    $stmt = $pdo->prepare(
        "SELECT al.reference_id AS batch_id, al.message
         FROM alerts_log al
         JOIN batches b ON b.id = al.reference_id AND b.status = 'active'
         WHERE al.company_id = ? AND al.type = 'vaccination_due'
           AND al.reference_type = 'batch_vaccination_schedule'
           AND al.resolved_at IS NULL AND al.status != 'failed'
         ORDER BY al.triggered_at"
    );
    $stmt->execute([$companyId]);
    $vaccinations = $stmt->fetchAll();

    // Feed that has run out: purchases minus consumption, per species and unit.
    $stmt = $pdo->prepare(
        "SELECT s.name, fp.unit,
                COALESCE(SUM(fp.quantity), 0) AS purchased,
                COALESCE((
                    SELECT SUM(fc.quantity) FROM feed_consumption fc
                    JOIN batches b2 ON b2.id = fc.batch_id
                    WHERE b2.species_id = s.id AND fc.unit = fp.unit
                ), 0) AS consumed
         FROM species s
         JOIN feed_purchases fp ON fp.species_id = s.id
         WHERE s.company_id = ?
         GROUP BY s.id, s.name, fp.unit
         HAVING (purchased - consumed) <= 0
         ORDER BY s.name"
    );
    $stmt->execute([$companyId]);
    $feedOut = $stmt->fetchAll();

    // Paid plan ending within a week (the operator's own company never expires).
    $stmt = $pdo->prepare(
        "SELECT cs.ends_at, sp.name AS plan_name
         FROM company_subscriptions cs
         JOIN subscription_plans sp ON sp.id = cs.subscription_plan_id
         WHERE cs.company_id = ? AND cs.company_id != ? AND cs.status = 'active'
           AND cs.ends_at BETWEEN NOW() AND (NOW() + INTERVAL 7 DAY)
         ORDER BY cs.ends_at LIMIT 1"
    );
    $stmt->execute([$companyId, HEF_OWNER_COMPANY_ID]);
    $subscription = $stmt->fetch() ?: null;

    return ['vaccinations' => $vaccinations, 'feedOut' => $feedOut, 'subscription' => $subscription];
}

/** One titled bullet list for the summary email. $items are already-escaped HTML. */
function hef_digest_section(string $title, array $items, string $color, int $max = 12): string
{
    $more = count($items) - $max;
    $items = array_slice($items, 0, $max);
    $html = '<p style="margin:18px 0 6px; font-size:15px; font-weight:bold; color:' . $color . ';">'
        . htmlspecialchars($title) . ' (' . ($more > 0 ? $max + $more : count($items)) . ')</p>'
        . '<ul style="margin:0; padding-left:18px; color:#333; font-size:14px; line-height:1.6;">';
    foreach ($items as $item) {
        $html .= '<li>' . $item . '</li>';
    }
    if ($more > 0) {
        $html .= '<li style="color:#777;">…and ' . $more . ' more</li>';
    }

    return $html . '</ul>';
}

/**
 * Sends each user who switched on "Send me a daily summary" one email listing
 * what needs attention, at most once a day (by the company's own date), and
 * nothing at all when there is nothing to report:
 *   - vaccinations still due, and feed that has run out (everyone; feed is
 *     left out for vets, who don't see the Feed page)
 *   - the plan ending soon (Owner only)
 *   - the user's own reminders, and ones shared with the team, that are
 *     overdue or due in the next 3 days.
 *     Reminders due today were already emailed on their own by
 *     hef_send_due_reminders(), so they are only listed here if that email
 *     hasn't gone out yet.
 * Run this after hef_send_due_reminders().
 *
 * @return int number of summary emails sent
 */
function hef_send_daily_digests(PDO $pdo): int
{
    $users = $pdo->query(
        "SELECT u.id, u.company_id, u.name, u.email, u.role, u.last_digest_on, c.timezone
         FROM users u
         JOIN companies c ON c.id = u.company_id
         WHERE u.status = 'active' AND u.digest_enabled = 1 AND u.email != ''
         ORDER BY u.company_id, u.id
         LIMIT 1000"
    )->fetchAll();

    $companyData = [];
    $sent = 0;

    foreach ($users as $u) {
        try {
            $today = hef_company_today($u['timezone']);
            if ($u['last_digest_on'] === $today) {
                continue; // already sent today
            }

            $companyId = (int) $u['company_id'];
            if (! isset($companyData[$companyId])) {
                $companyData[$companyId] = hef_digest_company_data($pdo, $companyId);
            }
            $data = $companyData[$companyId];
            $base = 'https://cthkennels.com/hef/admin/';
            $sections = '';
            $counts = ['vaccinations' => 0, 'feed' => 0, 'reminders' => 0];

            // Reminders: this user's own, plus ones shared with the team.
            $stmt = $pdo->prepare(
                "SELECT title, remind_on, last_reminded_on FROM user_notes
                 WHERE status = 'open' AND remind_on IS NOT NULL AND remind_on <= ?
                   AND (user_id = ? OR (is_shared = 1 AND company_id = ?))
                 ORDER BY remind_on, id"
            );
            $stmt->execute([date('Y-m-d', strtotime($today . ' +3 days')), (int) $u['id'], $companyId]);
            $reminderItems = [];
            foreach ($stmt->fetchAll() as $r) {
                $diff = (int) round((strtotime($r['remind_on']) - strtotime($today)) / 86400);
                if ($diff === 0 && $r['last_reminded_on'] !== null && $r['last_reminded_on'] >= $r['remind_on']) {
                    continue; // already emailed on its own this morning
                }
                if ($diff < 0) {
                    $when = '<span style="color:#c0392b;">overdue by ' . abs($diff) . ($diff === -1 ? ' day' : ' days') . '</span>';
                } elseif ($diff === 0) {
                    $when = '<strong>today</strong>';
                } elseif ($diff === 1) {
                    $when = 'tomorrow';
                } else {
                    $when = 'in ' . $diff . ' days (' . date('d M', strtotime($r['remind_on'])) . ')';
                }
                $reminderItems[] = htmlspecialchars($r['title']) . ' — ' . $when;
            }

            $vaccItems = [];
            foreach ($data['vaccinations'] as $v) {
                $vaccItems[] = '<a href="' . $base . 'batch-view.php?id=' . (int) $v['batch_id'] . '" style="color:#2f7d4f;">'
                    . htmlspecialchars($v['message']) . '</a>';
            }

            $feedItems = [];
            if ($u['role'] !== 'vet') {
                foreach ($data['feedOut'] as $f) {
                    $balance = $f['purchased'] - $f['consumed'];
                    $feedItems[] = htmlspecialchars($f['name']) . ': ' . htmlspecialchars((string) $balance . ' ' . $f['unit']) . ' left';
                }
            }

            if ($vaccItems) {
                $sections .= hef_digest_section('💉 Vaccinations due', $vaccItems, '#c0392b');
            }
            if ($feedItems) {
                $sections .= hef_digest_section('🌾 Feed out of stock', $feedItems, '#c0392b');
            }
            if ($u['role'] === 'owner' && $data['subscription']) {
                $left = max(0, (int) ((strtotime($data['subscription']['ends_at']) - time()) / 86400));
                $sections .= hef_digest_section('⏳ Subscription', [
                    htmlspecialchars($data['subscription']['plan_name']) . ' plan ends on '
                    . date('d M Y', strtotime($data['subscription']['ends_at'])) . ' (' . $left . ' day(s) left)',
                ], '#b8860b');
            }
            if ($u['role'] === 'owner') {
                if (! array_key_exists('payroll', $companyData[$companyId])) {
                    try {
                        $companyData[$companyId]['payroll'] = hef_payroll_due_info($pdo, $companyId, $u['timezone']);
                    } catch (PDOException $e) {
                        $companyData[$companyId]['payroll'] = null; // staff tables not created yet
                    }
                }
                $pay = $companyData[$companyId]['payroll'];
                if ($pay) {
                    $when = $pay['days'] > 0 ? 'due in ' . $pay['days'] . ' day' . ($pay['days'] === 1 ? '' : 's')
                        : ($pay['days'] === 0 ? 'due today' : 'overdue by ' . abs($pay['days']) . ' day' . (abs($pay['days']) === 1 ? '' : 's'));
                    $sections .= hef_digest_section('💰 Salary', [
                        htmlspecialchars(date('F Y', strtotime($pay['month']))) . ' salary is ' . $when . ' (' . date('d M', strtotime($pay['due']))
                        . '): <a href="' . $base . 'staff-payroll.php?month=' . substr($pay['month'], 0, 7) . '" style="color:#2f7d4f;">'
                        . $pay['unpaid'] . ' staff not paid yet</a>',
                    ], $pay['days'] < 0 ? '#c0392b' : '#b8860b');
                }
            }
            if ($reminderItems) {
                $sections .= hef_digest_section('⏰ Your reminders', $reminderItems, '#2f7d4f');
            }

            if ($sections === '') {
                continue; // nothing to report today
            }

            $first = explode(' ', trim($u['name']))[0] ?? '';
            $inner = '<p style="margin:0; color:#444; font-size:14px;">Good morning' . ($first !== '' ? ', ' . htmlspecialchars($first) : '')
                . '. Here is what needs attention today.</p>'
                . $sections
                . hef_email_button($base . 'dashboard.php', 'Open HEFarm')
                . '<p style="margin:16px 0 0; color:#888; font-size:12px;">You get this because the daily summary is switched on in '
                . '<a href="' . $base . 'profile.php" style="color:#888;">My Profile</a>.</p>';

            $subject = 'Your HEFarm daily summary — ' . date('d M', strtotime($today));
            $html = hef_email_wrap('☀️ Daily Summary', $inner, 'What needs attention today');

            if (hef_send_mail($pdo, $u['email'], $subject, $html, $companyId, (int) $u['id'])) {
                $pdo->prepare('UPDATE users SET last_digest_on = ? WHERE id = ?')->execute([$today, (int) $u['id']]);
                $sent++;
            }
        } catch (Throwable $e) {
            error_log('HEFarm daily summary for user ' . $u['id'] . ' failed: ' . $e->getMessage());
        }
    }

    return $sent;
}
