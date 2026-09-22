<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/backup.php';
hef_require_role(['owner'], $currentUser['role']);

// The Owner's backup of their own company: an encrypted file to keep somewhere
// safe, and a restore from such a file. See includes/backup.php for what is in it.

$companyId = (int) $currentUser['company_id'];
$userId = (int) $currentUser['user_id'];
$stmt = $pdo->prepare('SELECT name, slug FROM companies WHERE id = ?');
$stmt->execute([$companyId]);
$company = $stmt->fetch();

$unavailable = hef_backup_requirements();
$error = '';
$minPassphrase = 8;

// A file bigger than the server's post_max_size arrives as an empty request.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $error = 'That file is bigger than this server accepts (' . ini_get('post_max_size') . '). Try a smaller backup, or ask your host to raise the upload limit.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ! $unavailable) {
    // ---- Download a backup ----
    if (isset($_POST['download_backup'])) {
        $pass = (string) ($_POST['passphrase'] ?? '');
        if (strlen($pass) < $minPassphrase) {
            $error = 'Choose a passphrase of at least ' . $minPassphrase . ' characters.';
        } elseif ($pass !== (string) ($_POST['passphrase2'] ?? '')) {
            $error = 'The two passphrases don\'t match.';
        } else {
            try {
                $result = hef_backup_create($pdo, 'company', $companyId, $pass, ['company' => $company['name'], 'by' => $currentUser['name'] ?? '']);
                hef_backup_log($pdo, $companyId, $userId, 'backup', 'company', $result['records'] . ' records in ' . $result['tables'] . ' tables');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . hef_backup_filename('company-' . $company['slug']) . '"');
                header('Content-Length: ' . strlen($result['blob']));
                header('Cache-Control: no-store');
                echo $result['blob'];
                exit;
            } catch (Throwable $e) {
                error_log('backup failed: ' . $e->getMessage());
                $error = 'The backup could not be created. Please try again, or contact support if it keeps happening.';
            }
        }
    }

    // ---- Restore from a backup ----
    if (isset($_POST['restore_backup'])) {
        $pass = (string) ($_POST['passphrase'] ?? '');
        $file = $_FILES['backup_file'] ?? null;
        $flash = function (string $type, string $msg) {
            $_SESSION['backup_flash'] = [$type, $msg];
            header('Location: /hef/admin/backup.php');
            exit;
        };

        if (trim((string) ($_POST['confirm_name'] ?? '')) !== $company['name'] || empty($_POST['understand'])) {
            $error = 'To restore, tick the box and type your company name exactly as shown.';
        } elseif (! $file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            $error = 'Choose the backup file to restore from.';
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $error = $file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
                ? 'The file is bigger than this server accepts (' . ini_get('upload_max_filesize') . ').'
                : 'The file could not be uploaded. Please try again.';
        } elseif ($pass === '') {
            $error = 'Enter the passphrase you used when you made the backup.';
        } else {
            $blob = file_get_contents($file['tmp_name']);
            $opened = hef_backup_open($blob === false ? '' : $blob, $pass);
            if (isset($opened['error'])) {
                $error = $opened['error'];
            } else {
                $result = hef_backup_restore($pdo, $opened['payload'], 'company', $companyId);
                if ($result['ok']) {
                    hef_backup_log($pdo, $companyId, $userId, 'restore', 'company', $result['message']);
                    error_log("HEFarm: company #{$companyId} restored from a backup by user #{$userId}");
                    $flash('ok', $result['message']);
                }
                $error = $result['message'];
            }
        }
    }
}

[$flashType, $flashMsg] = $_SESSION['backup_flash'] ?? [null, null];
unset($_SESSION['backup_flash']);

$recent = hef_backup_recent($pdo, $companyId, 8);
$lastBackup = null;
foreach ($recent as $r) {
    if ($r['action'] === 'backup') {
        $lastBackup = $r['created_at'];
        break;
    }
}

require_once __DIR__ . '/header.php';
?>
<h4 class="mb-1"><i class="bi bi-cloud-arrow-down me-2"></i>Backup &amp; restore</h4>
<p class="text-muted small mb-3">
    <?php if ($lastBackup): ?>Last backup downloaded <strong><?= date('d M Y, h:i A', strtotime($lastBackup)) ?></strong>.<?php else: ?><strong class="text-danger">You haven't downloaded a backup yet.</strong><?php endif; ?>
    Keep backup files somewhere safe that isn't this server, such as your own computer or a cloud drive.
