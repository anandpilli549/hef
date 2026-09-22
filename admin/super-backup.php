<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/backup.php';

if (! $currentUser['is_super_admin']) {
    header('Location: /hef/admin/dashboard.php');
    exit;
}

// Super Admin backup and restore: the whole database, or one company at a
// time (including its login accounts and subscription, so a deleted company
// can be brought back). See includes/backup.php.

$userId = (int) $currentUser['user_id'];
$unavailable = hef_backup_requirements();
$error = '';
$minPassphrase = 8;

// A file bigger than the server's post_max_size arrives as an empty request.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $error = 'That file is bigger than this server accepts (' . ini_get('post_max_size') . '). Try a smaller backup, or ask your host to raise the upload limit.';
}

$companies = $pdo->query('SELECT id, name, status FROM companies ORDER BY name')->fetchAll();

$flash = function (string $type, string $msg) {
    $_SESSION['backup_flash'] = [$type, $msg];
    header('Location: /hef/admin/super-backup.php');
    exit;
};

/** Reads and opens the uploaded backup, or returns an error message. */
$openUpload = function (string $pass) {
    $file = $_FILES['backup_file'] ?? null;
    if (! $file || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['error' => 'Choose the backup file to restore from.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['error' => $file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
            ? 'The file is bigger than this server accepts (' . ini_get('upload_max_filesize') . ').'
            : 'The file could not be uploaded. Please try again.'];
    }
    if ($pass === '') {
        return ['error' => 'Enter the passphrase used when the backup was made.'];
    }
    $blob = file_get_contents($file['tmp_name']);

    return hef_backup_open($blob === false ? '' : $blob, $pass);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ! $unavailable) {
    $pass = (string) ($_POST['passphrase'] ?? '');

    // ---- Download: whole database or one company ----
    if (isset($_POST['download_database']) || isset($_POST['download_company'])) {
        $isDb = isset($_POST['download_database']);
        $companyId = $isDb ? null : (int) ($_POST['company_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT id, name, slug FROM companies WHERE id = ?');
        $stmt->execute([$companyId ?? 0]);
        $target = $stmt->fetch();

        if (strlen($pass) < $minPassphrase) {
            $error = 'Choose a passphrase of at least ' . $minPassphrase . ' characters.';
        } elseif ($pass !== (string) ($_POST['passphrase2'] ?? '')) {
            $error = 'The two passphrases don\'t match.';
        } elseif (! $isDb && ! $target) {
            $error = 'Choose a company.';
        } else {
            try {
                $scope = $isDb ? 'database' : 'company_full';
                $result = hef_backup_create($pdo, $scope, $companyId, $pass, ['by' => $currentUser['name'] ?? '']);
                hef_backup_log($pdo, $companyId, $userId, 'backup', $scope, ($isDb ? 'Whole database: ' : $target['name'] . ': ') . $result['records'] . ' records in ' . $result['tables'] . ' tables');
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . hef_backup_filename($isDb ? 'database' : 'company-' . $target['slug']) . '"');
                header('Content-Length: ' . strlen($result['blob']));
                header('Cache-Control: no-store');
                echo $result['blob'];
                exit;
            } catch (Throwable $e) {
                error_log('super backup failed: ' . $e->getMessage());
                $error = 'The backup could not be created. Please try again.';
            }
        }
    }

    // ---- Restore the whole database ----
    if (isset($_POST['restore_database'])) {
        if (trim((string) ($_POST['confirm_text'] ?? '')) !== 'RESTORE EVERYTHING') {
            $error = 'Type RESTORE EVERYTHING exactly to confirm.';
        } else {
            $opened = $openUpload($pass);
            if (isset($opened['error'])) {
                $error = $opened['error'];
            } else {
                $result = hef_backup_restore($pdo, $opened['payload'], 'database');
                if ($result['ok']) {
                    hef_backup_log($pdo, null, $userId, 'restore', 'database', $result['message']);
                    error_log("HEFarm: WHOLE DATABASE restored by super admin #{$userId}");
                    $flash('ok', 'Database restored. ' . $result['message'] . ' Everyone, including you, may need to log in again.');
                }
                $error = $result['message'];
            }
        }
    }

    // ---- Restore one company ----
    if (isset($_POST['restore_company'])) {
        if (trim((string) ($_POST['confirm_text'] ?? '')) !== 'RESTORE') {
            $error = 'Type RESTORE exactly to confirm.';
        } else {
            $opened = $openUpload($pass);
            if (isset($opened['error'])) {
                $error = $opened['error'];
            } else {
                $backupCompany = (int) ($opened['payload']['company']['id'] ?? 0);
                if ($backupCompany > 0 && $backupCompany === (int) HEF_OWNER_COMPANY_ID) {
                    $error = 'That backup is of the platform owner\'s own company. Restore it with the whole-database restore instead.';
                } elseif ($backupCompany > 0 && $backupCompany === (int) $currentUser['company_id']) {
                    $error = 'That backup is of the company you are logged in to, so it can\'t be restored while you are using it. Log in as a different Super Admin, or use the whole-database restore.';
                } else {
                    $result = hef_backup_restore($pdo, $opened['payload'], 'company_full');
                    if ($result['ok']) {
                        $name = (string) ($opened['payload']['company']['name'] ?? ('#' . $backupCompany));
                        hef_backup_log($pdo, $backupCompany, $userId, 'restore', 'company_full', $name . ': ' . $result['message']);
                        error_log("HEFarm: company #{$backupCompany} restored by super admin #{$userId}");
                        $flash('ok', 'Restored ' . $name . '. ' . $result['message']);
                    }
                    $error = $result['message'];
                }
            }
        }
    }
}

