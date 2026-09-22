<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/permissions.php';
hef_require_role(['owner'], $currentUser['role']);

$companyId = $currentUser['company_id'];
$error = '';

require_once __DIR__ . '/header.php';

$stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
$stmt->execute([$companyId]);
$company = $stmt->fetch();

$stmt = $pdo->prepare(
    "SELECT cs.*, sp.name AS plan_name, sp.price, sp.billing_cycle
     FROM company_subscriptions cs JOIN subscription_plans sp ON sp.id = cs.subscription_plan_id
     WHERE cs.company_id = ? ORDER BY cs.created_at DESC LIMIT 1"
);
$stmt->execute([$companyId]);
$currentSub = $stmt->fetch();

$stmt = $pdo->query('SELECT * FROM subscription_plans ORDER BY price');
$plans = $stmt->fetchAll();

$trialDaysLeft = null;
if ($company['status'] === 'trial' && $company['trial_ends_at']) {
    $trialDaysLeft = max(0, (int) ((strtotime($company['trial_ends_at']) - time()) / 86400));
}
$platformCredentials = hef_get_platform_razorpay_credentials($pdo);
?>
<h4 class="mb-3"><i class="bi bi-credit-card me-2"></i>Billing</h4>

<?php if ($companyId == HEF_OWNER_COMPANY_ID): ?>
    <div class="card p-4">
        <span class="badge bg-success-subtle text-success mb-2">Lifetime Access</span>
        <p class="mb-0 text-muted">As the operator of this HEFarm instance, your own farm doesn't need a subscription.</p>
    </div>
<?php else: ?>

<?php if (isset($_GET['success'])): ?>
    <div class="alert alert-success">Subscription activated successfully!</div>
<?php endif; ?>
<?php if (($_GET['upgrade'] ?? '') === 'pro'): ?>
    <div class="alert alert-info"><i class="bi bi-stars me-1"></i>Customers, Suppliers, Requirements and Staff &amp; Payroll are available on Pro plans. Choose a plan marked <strong>Pro</strong> below to unlock them.</div>
<?php endif; ?>
<?php if (isset($_GET['error'])): ?>
    <div class="alert alert-danger">There was a problem processing your payment. Please try again or contact support.</div>
<?php endif; ?>

<div class="card p-4 mb-4">
    <?php if ($company['status'] === 'trial'): ?>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <span class="badge bg-warning-subtle text-warning mb-1">Free Trial</span>
                <div><?= $trialDaysLeft ?> day(s) left — trial ends <?= date('d M Y', strtotime($company['trial_ends_at'])) ?></div>
            </div>
        </div>
    <?php elseif ($currentSub && $currentSub['status'] === 'active'): ?>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <span class="badge bg-success-subtle text-success mb-1">Active</span>
                <div><?= htmlspecialchars($currentSub['plan_name']) ?> — ₹<?= number_format($currentSub['price'], 0) ?>/<?= $currentSub['billing_cycle'] === 'monthly' ? 'month' : 'year' ?></div>
                <div class="text-muted small">Renews / expires <?= date('d M Y', strtotime($currentSub['ends_at'])) ?></div>
            </div>
        </div>
    <?php else: ?>
        <div class="badge bg-danger-subtle text-danger">No active subscription</div>
        <p class="text-muted small mt-2 mb-0">Choose a plan below to continue using HEFarm.</p>
    <?php endif; ?>
    <?php
        $usageRows = [
            'Team members' => ['users', 'bi-people'],
            'Active batches' => ['batches', 'bi-egg-fried'],
        ];
    ?>
    <div class="d-flex flex-wrap gap-4 mt-3 pt-3 border-top small">
        <?php foreach ($usageRows as $usageLabel => [$usageKey, $usageIcon]): ?>
            <?php
                $used = hef_plan_usage($pdo, (int) $companyId, $usageKey);
                $limit = hef_plan_limit($pdo, (int) $companyId, $usageKey);
                $atLimit = $limit !== null && $used >= $limit;
            ?>
            <div>
                <i class="bi <?= $usageIcon ?> me-1 text-muted"></i><?= $usageLabel ?>:
                <strong class="<?= $atLimit ? 'text-danger' : '' ?>"><?= $used ?><?= $limit !== null ? ' of ' . $limit : '' ?></strong>
                <?php if ($limit === null): ?><span class="text-muted">(no limit)</span><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php if (! $platformCredentials): ?>
    <div class="alert alert-warning">Billing isn't fully configured on this HEFarm instance yet — payments aren't available right now.</div>
<?php endif; ?>

<div class="row g-3">
    <?php foreach ($plans as $plan): ?>
        <div class="col-12 col-md-6 col-lg-3">
            <div class="card p-3 h-100 d-flex flex-column">
                <h6 class="mb-1"><?= htmlspecialchars($plan['name']) ?></h6>
                <div class="fs-4 fw-bold text-success">₹<?= number_format($plan['price'], 0) ?></div>
                <div class="text-muted small mb-2">per <?= $plan['billing_cycle'] === 'monthly' ? 'month' : 'year' ?></div>
                <ul class="list-unstyled small text-muted mb-3">
                    <li><i class="bi bi-check2 text-success me-1"></i> <?= (int) $plan['max_users'] > 0 ? 'Up to ' . (int) $plan['max_users'] . ' users' : 'Unlimited users' ?></li>
                    <li><i class="bi bi-check2 text-success me-1"></i> <?= (int) $plan['max_batches'] > 0 ? 'Up to ' . (int) $plan['max_batches'] . ' active batches' : 'Unlimited batches' ?></li>
                    <?php if ($plan['storefront_enabled']): ?><li><i class="bi bi-check2 text-success me-1"></i> Storefront included</li><?php endif; ?>
                    <?php if ($plan['pro_features_enabled']): ?><li><i class="bi bi-check2 text-success me-1"></i> <strong>Pro:</strong> Customers, Suppliers, Requirements &amp; Staff payroll</li><?php endif; ?>
                </ul>
                <a href="/hef/admin/billing-pay.php?plan_id=<?= $plan['id'] ?>" class="btn btn-hef text-white mt-auto <?= ! $platformCredentials ? 'disabled' : '' ?>">
                    <?= $currentSub && (int) $currentSub['subscription_plan_id'] === (int) $plan['id'] ? 'Renew' : 'Choose Plan' ?>
                </a>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
