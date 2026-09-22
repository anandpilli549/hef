<?php
require_once __DIR__ . '/bootstrap.php';

if (! $currentUser['is_super_admin']) {
    header('Location: /hef/admin/dashboard.php');
    exit;
}

$error = '';
$status = '';

// --- Quick actions: grant free lifetime access, or suspend ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grant_free_id'])) {
    $cid = (int) $_POST['grant_free_id'];
    $pdo->prepare("UPDATE companies SET status = 'active', updated_at = NOW() WHERE id = ?")->execute([$cid]);
    $pdo->prepare("UPDATE company_subscriptions SET status = 'cancelled', updated_at = NOW() WHERE company_id = ? AND status = 'active'")->execute([$cid]);
    $status = 'Company granted free active access.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['suspend_id'])) {
    $cid = (int) $_POST['suspend_id'];
    if ($cid == HEF_OWNER_COMPANY_ID) {
        $error = "Can't suspend the platform owner's own company.";
    } else {
        $pdo->prepare("UPDATE companies SET status = 'expired', updated_at = NOW() WHERE id = ?")->execute([$cid]);
        $status = 'Company suspended.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['extend_trial_id'])) {
    $cid = (int) $_POST['extend_trial_id'];
    $days = (int) ($_POST['extend_days'] ?? 14);
    $pdo->prepare("UPDATE companies SET status = 'trial', trial_ends_at = NOW() + INTERVAL ? DAY, updated_at = NOW() WHERE id = ?")
        ->execute([$days, $cid]);
    $status = "Trial extended by {$days} days.";
}

// --- Edit a company's details ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_company_id'])) {
    $cid = (int) $_POST['edit_company_id'];
    $name = trim($_POST['name'] ?? '');
    $slug = strtolower(trim($_POST['slug'] ?? ''));
    $newStatus = $_POST['status'] ?? '';
    $trialDate = trim($_POST['trial_ends_at'] ?? '');
    $timezone = trim($_POST['timezone'] ?? '');
    $currency = strtoupper(trim($_POST['currency_code'] ?? ''));
    $address = trim($_POST['address'] ?? '');
    $pincode = trim($_POST['pincode'] ?? '');

    $stmt = $pdo->prepare('SELECT id, slug FROM companies WHERE id = ?');
    $stmt->execute([$cid]);
    $existing = $stmt->fetch();

    $trialParsed = $trialDate === '' ? null : DateTime::createFromFormat('Y-m-d', $trialDate);

    if (! $existing) {
        $error = 'Company not found.';
    } elseif ($name === '' || strlen($name) > 255) {
        $error = 'Enter a company name (up to 255 characters).';
    } elseif (! preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) || strlen($slug) > 255) {
        $error = 'The slug can only use lowercase letters, numbers and single hyphens (e.g. hyderabad-ecofarm).';
    } elseif (! in_array($newStatus, ['trial', 'active', 'expired', 'cancelled'], true)) {
        $error = 'Choose a valid status.';
    } elseif ($cid === (int) HEF_OWNER_COMPANY_ID && $newStatus !== 'active') {
        $error = "The platform owner's own company has to stay active.";
    } elseif ($trialDate !== '' && (! $trialParsed || $trialParsed->format('Y-m-d') !== $trialDate)) {
        $error = 'Enter a valid trial end date.';
    } elseif (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
        $error = 'Choose a valid time zone (for example Asia/Kolkata).';
    } elseif (! preg_match('/^[A-Z]{3}$/', $currency)) {
        $error = 'The currency must be a 3-letter code such as INR.';
    } elseif (strlen($address) > 255 || strlen($pincode) > 10) {
        $error = 'Address is limited to 255 characters and pincode to 10.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM companies WHERE slug = ? AND id != ?');
        $stmt->execute([$slug, $cid]);
        if ($stmt->fetch()) {
            $error = 'Another company already uses that slug.';
        } else {
            try {
                $pdo->prepare(
                    'UPDATE companies SET name = ?, slug = ?, status = ?, trial_ends_at = ?, timezone = ?,
                            currency_code = ?, address = ?, pincode = ?, updated_at = NOW()
                     WHERE id = ?'
                )->execute([
                    $name, $slug, $newStatus, $trialDate !== '' ? $trialDate . ' 23:59:59' : null, $timezone,
                    $currency, $address !== '' ? $address : null, $pincode !== '' ? $pincode : null, $cid,
                ]);
                $status = "Updated {$name}." . ($slug !== $existing['slug'] ? ' Its public store address changed, so old shared links will stop working.' : '');
            } catch (PDOException $e) {
                error_log('company update failed: ' . $e->getMessage());
                $error = 'Could not save changes.';
            }
        }
    }
}

