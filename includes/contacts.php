<?php
/**
 * Shared pieces for the Customers and Suppliers pages (see contacts_page.php):
 * company-time helpers, phone numbers in one format, call notes, closing and
 * reopening a contact, and the card and dialogs each contact is shown with.
 */

// =================================================================
// Contact types
// =================================================================

/** Everything that differs between customers and suppliers. */
function hef_contact_meta(string $type): array
{
    if ($type === 'customer') {
        return [
            'type' => 'customer', 'table' => 'customers', 'lines_table' => 'customer_species', 'fk' => 'customer_id',
            'label' => 'Customer', 'plural' => 'Customers', 'icon' => 'bi-person-badge', 'page' => 'customers.php',
            'phone_cols' => ['phone', 'phone2'], 'last_col' => 'last_customer_update_at',
            'closed_status' => 'closed', 'closed_label' => 'Closed', 'updated_by' => 'customer',
            'lines_title' => 'Needs', 'export' => 'customers',
            'reasons' => [
                'booked_with_us' => 'Booked from us',
                'no_longer_needed' => 'No longer needs animals',
                'bought_elsewhere' => 'Bought elsewhere',
                'not_reachable' => 'Not reachable',
                'other' => 'Other',
            ],
        ];
    }

    return [
        'type' => 'supplier', 'table' => 'suppliers', 'lines_table' => 'supplier_species', 'fk' => 'supplier_id',
        'label' => 'Supplier', 'plural' => 'Suppliers', 'icon' => 'bi-truck', 'page' => 'suppliers.php',
        'phone_cols' => ['phone', 'phone2', 'phone3', 'phone4'], 'last_col' => 'last_supplier_update_at',
        'closed_status' => 'inactive', 'closed_label' => 'Not supplying', 'updated_by' => 'supplier',
        'lines_title' => 'Supplies', 'export' => 'suppliers',
        'reasons' => [
            'no_longer_supplying' => 'No longer supplying',
            'out_of_stock' => 'Out of stock for now',
            'price_quality' => 'Price or quality issues',
            'not_reachable' => 'Not reachable',
            'other' => 'Other',
        ],
    ];
}

// =================================================================
// Company time
// =================================================================

/**
 * The time zone the database's own clock (NOW(), timestamp columns) runs in.
 * Set HEF_DB_TIMEZONE in config.php to force one; otherwise it is worked out
 * from the database's current UTC offset (keeping PHP's own zone, and so its
 * daylight-saving rules, when that matches).
 */
function hef_db_timezone(PDO $pdo): DateTimeZone
{
    static $tz = null;
    if ($tz !== null) {
        return $tz;
    }
    if (defined('HEF_DB_TIMEZONE') && HEF_DB_TIMEZONE) {
        try {
            return $tz = new DateTimeZone(HEF_DB_TIMEZONE);
        } catch (Exception $e) {
            // fall through and work it out
        }
    }

    $row = $pdo->query('SELECT NOW() AS n, UTC_TIMESTAMP() AS u')->fetch();
    $offset = strtotime($row['n'] . ' UTC') - strtotime($row['u'] . ' UTC');

    $php = new DateTimeZone(date_default_timezone_get());
    if ($php->getOffset(new DateTimeImmutable('now', new DateTimeZone('UTC'))) === $offset) {
        return $tz = $php;
    }
    $sign = $offset < 0 ? '-' : '+';
    $abs = abs($offset);

    return $tz = new DateTimeZone(sprintf('%s%02d:%02d', $sign, intdiv($abs, 3600), intdiv($abs % 3600, 60)));
}

function hef_company_tz(PDO $pdo, int $companyId): string
{
    $stmt = $pdo->prepare('SELECT timezone FROM companies WHERE id = ?');
    $stmt->execute([$companyId]);
    $tz = (string) $stmt->fetchColumn();

    return in_array($tz, DateTimeZone::listIdentifiers(), true) ? $tz : 'Asia/Kolkata';
}

