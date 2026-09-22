<?php
require_once __DIR__ . '/bootstrap.php';

if (! $currentUser['is_super_admin']) {
    header('Location: /hef/admin/dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_plan_id'])) {
    $id = (int) $_POST['delete_plan_id'];
    try {
        $pdo->prepare('DELETE FROM subscription_plans WHERE id = ?')->execute([$id]);
        header('Location: /hef/admin/super-plans.php');
        exit;
    } catch (PDOException $e) {
        $error = str_contains($e->getMessage(), 'foreign key')
            ? "Can't delete — companies are currently subscribed to this plan."
            : 'Could not delete plan.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_plan_id'])) {
    $id = (int) $_POST['edit_plan_id'];
    $name = trim($_POST['name'] ?? '');
    $price = (float) ($_POST['price'] ?? 0);
    $cycle = $_POST['billing_cycle'] ?? 'monthly';
    $maxUsers = (int) ($_POST['max_users'] ?? 0);
    $maxBatches = (int) ($_POST['max_batches'] ?? 0);
    $storefrontEnabled = isset($_POST['storefront_enabled']) ? 1 : 0;
    $proFeaturesEnabled = isset($_POST['pro_features_enabled']) ? 1 : 0;

    if (! $name || $price <= 0) {
        $error = 'Name and a positive price are required.';
    } else {
        $pdo->prepare(
            'UPDATE subscription_plans SET name=?, price=?, billing_cycle=?, max_users=?, max_batches=?, storefront_enabled=?, pro_features_enabled=?, updated_at=NOW() WHERE id=?'
        )->execute([$name, $price, $cycle, $maxUsers, $maxBatches, $storefrontEnabled, $proFeaturesEnabled, $id]);
        header('Location: /hef/admin/super-plans.php');
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $price = (float) ($_POST['price'] ?? 0);
    $cycle = $_POST['billing_cycle'] ?? 'monthly';
    $maxUsers = (int) ($_POST['max_users'] ?? 0);
    $maxBatches = (int) ($_POST['max_batches'] ?? 0);
    $storefrontEnabled = isset($_POST['storefront_enabled']) ? 1 : 0;
    $proFeaturesEnabled = isset($_POST['pro_features_enabled']) ? 1 : 0;

    if (! $name || $price <= 0) {
        $error = 'Name and a positive price are required.';
    } else {
        $pdo->prepare(
            "INSERT INTO subscription_plans (name, price, currency_code, billing_cycle, max_users, max_batches, storefront_enabled, pro_features_enabled, created_at, updated_at)
             VALUES (?, ?, 'INR', ?, ?, ?, ?, ?, NOW(), NOW())"
        )->execute([$name, $price, $cycle, $maxUsers, $maxBatches, $storefrontEnabled, $proFeaturesEnabled]);
        header('Location: /hef/admin/super-plans.php');
        exit;
    }
}

require_once __DIR__ . '/header.php';

$stmt = $pdo->query('SELECT * FROM subscription_plans ORDER BY price');
$plans = $stmt->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-tags me-2"></i>Manage Plans</h4>
    <button class="btn btn-hef text-white btn-sm" data-bs-toggle="modal" data-bs-target="#addPlanModal">
        <i class="bi bi-plus-lg me-1"></i>Add plan
    </button>
</div>

<?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="row g-3">
    <?php foreach ($plans as $plan): ?>
        <div class="col-12 col-md-6 col-lg-3">
            <div class="card p-3 h-100 d-flex flex-column">
                <h6 class="mb-1"><?= htmlspecialchars($plan['name']) ?></h6>
                <div class="fs-4 fw-bold text-success">₹<?= number_format($plan['price'], 0) ?></div>
                <div class="text-muted small mb-2">per <?= $plan['billing_cycle'] === 'monthly' ? 'month' : 'year' ?></div>
                <div class="text-muted small mb-3">
                    <?= (int) $plan['max_users'] > 0 ? 'Up to ' . (int) $plan['max_users'] . ' users' : 'Unlimited users' ?> · <?= (int) $plan['max_batches'] > 0 ? (int) $plan['max_batches'] . ' active batches' : 'unlimited batches' ?>
                    <?= $plan['storefront_enabled'] ? ' · storefront' : '' ?>
                    <?= $plan['pro_features_enabled'] ? ' · <span class="badge bg-success-subtle text-success">Pro</span>' : '' ?>
                </div>
                <div class="d-flex gap-2 mt-auto">
                    <button class="btn btn-sm btn-outline-secondary flex-fill" data-bs-toggle="modal" data-bs-target="#editPlan<?= $plan['id'] ?>">Edit</button>
                    <form method="POST" onsubmit="return confirm('Delete this plan?');">
                        <input type="hidden" name="delete_plan_id" value="<?= $plan['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="editPlan<?= $plan['id'] ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST">
                        <input type="hidden" name="edit_plan_id" value="<?= $plan['id'] ?>">
                        <div class="modal-header">
                            <h5 class="modal-title">Edit plan</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-2">
                                <label class="form-label small">Name</label>
                                <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($plan['name']) ?>">
                            </div>
                            <div class="mb-2">
                                <label class="form-label small">Price (₹)</label>
                                <input type="number" step="0.01" name="price" class="form-control" required value="<?= htmlspecialchars((string) $plan['price']) ?>">
                            </div>
                            <div class="mb-2">
                                <label class="form-label small">Billing cycle</label>
                                <select name="billing_cycle" class="form-select">
                                    <option value="monthly" <?= $plan['billing_cycle'] === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                    <option value="yearly" <?= $plan['billing_cycle'] === 'yearly' ? 'selected' : '' ?>>Yearly</option>
                                </select>
                            </div>
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="form-label small">Max users <span class="text-muted">(0 = unlimited)</span></label>
                                    <input type="number" min="0" name="max_users" class="form-control" value="<?= (int) $plan['max_users'] ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small">Max active batches <span class="text-muted">(0 = unlimited)</span></label>
                                    <input type="number" min="0" name="max_batches" class="form-control" value="<?= (int) $plan['max_batches'] ?>">
                                </div>
                            </div>
                            <div class="form-check mb-2">
                                <input type="checkbox" name="storefront_enabled" class="form-check-input" id="storefrontEnabled<?= $plan['id'] ?>" <?= $plan['storefront_enabled'] ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="storefrontEnabled<?= $plan['id'] ?>">Storefront enabled</label>
                            </div>
                            <div class="form-check mb-2">
                                <input type="checkbox" name="pro_features_enabled" class="form-check-input" id="proFeatures<?= $plan['id'] ?>" <?= $plan['pro_features_enabled'] ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="proFeatures<?= $plan['id'] ?>">Pro features (Customers, Suppliers, Requirements &amp; Staff payroll)</label>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="submit" class="btn btn-hef text-white w-100">Save changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- Add Plan Modal -->
<div class="modal fade" id="addPlanModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Add plan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label small">Name</label>
                        <input type="text" name="name" class="form-control" required placeholder="e.g. Starter">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Price (₹)</label>
                        <input type="number" step="0.01" name="price" class="form-control" required min="0.01">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Billing cycle</label>
                        <select name="billing_cycle" class="form-select">
                            <option value="monthly">Monthly</option>
                            <option value="yearly">Yearly</option>
                        </select>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small">Max users <span class="text-muted">(0 = unlimited)</span></label>
                            <input type="number" min="0" name="max_users" class="form-control" value="1">
                        </div>
                        <div class="col-6">
                            <label class="form-label small">Max active batches <span class="text-muted">(0 = unlimited)</span></label>
                            <input type="number" min="0" name="max_batches" class="form-control" value="10">
                        </div>
                    </div>
                    <div class="form-check mb-2">
                        <input type="checkbox" name="storefront_enabled" class="form-check-input" id="storefrontEnabledNew" checked>
                        <label class="form-check-label small" for="storefrontEnabledNew">Storefront enabled</label>
                    </div>
                    <div class="form-check mb-2">
                        <input type="checkbox" name="pro_features_enabled" class="form-check-input" id="proFeaturesNew">
                        <label class="form-check-label small" for="proFeaturesNew">Pro features (Customers, Suppliers, Requirements &amp; Staff payroll)</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-hef text-white w-100">Create plan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