// --- Delete a company and everything in it ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_company_id'])) {
    $cid = (int) $_POST['delete_company_id'];
    $typed = trim($_POST['confirm_name'] ?? '');

    $stmt = $pdo->prepare('SELECT id, name FROM companies WHERE id = ?');
    $stmt->execute([$cid]);
    $target = $stmt->fetch();

    if (! $target) {
        $error = 'Company not found.';
    } elseif ($cid === (int) HEF_OWNER_COMPANY_ID) {
        $error = "You can't delete the platform owner's own company.";
    } elseif ($cid === (int) $currentUser['company_id']) {
        $error = "You can't delete the company you are logged in to.";
    } elseif ($typed !== $target['name']) {
        $error = "The name you typed didn't match, so nothing was deleted.";
    } elseif ((int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_super_admin = 1 AND company_id = ' . (int) $cid)->fetchColumn() > 0) {
        $error = 'This company contains a Super Admin account, which would be deleted with it. Move or remove that account first.';
    } else {
        // A few tables point at species or listings without a cascade, so
        // clear those first; deleting the company then cascades the rest
        // (users, devices, species, feed, finances, customers, notes ...).
        $pdo->beginTransaction();
        try {
            foreach (['incubation_batches', 'batches', 'bookings'] as $table) {
                $pdo->prepare("DELETE FROM {$table} WHERE company_id = ?")->execute([$cid]);
            }
            $pdo->prepare('DELETE FROM companies WHERE id = ?')->execute([$cid]);
            $pdo->commit();
            error_log("HEFarm: super admin #{$currentUser['user_id']} deleted company #{$cid} ({$target['name']})");
            $status = "Deleted {$target['name']} and all its data.";
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('company delete failed: ' . $e->getMessage());
            $error = 'Could not delete this company, so nothing was changed. Suspend it instead.';
        }
    }
}

require_once __DIR__ . '/header.php';

$perPage = 20;
$page = hef_current_page();

$stmt = $pdo->query('SELECT COUNT(*) FROM companies');
$totalCompanies = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT c.*,
        (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id) AS user_count,
        (SELECT COUNT(*) FROM batches b WHERE b.company_id = c.id) AS batch_count,
        (SELECT sp.name FROM company_subscriptions cs JOIN subscription_plans sp ON sp.id = cs.subscription_plan_id
         WHERE cs.company_id = c.id ORDER BY cs.created_at DESC LIMIT 1) AS plan_name
     FROM companies c
     ORDER BY c.created_at DESC
     LIMIT {$perPage} OFFSET " . hef_offset($page, $perPage)
);
$stmt->execute();
$companies = $stmt->fetchAll();

// Platform-wide stats
$stmt = $pdo->query("SELECT status, COUNT(*) AS cnt FROM companies GROUP BY status");
$statusCounts = array_column($stmt->fetchAll(), 'cnt', 'status');
?>
<h4 class="mb-3"><i class="bi bi-buildings me-2"></i>All Companies</h4>

<?php if ($status): ?><div class="alert alert-success py-2"><?= htmlspecialchars($status) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center">
            <div class="fs-3 fw-bold"><?= $totalCompanies ?></div>
            <div class="text-muted small">Total Companies</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center">
            <div class="fs-3 fw-bold text-success"><?= $statusCounts['active'] ?? 0 ?></div>
            <div class="text-muted small">Active</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center">
            <div class="fs-3 fw-bold text-warning"><?= $statusCounts['trial'] ?? 0 ?></div>
            <div class="text-muted small">On Trial</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center">
            <div class="fs-3 fw-bold text-danger"><?= $statusCounts['expired'] ?? 0 ?></div>
            <div class="text-muted small">Expired</div>
        </div>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table mb-0">
            <thead>
                <tr><th>Company</th><th>Status</th><th>Plan</th><th>Users</th><th>Batches</th><th>Joined</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($companies as $c): ?>
                    <tr>
                        <td>
                            <a href="/hef/admin/super-company-view.php?id=<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></a>
                            <?php if ($c['id'] == HEF_OWNER_COMPANY_ID): ?><span class="badge bg-dark ms-1">You</span><?php endif; ?>
                        </td>
                        <td><span class="badge bg-secondary-subtle text-dark text-capitalize"><?= htmlspecialchars($c['status']) ?></span></td>
                        <td class="small"><?= htmlspecialchars($c['plan_name'] ?? '—') ?></td>
                        <td><?= (int) $c['user_count'] ?></td>
                        <td><?= (int) $c['batch_count'] ?></td>
                        <td class="small text-muted"><?= date('d M Y', strtotime($c['created_at'])) ?></td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="/hef/admin/super-company-view.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-secondary">View</a>
                                <button type="button" class="btn btn-sm btn-outline-secondary" title="Edit" data-bs-toggle="modal" data-bs-target="#editCompany<?= (int) $c['id'] ?>"><i class="bi bi-pencil"></i></button>
                                <?php if ($c['id'] == HEF_OWNER_COMPANY_ID || $c['id'] == $currentUser['company_id']): ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger" disabled title="This is your own company and can't be deleted"><i class="bi bi-trash"></i></button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" data-bs-toggle="modal" data-bs-target="#deleteCompany<?= (int) $c['id'] ?>"><i class="bi bi-trash"></i></button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?= hef_pagination_links($page, $totalCompanies, $perPage) ?>