</p>

<?php if ($unavailable): ?><div class="alert alert-danger"><?= htmlspecialchars($unavailable) ?></div><?php endif; ?>
<?php if ($flashMsg): ?><div class="alert alert-<?= $flashType === 'ok' ? 'success' : 'danger' ?>"><i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($flashMsg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-xl-6">
        <div class="card p-4 h-100">
            <h6 class="mb-3"><i class="bi bi-download me-1"></i>Download an encrypted backup</h6>
            <form method="POST">
                <input type="hidden" name="download_backup" value="1">
                <div class="row g-2 mb-2">
                    <div class="col-12 col-sm-6"><label class="form-label small">Passphrase</label><input type="password" name="passphrase" class="form-control" minlength="<?= $minPassphrase ?>" required autocomplete="new-password"></div>
                    <div class="col-12 col-sm-6"><label class="form-label small">Repeat it</label><input type="password" name="passphrase2" class="form-control" minlength="<?= $minPassphrase ?>" required autocomplete="new-password"></div>
                </div>
                <div class="alert alert-warning py-2 small">
                    <i class="bi bi-key me-1"></i><strong>Write the passphrase down.</strong> It is not stored anywhere, so if you forget it, nobody, including us, can open the backup.
                </div>
                <button type="submit" class="btn btn-hef text-white" <?= $unavailable ? 'disabled' : '' ?>><i class="bi bi-download me-1"></i>Download backup</button>
            </form>
            <hr>
            <div class="small">
                <div class="fw-semibold mb-1">What's in it</div>
                <p class="text-muted mb-2">Your species and breeds, rooms, batches and everything recorded on them (mortality, feed, health, vaccinations, weights, eggs), finances, customers and suppliers with their requirements and rates, store listings and bookings, refund policy, staff with attendance, advances and payroll, and your notes and reminders.</p>
                <div class="fw-semibold mb-1">What isn't</div>
                <p class="text-muted mb-0">Login accounts and team members, your subscription and billing, payment / SMS / WhatsApp credentials, activity logs, and uploaded photos (photos stay on the server, and a restore keeps their links).</p>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="card p-4 h-100 border-danger-subtle">
            <h6 class="mb-3"><i class="bi bi-upload me-1"></i>Restore from a backup</h6>
            <div class="alert alert-danger py-2 small mb-3">
                <i class="bi bi-exclamation-triangle me-1"></i><strong>This replaces all of your company's current data</strong> with what is in the file. Anything added since the backup is lost. Download a fresh backup first if you might need today's data. If the restore fails, nothing is changed.
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="restore_backup" value="1">
                <div class="mb-2"><label class="form-label small">Backup file (.hefbak)</label><input type="file" name="backup_file" class="form-control" accept=".hefbak" required></div>
                <div class="mb-2"><label class="form-label small">Its passphrase</label><input type="password" name="passphrase" class="form-control" required autocomplete="off"></div>
                <div class="mb-2">
                    <label class="form-label small">Type <strong><?= htmlspecialchars($company['name']) ?></strong> to confirm</label>
                    <input type="text" name="confirm_name" class="form-control" required autocomplete="off">
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="understand" id="understand" value="1">
                    <label class="form-check-label small" for="understand">I understand this replaces my current data.</label>
                </div>
                <button type="submit" class="btn btn-danger" <?= $unavailable ? 'disabled' : '' ?>><i class="bi bi-arrow-counterclockwise me-1"></i>Restore</button>
                <div class="form-text mt-2">A backup can only be restored into the company it came from. Largest file this server accepts: <?= htmlspecialchars((string) ini_get('upload_max_filesize')) ?>.</div>
            </form>
        </div>
    </div>
</div>

<?php if ($recent): ?>
<h6 class="mt-4 mb-2">Recent activity</h6>
<div class="card">
    <ul class="list-group list-group-flush small">
        <?php foreach ($recent as $r): ?>
            <li class="list-group-item d-flex justify-content-between gap-2">
                <span><span class="badge <?= $r['action'] === 'backup' ? 'bg-success' : 'bg-warning text-dark' ?> me-1"><?= htmlspecialchars(ucfirst($r['action'])) ?></span><?= htmlspecialchars((string) $r['details']) ?><?= $r['user_name'] ? ' · ' . htmlspecialchars($r['user_name']) : '' ?></span>
                <span class="text-muted text-nowrap"><?= date('d M Y, h:i A', strtotime($r['created_at'])) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