[$flashType, $flashMsg] = $_SESSION['backup_flash'] ?? [null, null];
unset($_SESSION['backup_flash']);
$recent = hef_backup_recent($pdo, null, 12);

require_once __DIR__ . '/header.php';
?>
<h4 class="mb-1"><i class="bi bi-hdd-stack me-2"></i>Backup &amp; restore</h4>
<p class="text-muted small mb-3">Encrypted backups of the whole database or of a single company. Keep the files (and their passphrases) somewhere safe that isn't this server. Photos uploaded to the server aren't inside a backup, so also copy the uploads folder from your hosting file manager or FTP.</p>

<?php if ($unavailable): ?><div class="alert alert-danger"><?= htmlspecialchars($unavailable) ?></div><?php endif; ?>
<?php if ($flashMsg): ?><div class="alert alert-<?= $flashType === 'ok' ? 'success' : 'danger' ?>"><?= htmlspecialchars($flashMsg) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="row g-3 mb-3">
    <!-- Whole database -->
    <div class="col-12 col-xl-6">
        <div class="card p-4 h-100">
            <h6 class="mb-3"><i class="bi bi-database me-1"></i>Whole database</h6>
            <form method="POST" class="mb-3">
                <input type="hidden" name="download_database" value="1">
                <div class="row g-2 mb-2">
                    <div class="col-6"><label class="form-label small">Passphrase</label><input type="password" name="passphrase" class="form-control" minlength="<?= $minPassphrase ?>" required autocomplete="new-password"></div>
                    <div class="col-6"><label class="form-label small">Repeat it</label><input type="password" name="passphrase2" class="form-control" minlength="<?= $minPassphrase ?>" required autocomplete="new-password"></div>
                </div>
                <button type="submit" class="btn btn-hef text-white" <?= $unavailable ? 'disabled' : '' ?>><i class="bi bi-download me-1"></i>Download full backup</button>
            </form>
            <hr>
            <div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-triangle me-1"></i><strong>Restoring replaces EVERY company's data</strong> with the file's contents. Everything added since the backup is lost, and you may be logged out. If it fails, nothing is changed.</div>
            <form method="POST" enctype="multipart/form-data" onsubmit="return confirm('Replace the ENTIRE database with this backup? This cannot be undone.');">
                <input type="hidden" name="restore_database" value="1">
                <div class="mb-2"><input type="file" name="backup_file" class="form-control" accept=".hefbak" required></div>
                <div class="row g-2 mb-2">
                    <div class="col-6"><input type="password" name="passphrase" class="form-control" placeholder="Its passphrase" required autocomplete="off"></div>
                    <div class="col-6"><input type="text" name="confirm_text" class="form-control" placeholder="Type RESTORE EVERYTHING" required autocomplete="off"></div>
                </div>
                <button type="submit" class="btn btn-danger" <?= $unavailable ? 'disabled' : '' ?>><i class="bi bi-arrow-counterclockwise me-1"></i>Restore whole database</button>
            </form>
        </div>
    </div>

    <!-- One company -->
    <div class="col-12 col-xl-6">
        <div class="card p-4 h-100">
            <h6 class="mb-3"><i class="bi bi-building me-1"></i>One company</h6>
            <form method="POST" class="mb-3">
                <input type="hidden" name="download_company" value="1">
                <div class="mb-2">
                    <label class="form-label small">Company</label>
                    <select name="company_id" class="form-select" required>
                        <option value="">Choose…</option>
                        <?php foreach ($companies as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['status']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-6"><label class="form-label small">Passphrase</label><input type="password" name="passphrase" class="form-control" minlength="<?= $minPassphrase ?>" required autocomplete="new-password"></div>
                    <div class="col-6"><label class="form-label small">Repeat it</label><input type="password" name="passphrase2" class="form-control" minlength="<?= $minPassphrase ?>" required autocomplete="new-password"></div>
                </div>
                <button type="submit" class="btn btn-hef text-white" <?= $unavailable ? 'disabled' : '' ?>><i class="bi bi-download me-1"></i>Download company backup</button>
                <div class="form-text">Includes the company's login accounts, subscription and settings, so it can be rebuilt even after being deleted.</div>
            </form>
            <hr>
            <div class="alert alert-danger py-2 small"><i class="bi bi-exclamation-triangle me-1"></i><strong>Restoring replaces that company's current data</strong> (or recreates it if it no longer exists). Other companies aren't touched.</div>
            <form method="POST" enctype="multipart/form-data" onsubmit="return confirm('Replace this company\'s data with the backup? This cannot be undone.');">
                <input type="hidden" name="restore_company" value="1">
                <div class="mb-2"><input type="file" name="backup_file" class="form-control" accept=".hefbak" required></div>
                <div class="row g-2 mb-2">
                    <div class="col-6"><input type="password" name="passphrase" class="form-control" placeholder="Its passphrase" required autocomplete="off"></div>
                    <div class="col-6"><input type="text" name="confirm_text" class="form-control" placeholder="Type RESTORE" required autocomplete="off"></div>
                </div>
                <button type="submit" class="btn btn-danger" <?= $unavailable ? 'disabled' : '' ?>><i class="bi bi-arrow-counterclockwise me-1"></i>Restore company</button>
            </form>
        </div>
    </div>
</div>

<p class="text-muted small">Largest file this server accepts: <?= htmlspecialchars((string) ini_get('upload_max_filesize')) ?>. A company owner's own backup (from their Backup page) restores only from that page.</p>

<?php if ($recent): ?>
<h6 class="mt-4 mb-2">Recent activity</h6>
<div class="card">
    <ul class="list-group list-group-flush small">
        <?php foreach ($recent as $r): ?>
            <li class="list-group-item d-flex justify-content-between gap-2">
                <span>
                    <span class="badge <?= $r['action'] === 'backup' ? 'bg-success' : 'bg-warning text-dark' ?> me-1"><?= htmlspecialchars(ucfirst($r['action'])) ?></span>
                    <?= $r['company_name'] ? '<strong>' . htmlspecialchars($r['company_name']) . '</strong> · ' : ($r['scope'] === 'database' ? '<strong>Whole database</strong> · ' : '') ?>
                    <?= htmlspecialchars((string) $r['details']) ?><?= $r['user_name'] ? ' · ' . htmlspecialchars($r['user_name']) : '' ?>
                </span>
                <span class="text-muted text-nowrap"><?= date('d M Y, h:i A', strtotime($r['created_at'])) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
