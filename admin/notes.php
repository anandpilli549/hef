<?php
require_once __DIR__ . '/bootstrap.php';

// Personal notes and reminders. Every user sees and edits only their own,
// whatever their role. A note with a date is a reminder: the daily job
// (cron/run-alerts.php) emails it to the user on that date.

$companyId = (int) $currentUser['company_id'];
$userId = (int) $currentUser['user_id'];
$error = '';
$openAddModal = false;
$addValues = ['title' => '', 'body' => '', 'remind_on' => '', 'repeat_rule' => 'none', 'link_type' => null, 'link_id' => null, 'is_shared' => 0];
$canSeePro = hef_user_can_access_pro_pages($pdo, $currentUser);

$repeatOptions = [
    'none' => 'Does not repeat',
    'daily' => 'Every day',
    'weekly' => 'Every week',
    'monthly' => 'Every month',
    'yearly' => 'Every year',
];

// Records a note can be linked to. Customers and suppliers are Pro pages, so
// only Pro owners can link to them.
$linkChoices = ['batch' => [], 'customer' => [], 'supplier' => []];
$stmt = $pdo->prepare("SELECT id, batch_code FROM batches WHERE company_id = ? ORDER BY (status = 'active') DESC, date_acquired DESC LIMIT 300");
$stmt->execute([$companyId]);
foreach ($stmt->fetchAll() as $r) {
    $linkChoices['batch'][(int) $r['id']] = $r['batch_code'];
}
if (hef_user_can_access_pro_pages($pdo, $currentUser)) {
    foreach (['customer' => 'customers', 'supplier' => 'suppliers'] as $type => $table) {
        $stmt = $pdo->prepare("SELECT id, name FROM {$table} WHERE company_id = ? ORDER BY name LIMIT 500");
        $stmt->execute([$companyId]);
        foreach ($stmt->fetchAll() as $r) {
            $linkChoices[$type][(int) $r['id']] = $r['name'];
        }
    }
}

// "Today" in the company's time zone — reminders are dates, not times.
$stmt = $pdo->prepare('SELECT timezone FROM companies WHERE id = ?');
$stmt->execute([$companyId]);
$today = hef_company_today($stmt->fetchColumn() ?: null);

/** Reads and validates the add / edit form. */
function hef_read_note_form(array $post, array $repeatKeys, array $linkChoices): array
{
    $cut = function (string $text, int $max): string {
        return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
    };

    $title = $cut(trim((string) ($post['title'] ?? '')), 255);
    $body = $cut(trim((string) ($post['body'] ?? '')), 5000);
    $remindOn = trim((string) ($post['remind_on'] ?? ''));
    $repeat = (string) ($post['repeat_rule'] ?? 'none');
    $error = '';

    if ($title === '') {
        $error = 'Give the note a title.';
    }

    if ($remindOn === '') {
        $remindOn = null;
        $repeat = 'none'; // nothing to repeat without a date
    } else {
        $parsed = DateTime::createFromFormat('Y-m-d', $remindOn);
        if (! $parsed || $parsed->format('Y-m-d') !== $remindOn) {
            $error = $error ?: 'Enter a valid reminder date.';
        }
    }
    if (! in_array($repeat, $repeatKeys, true)) {
        $repeat = 'none';
    }

    // "batch:12" -> only kept if that record really is one of this company's.
    $linkType = null;
    $linkId = null;
    if (preg_match('/^(batch|customer|supplier):(\d+)$/', (string) ($post['link'] ?? ''), $m) && isset($linkChoices[$m[1]][(int) $m[2]])) {
        $linkType = $m[1];
        $linkId = (int) $m[2];
    }

    return [
        'title' => $title,
        'body' => $body !== '' ? $body : null,
        'remind_on' => $remindOn,
        'repeat_rule' => $repeat,
        'link_type' => $linkType,
        'link_id' => $linkId,
        'is_shared' => (($post['share'] ?? '') === 'team') ? 1 : 0,
        'error' => $error,
    ];
}