/** A database timestamp (server time) shown in the company's own time zone. */
function hef_company_local(PDO $pdo, ?string $dbTimestamp, string $companyTz, string $format = 'd M Y, h:i A'): string
{
    if (! $dbTimestamp) {
        return '';
    }
    try {
        return (new DateTimeImmutable($dbTimestamp, hef_db_timezone($pdo)))
            ->setTimezone(new DateTimeZone($companyTz))
            ->format($format);
    } catch (Exception $e) {
        return date($format, strtotime($dbTimestamp));
    }
}

/** "Now" as 'Y-m-d H:i:s' on the company's own clock. */
function hef_company_now(string $companyTz): string
{
    return (new DateTimeImmutable('now', new DateTimeZone($companyTz)))->format('Y-m-d H:i:s');
}

// =================================================================
// Phone numbers: one format for every entry
// =================================================================

/**
 * Turns a typed number into the one stored format, "+91XXXXXXXXXX" for
 * Indian numbers (or "+<country><number>" for others). Spaces, dashes,
 * brackets, a leading 0, "91" or "+91" are all accepted.
 *
 * @return string|null '' when nothing was typed, null when it isn't a valid number
 */
function hef_normalize_phone(string $input): ?string
{
    $raw = trim($input);
    if ($raw === '') {
        return '';
    }
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === '') {
        return null;
    }
    $international = $raw[0] === '+';
    if (strpos($digits, '00') === 0 && ! $international) {
        $international = true; // 0091 98765 43210
        $digits = substr($digits, 2);
    }

    if ($international) {
        if (strpos($digits, '91') === 0) {
            $national = substr($digits, 2);

            return preg_match('/^[1-9][0-9]{9}$/', $national) ? '+91' . $national : null;
        }

        return (strlen($digits) >= 8 && strlen($digits) <= 15 && $digits[0] !== '0') ? '+' . $digits : null;
    }
    if (preg_match('/^[1-9][0-9]{9}$/', $digits)) {
        return '+91' . $digits;
    }
    if (preg_match('/^0([1-9][0-9]{9})$/', $digits, $m) || preg_match('/^91([1-9][0-9]{9})$/', $digits, $m)) {
        return '+91' . $m[1];
    }

    return null;
}

/** A stored number for display: "+91 98765 43210". */
function hef_format_phone(?string $stored): string
{
    $stored = trim((string) $stored);
    if ($stored === '') {
        return '';
    }
    $n = hef_normalize_phone($stored);
    if ($n === null || $n === '') {
        return $stored;
    }

    return strpos($n, '+91') === 0 ? '+91 ' . substr($n, 3, 5) . ' ' . substr($n, 8) : $n;
}

/** The phone numbers on a customer / supplier row, in order, blanks left out. */
function hef_contact_numbers(array $row, array $meta): array
{
    $numbers = [];
    foreach ($meta['phone_cols'] as $col) {
        if (! empty($row[$col])) {
            $numbers[] = $row[$col];
        }
    }

    return $numbers;
}

/**
 * Reads the phone fields of a posted form. Returns [values by column, error].
 * Every number is saved in the one standard format.
 */
function hef_read_phones(array $post, array $meta): array
{
    $values = [];
    foreach ($meta['phone_cols'] as $i => $col) {
        $n = hef_normalize_phone((string) ($post[$col] ?? ''));
        if ($n === null) {
            return [[], 'Phone number ' . ($i + 1) . ' isn\'t valid. Enter it like 98765 43210 or +91 98765 43210.'];
        }
        $values[$col] = $n !== '' ? $n : null;
    }
    // Keep the numbers packed from the top (no gaps), and drop repeats.
    $packed = array_values(array_unique(array_filter($values)));
    foreach ($meta['phone_cols'] as $i => $col) {
        $values[$col] = $packed[$i] ?? null;
    }

    return [$values, ''];
}