<datalist id="timezoneList">
    <?php foreach (DateTimeZone::listIdentifiers() as $tzName): ?><option value="<?= htmlspecialchars($tzName) ?>"><?php endforeach; ?>
</datalist>

<?php foreach ($companies as $c): ?>
    <!-- Edit <?= (int) $c['id'] ?> -->
    <div class="modal fade" id="editCompany<?= (int) $c['id'] ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="edit_company_id" value="<?= (int) $c['id'] ?>">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit company</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-2 mb-2">
                            <div class="col-12 col-md-7">
                                <label class="form-label small">Name</label>
                                <input type="text" name="name" class="form-control" maxlength="255" required value="<?= htmlspecialchars($c['name']) ?>">
                            </div>
                            <div class="col-12 col-md-5">
                                <label class="form-label small">Slug (store address)</label>
                                <input type="text" name="slug" class="form-control" maxlength="255" required pattern="[a-z0-9]+(-[a-z0-9]+)*" value="<?= htmlspecialchars($c['slug']) ?>">
                            </div>
                        </div>
                        <div class="form-text mb-2">Changing the slug changes the public store address (store.php?company=<em>slug</em>), so links people already shared will stop working.</div>
                        <div class="row g-2 mb-2">
                            <div class="col-6 col-md-4">
                                <label class="form-label small">Status</label>
                                <select name="status" class="form-select">
                                    <?php foreach (['trial', 'active', 'expired', 'cancelled'] as $st): ?>
                                        <option value="<?= $st ?>" <?= $c['status'] === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-4">
                                <label class="form-label small">Trial ends</label>
                                <input type="date" name="trial_ends_at" class="form-control" value="<?= $c['trial_ends_at'] ? date('Y-m-d', strtotime($c['trial_ends_at'])) : '' ?>">
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label small">Currency</label>
                                <input type="text" name="currency_code" class="form-control text-uppercase" maxlength="3" required value="<?= htmlspecialchars($c['currency_code']) ?>">
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label small">Pincode</label>
                                <input type="text" name="pincode" class="form-control" maxlength="10" value="<?= htmlspecialchars((string) $c['pincode']) ?>">
                            </div>
                        </div>
                        <div class="row g-2 mb-2">
                            <div class="col-12 col-md-4">
                                <label class="form-label small">Time zone</label>
                                <input type="text" name="timezone" class="form-control" list="timezoneList" required value="<?= htmlspecialchars($c['timezone']) ?>">
                            </div>
                            <div class="col-12 col-md-8">
                                <label class="form-label small">Address</label>
                                <input type="text" name="address" class="form-control" maxlength="255" value="<?= htmlspecialchars((string) $c['address']) ?>">
                            </div>
                        </div>
                        <div class="form-text">Setting the status here doesn't touch the company's subscription. Use "Grant free access" on the company page for that.</div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-hef text-white w-100">Save changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($c['id'] != HEF_OWNER_COMPANY_ID && $c['id'] != $currentUser['company_id']): ?>
    <!-- Delete <?= (int) $c['id'] ?> -->
    <div class="modal fade" id="deleteCompany<?= (int) $c['id'] ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="delete_company_id" value="<?= (int) $c['id'] ?>">
                    <div class="modal-header">
                        <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Delete company</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="mb-2">This permanently deletes <strong><?= htmlspecialchars($c['name']) ?></strong> and everything in it:
                            <?= (int) $c['user_count'] ?> user(s), <?= (int) $c['batch_count'] ?> batch(es), and all their species, feed, finances, customers, suppliers, bookings, listings, notes and subscriptions. <strong>It can't be undone</strong> and it isn't kept in the audit log.</p>
                        <p class="text-muted small mb-3">Uploaded photos stay on the server. If you only want to stop their access, suspend the company instead.</p>
                        <label class="form-label small">Type the company name to confirm</label>
                        <input type="text" name="confirm_name" class="form-control" required autocomplete="off" placeholder="<?= htmlspecialchars($c['name']) ?>">
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-danger w-100">Delete company and all its data</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
<?php endforeach; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