function hef_notes_redirect(): void
{
    header('Location: /hef/admin/notes.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['toggle_note_id'])) {
        $pdo->prepare(
            "UPDATE user_notes SET status = IF(status = 'open', 'done', 'open'), updated_at = NOW()
             WHERE id = ? AND company_id = ? AND (user_id = ? OR is_shared = 1)"
        )->execute([(int) $_POST['toggle_note_id'], $companyId, $userId]);
        hef_notes_redirect();
    } elseif (isset($_POST['delete_note_id'])) {
        $pdo->prepare('DELETE FROM user_notes WHERE id = ? AND user_id = ?')
            ->execute([(int) $_POST['delete_note_id'], $userId]);
        hef_notes_redirect();
    } elseif (isset($_POST['edit_note_id'])) {
        $id = (int) $_POST['edit_note_id'];
        $form = hef_read_note_form($_POST, array_keys($repeatOptions), $linkChoices);
        if ($form['error']) {
            $error = $form['error'];
        } else {
            $stmt = $pdo->prepare('SELECT remind_on FROM user_notes WHERE id = ? AND user_id = ?');
            $stmt->execute([$id, $userId]);
            $old = $stmt->fetch();
            if ($old) {
                // A new date starts a fresh reminder; editing only the text of
                // one that was already emailed must not email it again.
                $dateChanged = ($old['remind_on'] ?? null) !== $form['remind_on'];
                $pdo->prepare(
                    'UPDATE user_notes
                     SET title = ?, body = ?, link_type = ?, link_id = ?, is_shared = ?, remind_on = ?, repeat_rule = ?,
                         last_reminded_on = IF(?, NULL, last_reminded_on), updated_at = NOW()
                     WHERE id = ? AND user_id = ?'
                )->execute([
                    $form['title'], $form['body'], $form['link_type'], $form['link_id'], $form['is_shared'],
                    $form['remind_on'], $form['repeat_rule'],
                    $dateChanged ? 1 : 0, $id, $userId,
                ]);
            }
            hef_notes_redirect();
        }
    } elseif (isset($_POST['add_note'])) {
        $form = hef_read_note_form($_POST, array_keys($repeatOptions), $linkChoices);
        if ($form['error']) {
            $error = $form['error'];
            $openAddModal = true;
            $addValues = [
                'title' => (string) ($_POST['title'] ?? ''),
                'body' => (string) ($_POST['body'] ?? ''),
                'remind_on' => (string) ($_POST['remind_on'] ?? ''),
                'repeat_rule' => (string) ($_POST['repeat_rule'] ?? 'none'),
                'link_type' => $form['link_type'],
                'link_id' => $form['link_id'],
                'is_shared' => $form['is_shared'],
            ];
        } else {
            $pdo->prepare(
                "INSERT INTO user_notes (company_id, user_id, title, body, link_type, link_id, is_shared, remind_on, repeat_rule, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', NOW(), NOW())"
            )->execute([
                $companyId, $userId, $form['title'], $form['body'], $form['link_type'], $form['link_id'],
                $form['is_shared'], $form['remind_on'], $form['repeat_rule'],
            ]);
            hef_notes_redirect();
        }
    }
}

// Arriving from a record's "Remind me" button (notes.php?link=batch:12):
// open the add dialog with that record already chosen.
if ($_SERVER['REQUEST_METHOD'] === 'GET'
    && preg_match('/^(batch|customer|supplier):(\d+)$/', (string) ($_GET['link'] ?? ''), $m)
    && isset($linkChoices[$m[1]][(int) $m[2]])) {
    $addValues['link_type'] = $m[1];
    $addValues['link_id'] = (int) $m[2];
    $openAddModal = true;
}