/** One phone input with its label. */
function hef_phone_input(string $name, ?string $stored, string $label, string $id): string
{
    return '<label class="form-label small" for="' . htmlspecialchars($id) . '">' . htmlspecialchars($label) . '</label>'
        . '<input type="text" inputmode="tel" autocomplete="off" class="form-control" id="' . htmlspecialchars($id) . '" name="' . htmlspecialchars($name) . '"'
        . ' value="' . htmlspecialchars(hef_format_phone($stored)) . '" placeholder="98765 43210">';
}

/**
 * Call / SMS / WhatsApp buttons for all of a contact's numbers, for compact
 * lists: the buttons themselves for one number, or a small menu for several.
 */
function hef_contact_group(array $numbers, string $message = ''): string
{
    $numbers = array_values(array_filter($numbers));
    if (! $numbers) {
        return '';
    }
    if (count($numbers) === 1) {
        return hef_contact_buttons($numbers[0], $message);
    }

    $html = '<div class="dropdown d-inline-block"><button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" onclick="event.stopPropagation();" title="Contact">'
        . '<i class="bi bi-telephone-fill"></i> ' . count($numbers) . '</button><ul class="dropdown-menu dropdown-menu-end p-2" style="min-width:16rem;">';
    foreach ($numbers as $n) {
        $html .= '<li class="d-flex align-items-center justify-content-between gap-2 py-1"><span class="small text-nowrap">'
            . htmlspecialchars(hef_format_phone($n)) . '</span><span class="text-nowrap">' . hef_contact_buttons($n, $message) . '</span></li>';
    }

    return $html . '</ul></div>';
}

// =================================================================
// Call notes
// =================================================================

/** Adds a note (used by the note form and by automatic notes). */
function hef_contact_add_note(PDO $pdo, int $companyId, string $type, int $contactId, string $text, string $notedAt, ?int $userId): void
{
    $pdo->prepare(
        'INSERT INTO contact_notes (company_id, contact_type, contact_id, note, noted_at, created_by_user_id, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
    )->execute([$companyId, $type, $contactId, mb_substr($text, 0, 2000), $notedAt, $userId]);
}

/** 'Y-m-d H:i:00' from a datetime-local value, or null if it isn't one. */
function hef_parse_local_datetime(string $value): ?string
{
    $d = DateTime::createFromFormat('Y-m-d\TH:i', trim($value));

    return ($d && $d->format('Y-m-d\TH:i') === trim($value)) ? $d->format('Y-m-d H:i:00') : null;
}

/**
 * Handles the add / edit / delete note forms. Times are on the company's own
 * clock: a new note is stamped "now" unless a time is given.
 *
 * @return array{status: string, error: string, open: int} open = the contact whose notes to show again
 */
