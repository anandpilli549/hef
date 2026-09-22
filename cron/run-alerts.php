<?php
/**
 * Scheduled runner for alert emails, users' reminders, the daily summary
 * emails and subscription expiry.
 *
 * Call it from the hosting panel's cron job (HTTP GET), e.g. every 15 min:
 *
 *   https://cthkennels.com/hef/cron/run-alerts.php?key=YOUR_CRON_SECRET
 *
 * If the panel won't accept a "?key=" in the path, this also works:
 *
 *   https://cthkennels.com/hef/cron/run-alerts.php/YOUR_CRON_SECRET
 *
 * Why it exists: the same checks already run when someone opens an admin
 * page (see admin/bootstrap.php), but that only happens while people are
 * browsing. This runs them on a schedule, so vaccination-due, feed-low and
 * subscription-expiring emails and users' reminders (admin/notes.php) go out,
 * and lapsed subscriptions expire, even when nobody is logged in. Running both is safe: every check skips alerts
 * it has already sent.
 *
 * Setup: add this line to includes/config.php, using your own long random
 * secret (32+ letters and digits, no spaces or symbols):
 *
 *   define('CRON_SECRET', 'PASTE-YOUR-OWN-RANDOM-SECRET-HERE');
 *
 * Without a secret the script refuses to run, so it can never be triggered
 * by strangers.
 */

require_once __DIR__ . '/../includes/config.php';

$isCli = PHP_SAPI === 'cli';

if (! $isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');

    if (! defined('CRON_SECRET') || strlen((string) CRON_SECRET) < 16) {
        http_response_code(503);
        echo "Cron is not configured.\n";
        exit;
    }

    $given = isset($_GET['key'])
        ? (string) $_GET['key']
        : ltrim((string) ($_SERVER['PATH_INFO'] ?? ''), '/');

    if (! hash_equals((string) CRON_SECRET, $given)) {
        http_response_code(403);
        echo "Forbidden\n";
        exit;
    }
}

// Loads the database connection ($pdo), mail helpers and the alert checks.
require_once __DIR__ . '/../includes/alerts.php';

ignore_user_abort(true);
set_time_limit(120);

// One run at a time: if the previous run is still busy, skip this one.
$gotLock = (int) $pdo->query("SELECT GET_LOCK('hef_alert_cron', 0)")->fetchColumn();
if (! $gotLock) {
    echo "Skipped: previous run still in progress.\n";
    exit;
}

$started = microtime(true);

try {
    $before = (int) $pdo->query('SELECT COUNT(*) FROM alerts_log')->fetchColumn();

    hef_check_vaccinations_due($pdo);
    hef_check_feed_low($pdo);
    hef_check_subscriptions_expiring($pdo);
    hef_expire_lapsed_subscriptions($pdo);

    // Personal reminders are separate from the farm alerts above: if they
    // fail (say the notes table hasn't been created yet) the rest still counts.
    $remindersSent = 0;
    $digestsSent = 0;
    $reminderNote = '';
    try {
        $remindersSent = hef_send_due_reminders($pdo);
    } catch (Throwable $e) {
        error_log('HEFarm reminders failed: ' . $e->getMessage());
        $reminderNote = ' (reminders failed - see the server error log)';
    }
    // The daily summary comes after reminders, so it knows which were just emailed.
    try {
        $digestsSent = hef_send_daily_digests($pdo);
    } catch (Throwable $e) {
        error_log('HEFarm daily summaries failed: ' . $e->getMessage());
        $reminderNote .= ' (daily summaries failed - see the server error log)';
    }

    $after = (int) $pdo->query('SELECT COUNT(*) FROM alerts_log')->fetchColumn();

    printf(
        "OK - %d new alert(s), %d reminder(s), %d daily summary email(s) sent in %.1fs (%s)%s\n",
        $after - $before,
        $remindersSent,
        $digestsSent,
        microtime(true) - $started,
        date('Y-m-d H:i:s'),
        $reminderNote
    );
} catch (Throwable $e) {
    error_log('HEFarm cron failed: ' . $e->getMessage());
    http_response_code(500);
    echo "Failed - see the server error log.\n";
} finally {
    $pdo->query("SELECT RELEASE_LOCK('hef_alert_cron')");
}