// This user's own items plus notes shared with the team, split into reminders (dated), plain notes and done.
$link = hef_note_link_sql();
$stmt = $pdo->prepare(
    "SELECT n.*, au.name AS author_name, {$link['select']}
     FROM user_notes n
     JOIN users au ON au.id = n.user_id
     {$link['joins']}
     WHERE n.company_id = ? AND (n.user_id = ? OR n.is_shared = 1)
     ORDER BY n.remind_on IS NULL, n.remind_on, n.id"
);
$stmt->execute([$companyId, $userId]);
$reminders = [];
$notes = [];
$done = [];
foreach ($stmt->fetchAll() as $row) {
    if ($row['status'] === 'done') {
        $done[] = $row;
    } elseif ($row['remind_on'] !== null) {
        $reminders[] = $row;
    } else {
        $notes[] = $row;
    }
}
$newestFirst = function ($a, $b) {
    return strcmp((string) $b['updated_at'], (string) $a['updated_at']) ?: ((int) $b['id'] <=> (int) $a['id']);
};
usort($notes, $newestFirst);
usort($done, $newestFirst);
$done = array_slice($done, 0, 30);

/** [label, badge classes] describing how close a reminder date is. */
function hef_reminder_when(string $date, string $today): array
{
    $diff = (int) round((strtotime($date) - strtotime($today)) / 86400);
    if ($diff < 0) {
        $late = abs($diff);
        return ['Overdue by ' . $late . ($late === 1 ? ' day' : ' days'), 'bg-danger'];
    }
    if ($diff === 0) {
        return ['Today', 'bg-warning text-dark'];
    }
    if ($diff === 1) {
        return ['Tomorrow', 'bg-info text-dark'];
    }

    return ['In ' . $diff . ' days', 'bg-light text-dark border'];
}

/** The four form fields, shared by the add and edit dialogs. */
function hef_note_form_fields(array $v, string $id, array $repeatOptions, array $linkChoices): void
{
    $currentLink = ! empty($v['link_type']) ? $v['link_type'] . ':' . (int) $v['link_id'] : '';
    ?>
    <div class="mb-2">
        <label class="form-label small" for="<?= $id ?>Title">Title</label>
        <input type="text" name="title" id="<?= $id ?>Title" class="form-control" maxlength="255" required
            value="<?= htmlspecialchars((string) $v['title']) ?>" placeholder="e.g. Call the feed supplier">
    </div>
    <div class="mb-2">
        <label class="form-label small" for="<?= $id ?>Body">Note</label>
        <textarea name="body" id="<?= $id ?>Body" rows="4" class="form-control" maxlength="5000"
            placeholder="Details (optional)"><?= htmlspecialchars((string) $v['body']) ?></textarea>
    </div>
    <div class="row g-2 mb-2">
        <div class="col-12 col-sm-6">
            <label class="form-label small" for="<?= $id ?>Date">Remind me on</label>
            <input type="date" name="remind_on" id="<?= $id ?>Date" class="form-control" value="<?= htmlspecialchars((string) $v['remind_on']) ?>">
            <div class="form-text">Leave empty for a plain note.</div>
        </div>
        <div class="col-12 col-sm-6">
            <label class="form-label small" for="<?= $id ?>Repeat">Repeat</label>
            <select name="repeat_rule" id="<?= $id ?>Repeat" class="form-select">
                <?php foreach ($repeatOptions as $key => $label): ?>
                    <option value="<?= $key ?>" <?= (string) $v['repeat_rule'] === $key ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="mb-2">
        <label class="form-label small" for="<?= $id ?>Share">Who can see it</label>
        <select name="share" id="<?= $id ?>Share" class="form-select">
            <option value="">Only me</option>
            <option value="team" <?= ! empty($v['is_shared']) ? 'selected' : '' ?>>Everyone on my team</option>
        </select>
        <div class="form-text">Teammates can read it and mark it done. A shared reminder is emailed to the whole team.</div>
    </div>
    <?php if ($linkChoices['batch'] || $linkChoices['customer'] || $linkChoices['supplier']): ?>
    <div class="mb-2">
        <label class="form-label small" for="<?= $id ?>Link">Related to <span class="text-muted">(optional)</span></label>
        <select name="link" id="<?= $id ?>Link" class="form-select">
            <option value="">Nothing</option>
            <?php foreach (['batch' => 'Batches', 'customer' => 'Customers', 'supplier' => 'Suppliers'] as $type => $groupLabel): ?>
                <?php if (! empty($linkChoices[$type])): ?>
                    <optgroup label="<?= $groupLabel ?>">
                        <?php foreach ($linkChoices[$type] as $recordId => $recordLabel): ?>
                            <option value="<?= $type . ':' . $recordId ?>" <?= $currentLink === $type . ':' . $recordId ? 'selected' : '' ?>><?= htmlspecialchars($recordLabel) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <?php
}