function hef_contact_notes_handle(PDO $pdo, int $companyId, ?int $userId, string $type, string $tz): array
{
    $out = ['status' => '', 'error' => '', 'open' => 0];
    $meta = hef_contact_meta($type);

    if (isset($_POST['add_contact_note'])) {
        $contactId = (int) $_POST['add_contact_note'];
        $text = trim((string) ($_POST['note_text'] ?? ''));
        $stmt = $pdo->prepare('SELECT id FROM ' . $meta['table'] . ' WHERE id = ? AND company_id = ?');
        $stmt->execute([$contactId, $companyId]);
        if (! $stmt->fetchColumn()) {
            $out['error'] = $meta['label'] . ' not found.';
        } elseif ($text === '') {
            $out['error'] = 'Type the note first.';
            $out['open'] = $contactId;
        } else {
            $when = hef_parse_local_datetime((string) ($_POST['noted_at'] ?? '')) ?: hef_company_now($tz);
            hef_contact_add_note($pdo, $companyId, $type, $contactId, $text, $when, $userId);
            $out['status'] = 'Note saved.';
            $out['open'] = $contactId;
        }
    } elseif (isset($_POST['edit_contact_note'])) {
        $noteId = (int) $_POST['edit_contact_note'];
        $text = trim((string) ($_POST['note_text'] ?? ''));
        $stmt = $pdo->prepare('SELECT contact_id FROM contact_notes WHERE id = ? AND company_id = ? AND contact_type = ?');
        $stmt->execute([$noteId, $companyId, $type]);
        $contactId = (int) $stmt->fetchColumn();
        if (! $contactId) {
            $out['error'] = 'Note not found.';
        } elseif ($text === '') {
            $out['error'] = 'A note can\'t be empty. Use delete to remove it.';
            $out['open'] = $contactId;
        } else {
            $when = hef_parse_local_datetime((string) ($_POST['noted_at'] ?? ''));
            if ($when) {
                $pdo->prepare('UPDATE contact_notes SET note = ?, noted_at = ?, updated_at = NOW() WHERE id = ?')->execute([mb_substr($text, 0, 2000), $when, $noteId]);
            } else {
                $pdo->prepare('UPDATE contact_notes SET note = ?, updated_at = NOW() WHERE id = ?')->execute([mb_substr($text, 0, 2000), $noteId]);
            }
            $out['status'] = 'Note updated.';
            $out['open'] = $contactId;
        }
    } elseif (isset($_POST['delete_contact_note'])) {
        $noteId = (int) $_POST['delete_contact_note'];
        $stmt = $pdo->prepare('SELECT contact_id FROM contact_notes WHERE id = ? AND company_id = ? AND contact_type = ?');
        $stmt->execute([$noteId, $companyId, $type]);
        $contactId = (int) $stmt->fetchColumn();
        if ($contactId) {
            $pdo->prepare('DELETE FROM contact_notes WHERE id = ?')->execute([$noteId]);
            $out['status'] = 'Note deleted.';
            $out['open'] = $contactId;
        }
    }

    return $out;
}

/** Notes for a set of contacts, newest first, keyed by contact id. */
function hef_contact_notes_load(PDO $pdo, int $companyId, string $type, array $ids): array
{
    if (! $ids) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT n.*, u.name AS user_name FROM contact_notes n LEFT JOIN users u ON u.id = n.created_by_user_id
         WHERE n.company_id = ? AND n.contact_type = ? AND n.contact_id IN ({$marks})
         ORDER BY n.noted_at DESC, n.id DESC"
    );
    $stmt->execute(array_merge([$companyId, $type], $ids));
    $out = [];
    foreach ($stmt->fetchAll() as $n) {
        $out[(int) $n['contact_id']][] = $n;
    }

    return $out;
}

// =================================================================
// Closing and reopening a contact
// =================================================================

/**
 * Handles "close" (customer no longer needs / booked from us; supplier no
 * longer supplying) and "reopen". A closed contact stays on file, with its
 * history and notes, but is left out of Requirements and the dashboard's
 * demand, so it stops appearing as something to act on. Each change is also
 * written into the contact's notes.
 *
 * @return array{status: string, error: string}
 */