/** One row in a list: title, note text, badges and the action buttons. */
function hef_note_item(array $n, string $today, array $repeatOptions, int $viewerId, bool $canSeePro): void
{
    $isDone = $n['status'] === 'done';
    $isMine = (int) $n['user_id'] === $viewerId;

    // Each note gets its own light colour, picked from its id so it stays the
    // same every time the page loads. Completed notes are a neutral grey.
    $palette = [
        ['#fff8e1', '#ffecb3'], // yellow
        ['#e8f5e9', '#c8e6c9'], // green
        ['#e3f2fd', '#bbdefb'], // blue
        ['#fce4ec', '#f8bbd0'], // pink
        ['#f3e5f5', '#e1bee7'], // purple
        ['#e0f7fa', '#b2ebf2'], // cyan
        ['#fff3e0', '#ffe0b2'], // orange
        ['#f1f8e9', '#dcedc8'], // lime
    ];
    [$cardBg, $cardBorder] = $isDone ? ['#f5f5f5', '#e2e2e2'] : $palette[(int) $n['id'] % count($palette)];
    ?>
    <div class="card h-100 shadow-sm" style="background: <?= $cardBg ?>; border: 1px solid <?= $cardBorder ?>;">
        <div class="card-body pb-2" style="min-width:0;">
                <div class="fw-semibold<?= $isDone ? ' text-decoration-line-through text-muted' : '' ?>" style="overflow-wrap:anywhere;"><?= htmlspecialchars($n['title']) ?></div>
                <?php if (! empty($n['body'])): ?>
                    <div class="text-muted small" style="white-space:pre-wrap; overflow-wrap:anywhere;"><?= htmlspecialchars($n['body']) ?></div>
                <?php endif; ?>
                <?php $linked = hef_note_link_info($n, $canSeePro); ?>
                <?php if ($linked): ?>
                    <div class="mt-1"><a href="<?= htmlspecialchars($linked['url']) ?>" class="badge bg-light text-dark border text-decoration-none"><i class="bi <?= $linked['icon'] ?> me-1"></i><?= htmlspecialchars($linked['label']) ?></a></div>
                <?php endif; ?>
                <?php if ((int) $n['is_shared'] === 1): ?>
                    <div class="mt-1 small">
                        <span class="badge bg-info-subtle text-info"><i class="bi bi-people me-1"></i>Team</span>
                        <?php if (! $isMine): ?><span class="text-muted">by <?= htmlspecialchars($n['author_name']) ?></span><?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ($n['remind_on'] !== null): ?>
                    <div class="mt-1 d-flex flex-wrap gap-1 align-items-center small">
                        <?php if (! $isDone): ?>
                            <?php [$whenLabel, $whenClass] = hef_reminder_when($n['remind_on'], $today); ?>
                            <span class="badge <?= $whenClass ?>"><?= htmlspecialchars($whenLabel) ?></span>
                        <?php endif; ?>
                        <span class="text-muted"><i class="bi bi-calendar-event me-1"></i><?= date('d M Y', strtotime($n['remind_on'])) ?></span>
                        <?php if ($n['repeat_rule'] !== 'none'): ?>
                            <span class="badge bg-light text-dark border"><i class="bi bi-arrow-repeat me-1"></i><?= htmlspecialchars($repeatOptions[$n['repeat_rule']] ?? $n['repeat_rule']) ?></span>
                        <?php endif; ?>
                        <?php if (! $isDone && $n['last_reminded_on'] !== null && $n['last_reminded_on'] >= $n['remind_on']): ?>
                            <span class="text-muted"><i class="bi bi-envelope-check me-1"></i>Email sent</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
        </div>
        <div class="d-flex justify-content-end gap-1 px-3 pb-3">
                <form method="POST">
                    <input type="hidden" name="toggle_note_id" value="<?= (int) $n['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-success" title="<?= $isDone ? 'Reopen' : 'Mark done' ?>">
                        <i class="bi <?= $isDone ? 'bi-arrow-counterclockwise' : 'bi-check-lg' ?>"></i>
                    </button>
                </form>
                <?php if ($isMine): ?>
                    <?php if (! $isDone): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary" title="Edit" data-bs-toggle="modal" data-bs-target="#editNote<?= (int) $n['id'] ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                    <?php endif; ?>
                    <form method="POST" onsubmit="return confirm('Delete this?');">
                        <input type="hidden" name="delete_note_id" value="<?= (int) $n['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                    </form>
                <?php endif; ?>
        </div>
    </div>
    <?php
}

require_once __DIR__ . '/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-1">
    <h4 class="mb-0"><i class="bi bi-journal-check me-2"></i>Notes &amp; Reminders</h4>
    <button type="button" class="btn btn-hef text-white" data-bs-toggle="modal" data-bs-target="#addNoteModal">
        <i class="bi bi-plus-lg me-1"></i>Add
    </button>
</div>
<p class="text-muted small mb-3">Notes are private to you unless you share them with your team. Give a note a date to make it a reminder — the email goes out that morning.</p>

<?php if ($error && ! $openAddModal): ?>
    <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if (! $reminders && ! $notes && ! $done): ?>
    <div class="card p-4 text-muted">Nothing here yet. Use <strong>Add</strong> to save a note or set a reminder.</div>
<?php endif; ?>

<?php if ($reminders): ?>
<h6 class="mb-2"><i class="bi bi-alarm me-1"></i>Reminders <span class="text-muted fw-normal">(<?= count($reminders) ?>)</span></h6>
<div class="row g-3 mb-4">
    <?php foreach ($reminders as $n): ?>
        <div class="col-12 col-md-6 col-xl-4"><?php hef_note_item($n, $today, $repeatOptions, $userId, $canSeePro); ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($notes): ?>
<h6 class="mb-2"><i class="bi bi-sticky me-1"></i>Notes <span class="text-muted fw-normal">(<?= count($notes) ?>)</span></h6>
<div class="row g-3 mb-4">
    <?php foreach ($notes as $n): ?>
        <div class="col-12 col-md-6 col-xl-4"><?php hef_note_item($n, $today, $repeatOptions, $userId, $canSeePro); ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($done): ?>
<details class="mb-4">
    <summary class="mb-2 text-muted" style="cursor:pointer;"><i class="bi bi-check2-all me-1"></i>Completed (<?= count($done) ?>)</summary>
    <div class="row g-3">
        <?php foreach ($done as $n): ?>
            <div class="col-12 col-md-6 col-xl-4"><?php hef_note_item($n, $today, $repeatOptions, $userId, $canSeePro); ?></div>
        <?php endforeach; ?>
    </div>
</details>
<?php endif; ?>

<!-- Add dialog -->
<div class="modal fade" id="addNoteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="add_note" value="1">
                <div class="modal-header">
                    <h5 class="modal-title">Add note or reminder</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if ($error && $openAddModal): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                    <?php hef_note_form_fields($addValues, 'addNote', $repeatOptions, $linkChoices); ?>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-hef text-white w-100">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit dialogs (open items only) -->
<?php foreach (array_merge($reminders, $notes) as $n): ?>
    <?php if ((int) $n['user_id'] !== $userId) { continue; } // teammates' shared notes can't be edited here ?>
    <div class="modal fade" id="editNote<?= (int) $n['id'] ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="edit_note_id" value="<?= (int) $n['id'] ?>">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <?php hef_note_form_fields($n, 'editNote' . (int) $n['id'], $repeatOptions, $linkChoices); ?>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-hef text-white w-100">Save changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php if ($openAddModal): ?>
<script>
// A save failed: reopen the add dialog so the message and typed text are visible.
window.addEventListener('load', function () {
    var el = document.getElementById('addNoteModal');
    if (el) { new bootstrap.Modal(el).show(); }
});
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