function hef_contact_status_handle(PDO $pdo, int $companyId, ?int $userId, string $type, string $tz): array
{
    $meta = hef_contact_meta($type);
    $id = (int) $_POST['set_contact_status'];
    $action = (string) ($_POST['status_action'] ?? '');

    $stmt = $pdo->prepare('SELECT id, name FROM ' . $meta['table'] . ' WHERE id = ? AND company_id = ?');
    $stmt->execute([$id, $companyId]);
    $contact = $stmt->fetch();
    if (! $contact) {
        return ['status' => '', 'error' => $meta['label'] . ' not found.'];
    }
    $now = hef_company_now($tz);

    if ($action === 'close') {
        $reason = (string) ($_POST['reason'] ?? '');
        if (! isset($meta['reasons'][$reason])) {
            return ['status' => '', 'error' => 'Choose a reason.'];
        }
        $extra = trim((string) ($_POST['status_note'] ?? ''));
        $pdo->prepare('UPDATE ' . $meta['table'] . ' SET status = ?, status_reason = ?, status_changed_at = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$meta['closed_status'], $reason, $now, $id]);
        hef_contact_add_note($pdo, $companyId, $type, $id, 'Marked as: ' . $meta['reasons'][$reason] . ($extra !== '' ? '. ' . $extra : ''), $now, $userId);

        return ['status' => $contact['name'] . ' marked as "' . $meta['reasons'][$reason] . '". They\'re left out of Requirements until you reopen them.', 'error' => ''];
    }
    if ($action === 'reopen') {
        $pdo->prepare('UPDATE ' . $meta['table'] . " SET status = 'active', status_reason = NULL, status_changed_at = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$now, $id]);
        hef_contact_add_note($pdo, $companyId, $type, $id, 'Reopened.', $now, $userId);

        return ['status' => $contact['name'] . ' is active again.', 'error' => ''];
    }

    return ['status' => '', 'error' => 'Unknown action.'];
}

// =================================================================
// The card and its dialogs
// =================================================================

/** Soft card colours: [background, border]. */
function hef_contact_palette(): array
{
    return [
        ['#fff8e1', '#ffecb3'], ['#e8f5e9', '#c8e6c9'], ['#e3f2fd', '#bbdefb'], ['#fce4ec', '#f8bbd0'],
        ['#f3e5f5', '#e1bee7'], ['#e0f7fa', '#b2ebf2'], ['#fff3e0', '#ffe0b2'], ['#f1f8e9', '#dcedc8'],
    ];
}

/**
 * One customer / supplier as a soft-coloured card: name, numbers with their
 * Call / SMS / WhatsApp buttons, what they need or supply, and the latest
 * note, with every action in one row along the bottom.
 *
 * $o: type, row, tz, lines (each label + detail), notes (newest first),
 *     updated_text, is_owner
 */
function hef_render_contact_card(array $o): void
{
    $meta = hef_contact_meta($o['type']);
    $c = $o['row'];
    $id = (int) $c['id'];
    $Type = ucfirst($o['type']);
    $closed = $c['status'] !== 'active';
    $palette = hef_contact_palette();
    [$bg, $border] = $closed ? ['#f2f2f2', '#dcdcdc'] : $palette[$id % count($palette)];
    $numbers = hef_contact_numbers($c, $meta);
    $notes = $o['notes'];
    $lastNote = $notes[0] ?? null;
    ?>
    <div class="card h-100 shadow-sm" style="background: <?= $bg ?>; border: 1px solid <?= $border ?>;">
        <div class="card-body pb-2">
            <div class="d-flex justify-content-between align-items-start gap-2">
                <div class="fw-semibold fs-6" style="overflow-wrap:anywhere;"><?= htmlspecialchars($c['name']) ?></div>
                <?php if ($closed): ?>
                    <span class="badge bg-secondary flex-shrink-0"><?= htmlspecialchars($meta['reasons'][$c['status_reason']] ?? $meta['closed_label']) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($closed && $c['status_changed_at']): ?>
                <div class="small text-muted"><?= htmlspecialchars($meta['closed_label']) ?> since <?= date('d M Y', strtotime($c['status_changed_at'])) ?></div>
            <?php endif; ?>
            <?php if ($o['updated_text']): ?>
                <div class="small text-success mt-1"><i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($o['updated_text']) ?></div>
            <?php endif; ?>

            <div class="mt-2">
                <?php foreach ($numbers as $n): ?>
                    <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap py-1">
                        <span class="small text-nowrap"><i class="bi bi-telephone me-1 text-muted"></i><?= htmlspecialchars(hef_format_phone($n)) ?></span>
                        <span class="text-nowrap"><?= hef_contact_buttons($n) ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if (! $numbers): ?><div class="small text-muted">No phone number</div><?php endif; ?>
                <?php if ($c['email']): ?><div class="small mt-1" style="overflow-wrap:anywhere;"><i class="bi bi-envelope me-1 text-muted"></i><a href="mailto:<?= htmlspecialchars($c['email']) ?>" class="text-decoration-none"><?= htmlspecialchars($c['email']) ?></a></div><?php endif; ?>
                <?php if ($c['address']): ?><div class="small text-muted mt-1" style="overflow-wrap:anywhere;"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($c['address']) ?></div><?php endif; ?>
            </div>

            <?php if ($o['lines']): ?>
                <div class="mt-2 d-flex flex-wrap gap-1">
                    <?php foreach ($o['lines'] as $ln): ?>
                        <span class="badge bg-white text-dark border fw-normal"><?= htmlspecialchars($ln['label']) ?>: <?= htmlspecialchars($ln['detail']) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($lastNote): ?>
                <div class="small text-muted mt-2" style="overflow-wrap:anywhere;">
                    <i class="bi bi-journal-text me-1"></i><strong><?= date('d M, h:i A', strtotime($lastNote['noted_at'])) ?></strong> — <?= htmlspecialchars(mb_strimwidth($lastNote['note'], 0, 90, '…')) ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="d-flex flex-wrap gap-1 px-3 pb-3">
            <button type="button" class="btn btn-sm btn-outline-secondary" title="Call notes" data-bs-toggle="modal" data-bs-target="#notes<?= $Type . $id ?>">
                <i class="bi bi-journal-text"></i><?php if ($notes): ?> <span class="badge bg-secondary"><?= count($notes) ?></span><?php endif; ?>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" title="Save a follow-up to your calendar" data-bs-toggle="modal" data-bs-target="#cal<?= $Type . $id ?>"><i class="bi bi-calendar-plus"></i></button>
            <?php if ($o['is_owner']): ?>
                <button type="button" class="btn btn-sm <?= $c['update_token'] ? 'btn-outline-success' : 'btn-outline-secondary' ?>" title="Private link for them to update their own list" data-bs-toggle="modal" data-bs-target="#link<?= $Type . $id ?>"><i class="bi bi-link-45deg"></i></button>
                <button type="button" class="btn btn-sm btn-outline-secondary" title="Edit" data-bs-toggle="modal" data-bs-target="#edit<?= $Type . $id ?>"><i class="bi bi-pencil"></i></button>
                <button type="button" class="btn btn-sm <?= $closed ? 'btn-outline-success' : 'btn-outline-warning' ?>" title="<?= $closed ? 'Reopen' : 'Close: ' . ($o['type'] === 'customer' ? 'no longer needs / booked from us' : 'no longer supplying') ?>" data-bs-toggle="modal" data-bs-target="#status<?= $Type . $id ?>">
                    <i class="bi <?= $closed ? 'bi-arrow-counterclockwise' : 'bi-x-circle' ?>"></i>
                </button>
                <form method="POST" class="d-inline" onsubmit="return confirm(<?= htmlspecialchars(json_encode('Delete ' . $c['name'] . ' permanently, with their notes? Use Close instead to keep the history.'), ENT_QUOTES) ?>);">
                    <input type="hidden" name="delete_contact_id" value="<?= $id ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

/** The dialog with a contact's call notes: add, edit and delete. */
function hef_render_notes_modal(array $o): void
{
    $id = (int) $o['row']['id'];
    $Type = ucfirst($o['type']);
    $nowLocal = substr(hef_company_now($o['tz']), 0, 16);
    $nowLocal = str_replace(' ', 'T', $nowLocal);
    ?>
    <div class="modal fade" id="notes<?= $Type . $id ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-journal-text me-1"></i>Notes — <?= htmlspecialchars($o['row']['name']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" class="mb-3">
                        <input type="hidden" name="add_contact_note" value="<?= $id ?>">
                        <textarea name="note_text" class="form-control mb-2" rows="3" maxlength="2000" required placeholder="What did you talk about?"></textarea>
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <input type="datetime-local" name="noted_at" class="form-control form-control-sm" style="width:auto;" value="<?= htmlspecialchars($nowLocal) ?>" title="Date and time (<?= htmlspecialchars($o['tz']) ?>)">
                            <button type="submit" class="btn btn-hef text-white btn-sm">Add note</button>
                        </div>
                        <div class="form-text">Time is in your company's time zone (<?= htmlspecialchars($o['tz']) ?>).</div>
                    </form>

                    <?php if (! $o['notes']): ?><p class="text-muted small mb-0">No notes yet.</p><?php endif; ?>
                    <?php foreach ($o['notes'] as $n): ?>
                        <?php $nid = (int) $n['id']; ?>
                        <div class="border rounded p-2 mb-2 bg-white">
                            <div class="hef-note-view" id="noteView<?= $nid ?>">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div class="small text-muted"><?= date('d M Y, h:i A', strtotime($n['noted_at'])) ?><?= $n['user_name'] ? ' · ' . htmlspecialchars($n['user_name']) : '' ?></div>
                                    <div class="d-flex gap-1 flex-shrink-0">
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" title="Edit" onclick="hefEditNote(<?= $nid ?>, true)"><i class="bi bi-pencil"></i></button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this note?');">
                                            <input type="hidden" name="delete_contact_note" value="<?= $nid ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1" title="Delete"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </div>
                                </div>
                                <div style="white-space:pre-wrap; overflow-wrap:anywhere;"><?= htmlspecialchars($n['note']) ?></div>
                            </div>
                            <form method="POST" class="d-none" id="noteEdit<?= $nid ?>">
                                <input type="hidden" name="edit_contact_note" value="<?= $nid ?>">
                                <textarea name="note_text" class="form-control mb-2" rows="3" maxlength="2000" required><?= htmlspecialchars($n['note']) ?></textarea>
                                <div class="d-flex gap-2 align-items-center flex-wrap">
                                    <input type="datetime-local" name="noted_at" class="form-control form-control-sm" style="width:auto;" value="<?= htmlspecialchars(str_replace(' ', 'T', substr($n['noted_at'], 0, 16))) ?>">
                                    <button type="submit" class="btn btn-hef text-white btn-sm">Save</button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="hefEditNote(<?= $nid ?>, false)">Cancel</button>
                                </div>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

/** The dialog for saving a follow-up with this contact to a calendar (Google Calendar or a .ics file). */
function hef_render_calendar_modal(array $o): void
{
    $id = (int) $o['row']['id'];
    $Type = ucfirst($o['type']);
    $tomorrow = (new DateTimeImmutable('now', new DateTimeZone($o['tz'])))->modify('+1 day')->format('Y-m-d');
    ?>
    <div class="modal fade" id="cal<?= $Type . $id ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" data-type="<?= htmlspecialchars($o['type']) ?>" data-id="<?= $id ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-calendar-plus me-1"></i>Follow-up — <?= htmlspecialchars($o['row']['name']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2"><label class="form-label small">Title</label><input type="text" class="form-control hef-cal-title" maxlength="120" value="Call <?= htmlspecialchars($o['row']['name']) ?>"></div>
                    <div class="row g-2 mb-2">
                        <div class="col-5"><label class="form-label small">Date</label><input type="date" class="form-control hef-cal-date" value="<?= $tomorrow ?>"></div>
                        <div class="col-4"><label class="form-label small">Time</label><input type="time" class="form-control hef-cal-time" value="10:00"></div>
                        <div class="col-3"><label class="form-label small">Length</label>
                            <select class="form-select hef-cal-mins"><option value="15">15 min</option><option value="30" selected>30 min</option><option value="60">1 hour</option></select></div>
                    </div>
                    <div class="form-text mb-3">Time is in your company's time zone (<?= htmlspecialchars($o['tz']) ?>). The event includes their phone numbers, email and address.</div>
                    <div class="d-flex gap-2 flex-wrap">
                        <button type="button" class="btn btn-outline-primary flex-fill" onclick="hefCalendar(this, 'google')"><i class="bi bi-google me-1"></i>Google Calendar</button>
                        <button type="button" class="btn btn-outline-secondary flex-fill" onclick="hefCalendar(this, 'ics')"><i class="bi bi-download me-1"></i>Download (.ics)</button>
                    </div>
                    <div class="form-text mt-2">The .ics file opens in Apple Calendar, Outlook and most other calendars.</div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

/** The dialog for closing (with a reason) or reopening a contact. */
function hef_render_status_modal(array $o): void
{
    $meta = hef_contact_meta($o['type']);
    $c = $o['row'];
    $id = (int) $c['id'];
    $Type = ucfirst($o['type']);
    $closed = $c['status'] !== 'active';
    ?>
    <div class="modal fade" id="status<?= $Type . $id ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="set_contact_status" value="<?= $id ?>">
                    <input type="hidden" name="status_action" value="<?= $closed ? 'reopen' : 'close' ?>">
                    <div class="modal-header">
                        <h5 class="modal-title"><?= $closed ? 'Reopen' : 'Close' ?> — <?= htmlspecialchars($c['name']) ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <?php if ($closed): ?>
                            <p class="mb-0">Marked as <strong><?= htmlspecialchars($meta['reasons'][$c['status_reason']] ?? $meta['closed_label']) ?></strong>. Reopen them so they count in Requirements again?</p>
                        <?php else: ?>
                            <p class="small text-muted">
                                <?= $o['type'] === 'customer'
                                    ? 'Use this when the customer no longer needs animals, booked from you, or bought elsewhere.'
                                    : 'Use this when the supplier stops supplying, or is out of stock for a while.' ?>
                                They stay on file with their history and notes, but are left out of Requirements and the dashboard until you reopen them.
                            </p>
                            <div class="mb-2">
                                <label class="form-label small">Reason</label>
                                <select name="reason" class="form-select" required>
                                    <option value="">Choose…</option>
                                    <?php foreach ($meta['reasons'] as $key => $label): ?><option value="<?= $key ?>"><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-2"><label class="form-label small">Note <span class="text-muted">(optional, saved with the reason)</span></label><input type="text" name="status_note" class="form-control" maxlength="300"></div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn <?= $closed ? 'btn-success' : 'btn-warning' ?> w-100"><?= $closed ? 'Reopen' : 'Close' ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
}

/** Small scripts for the notes and calendar dialogs. Print once per page. */
function hef_contacts_script(): void
{
    ?>
<script>
// Show or hide the edit form of one note.
function hefEditNote(id, editing) {
    document.getElementById('noteView' + id).classList.toggle('d-none', editing);
    document.getElementById('noteEdit' + id).classList.toggle('d-none', !editing);
}
// Open the follow-up as a Google Calendar event or a downloadable .ics file.
function hefCalendar(btn, format) {
    var box = btn.closest('.modal-content');
    var date = box.querySelector('.hef-cal-date').value;
    var time = box.querySelector('.hef-cal-time').value;
    if (!date || !time) { alert('Choose a date and time.'); return; }
    var params = new URLSearchParams({
        type: box.dataset.type, id: box.dataset.id, start: date + 'T' + time,
        mins: box.querySelector('.hef-cal-mins').value, title: box.querySelector('.hef-cal-title').value, format: format
    });
    window.open('/hef/admin/contact-calendar.php?' + params.toString(), '_blank');
}
function hefCopyLink(inputId, btn) {
    var input = document.getElementById(inputId);
    input.select();
    var done = function () { var t = btn.textContent; btn.textContent = 'Copied'; setTimeout(function () { btn.textContent = t; }, 1500); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(input.value).then(done, function () { document.execCommand('copy'); done(); });
    } else { document.execCommand('copy'); done(); }
}
</script>
    <?php
}
