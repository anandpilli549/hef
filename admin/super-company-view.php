<?php
require_once __DIR__ . '/bootstrap.php';

if (! $currentUser['is_super_admin']) {
    header('Location: /hef/admin/dashboard.php');
    exit;
}

$targetCompanyId = (int) ($_GET['id'] ?? 0);
$error = '';
$status = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['grant_free'])) {
    $pdo->prepare("UPDATE companies SET status = 'active', updated_at = NOW() WHERE id = ?")->execute([$targetCompanyId]);
    $pdo->prepare("UPDATE company_subscriptions SET status = 'cancelled', updated_at = NOW() WHERE company_id = ? AND status = 'active'")->execute([$targetCompanyId]);
    $status = 'Granted free active access.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['suspend'])) {
    if ($targetCompanyId == HEF_OWNER_COMPANY_ID) {
        $error = "Can't suspend the platform owner's own company.";
    } else {
        $pdo->prepare("UPDATE companies SET status = 'expired', updated_at = NOW() WHERE id = ?")->execute([$targetCompanyId]);
        $status = 'Company suspended.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['extend_trial'])) {
    $days = (int) ($_POST['extend_days'] ?? 14);
    $pdo->prepare("UPDATE companies SET status = 'trial', trial_ends_at = NOW() + INTERVAL ? DAY, updated_at = NOW() WHERE id = ?")
        ->execute([$days, $targetCompanyId]);
    $status = "Trial extended by {$days} days.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reactivate'])) {
    $pdo->prepare("UPDATE companies SET status = 'active', updated_at = NOW() WHERE id = ?")->execute([$targetCompanyId]);
    $status = 'Company reactivated.';
}

require_once __DIR__ . '/header.php';

$stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
$stmt->execute([$targetCompanyId]);
$company = $stmt->fetch();

if (! $company) {
    echo '<div class="alert alert-danger">Company not found.</div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$cid = (int) $company['id'];
$isOperator = $cid === (int) HEF_OWNER_COMPANY_ID;
$now = time();

/** "5 min ago", "3 h ago", "12 days ago", or a date for anything older; "never" when empty. */
function hef_ago(?string $timestamp): string
{
    if (! $timestamp) {
        return 'never';
    }
    $diff = time() - strtotime($timestamp);
    if ($diff < 90) {
        return 'just now';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . ' min ago';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . ' h ago';
    }
    if ($diff < 86400 * 30) {
        return floor($diff / 86400) . ' days ago';
    }

    return date('d M Y', strtotime($timestamp));
}

/** Whole days from now to $ts: positive = in the future, negative = in the past. */
function hef_days_until(int $ts): int
{
    $diff = $ts - time();

    return $diff >= 0 ? (int) ceil($diff / 86400) : -(int) floor(abs($diff) / 86400);
}

function hef_days_text(int $days, bool $future): string
{
    $n = abs($days);
    if ($future) {
        return $n === 0 ? 'today' : 'in ' . $n . ($n === 1 ? ' day' : ' days');
    }

    return $n === 0 ? 'earlier today' : $n . ($n === 1 ? ' day' : ' days') . ' ago';
}

// ---- People ----
$stmt = $pdo->prepare(
    'SELECT u.*, (SELECT MAX(ud.last_active_at) FROM user_devices ud WHERE ud.user_id = u.id) AS last_active
     FROM users u WHERE u.company_id = ?
     ORDER BY FIELD(u.role, "owner", "worker", "vet"), u.created_at'
);
$stmt->execute([$cid]);
$users = $stmt->fetchAll();

$owner = null;
$userStatusCounts = ['active' => 0, 'invited' => 0, 'disabled' => 0];
$lastActive = null;
foreach ($users as $u) {
    if ($owner === null && $u['role'] === 'owner' && ! $u['is_super_admin']) {
        $owner = $u;
    }
    $userStatusCounts[$u['status']] = ($userStatusCounts[$u['status']] ?? 0) + 1;
    if ($u['last_active'] && ($lastActive === null || $u['last_active'] > $lastActive)) {
        $lastActive = $u['last_active'];
    }
}

// ---- Subscriptions and expiry ----
$stmt = $pdo->prepare(
    "SELECT cs.*, sp.name AS plan_name, sp.price, sp.billing_cycle
     FROM company_subscriptions cs JOIN subscription_plans sp ON sp.id = cs.subscription_plan_id
     WHERE cs.company_id = ? ORDER BY cs.created_at DESC, cs.id DESC"
);
$stmt->execute([$cid]);
$subscriptions = $stmt->fetchAll();

$activeSub = null;
foreach ($subscriptions as $s) {
    if ($s['status'] === 'active' && ($s['ends_at'] === null || strtotime($s['ends_at']) > $now)) {
        $activeSub = $s;
        break;
    }
}

// What ends this company's access, and when.
$expiryTs = null;
$expiryTitle = '';
$expirySource = '';
if ($isOperator) {
    $expiryTitle = 'Never expires';
    $expirySource = "Platform owner's own company";
} elseif ($activeSub) {
    if ($activeSub['ends_at']) {
        $expiryTs = strtotime($activeSub['ends_at']);
        $expirySource = $activeSub['plan_name'] . ' plan';
    } else {
        $expiryTitle = 'No end date';
        $expirySource = $activeSub['plan_name'] . ' plan (ongoing)';
    }
} elseif ($company['status'] === 'trial') {
    if ($company['trial_ends_at']) {
        $expiryTs = strtotime($company['trial_ends_at']);
        $expirySource = 'Free trial';
    } else {
        $expiryTitle = 'No trial end date';
        $expirySource = 'Free trial';
    }
} elseif ($company['status'] === 'active') {
    $expiryTitle = 'No expiry';
    $expirySource = 'Free access (no paid plan)';
} else {
    // Expired or cancelled: the last date they had access until.
    $candidates = array_filter([
        $subscriptions ? ($subscriptions[0]['ends_at'] ? strtotime($subscriptions[0]['ends_at']) : null) : null,
        $company['trial_ends_at'] ? strtotime($company['trial_ends_at']) : null,
    ]);
    $expiryTs = $candidates ? max($candidates) : null;
    $expiryTitle = $expiryTs ? '' : 'Access ended';
    $expirySource = ucfirst($company['status']);
}

$expiryDays = $expiryTs !== null ? hef_days_until($expiryTs) : null;
$expiryPast = $expiryTs !== null && $expiryTs <= $now;
if ($expiryTs === null) {
    $expiryTone = in_array($company['status'], ['expired', 'cancelled'], true) ? 'danger' : 'success';
} elseif ($expiryPast || $expiryDays <= 3) {
    $expiryTone = 'danger';
} elseif ($expiryDays <= 14) {
    $expiryTone = 'warning';
} else {
    $expiryTone = 'success';
}

// ---- Plan, limits, storefront ----
$plan = hef_company_plan($pdo, $cid); // null: operator, or no active paid plan (trial / free access)
$usersUsed = hef_plan_usage($pdo, $cid, 'users');
$usersLimit = hef_plan_limit($pdo, $cid, 'users');
$batchesUsed = hef_plan_usage($pdo, $cid, 'batches');
$batchesLimit = hef_plan_limit($pdo, $cid, 'batches');
$storefrontOn = hef_company_storefront_allowed($pdo, $cid);
$storeUrl = 'https://cthkennels.com/hef/store.php?company=' . urlencode($company['slug']);

// ---- Data footprint ----
$count = function (string $sql) use ($pdo, $cid): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$cid]);

    return (int) $stmt->fetchColumn();
};
$animalsAlive = $count("SELECT COALESCE(SUM(current_count_male + current_count_female + current_count_unknown), 0) FROM batches WHERE company_id = ? AND status = 'active'");
$listingsPublished = $count("SELECT COUNT(*) FROM storefront_listings WHERE company_id = ? AND status = 'published'");
$bookingsRecent = $count("SELECT COUNT(*) FROM bookings WHERE company_id = ? AND created_at >= (NOW() - INTERVAL 30 DAY)");
$lastBatchAt = $pdo->prepare('SELECT MAX(created_at) FROM batches WHERE company_id = ?');
$lastBatchAt->execute([$cid]);
$lastBatchAt = $lastBatchAt->fetchColumn();

$footprint = [
    'Batches (active / total)' => $count("SELECT COUNT(*) FROM batches WHERE company_id = ? AND status = 'active'") . ' / ' . $count('SELECT COUNT(*) FROM batches WHERE company_id = ?'),
    'Species' => $count('SELECT COUNT(*) FROM species WHERE company_id = ?'),
    'Breeds' => $count('SELECT COUNT(*) FROM breeds WHERE company_id = ?'),
    'Rooms' => $count('SELECT COUNT(*) FROM rooms WHERE company_id = ?'),
    'Customers' => $count('SELECT COUNT(*) FROM customers WHERE company_id = ?'),
    'Suppliers' => $count('SELECT COUNT(*) FROM suppliers WHERE company_id = ?'),
    'Vaccinations recorded' => $count('SELECT COUNT(*) FROM vaccination_records vr JOIN batches b ON b.id = vr.batch_id WHERE b.company_id = ?'),
    'Mortality entries' => $count('SELECT COUNT(*) FROM mortality_log ml JOIN batches b ON b.id = ml.batch_id WHERE b.company_id = ?'),
    'Feed purchases' => $count('SELECT COUNT(*) FROM feed_purchases WHERE company_id = ?'),
    'Income / expense entries' => $count('SELECT COUNT(*) FROM income WHERE company_id = ?') . ' / ' . $count('SELECT COUNT(*) FROM expenses WHERE company_id = ?'),
    'Notes & reminders' => $count('SELECT COUNT(*) FROM user_notes WHERE company_id = ?'),
    'Storefront listings (published / total)' => $listingsPublished . ' / ' . $count('SELECT COUNT(*) FROM storefront_listings WHERE company_id = ?'),
];

$stmt = $pdo->prepare('SELECT status, COUNT(*) AS cnt FROM bookings WHERE company_id = ? GROUP BY status ORDER BY cnt DESC');
$stmt->execute([$cid]);
$bookingsByStatus = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT razorpay_key_id FROM payment_settings WHERE company_id = ?');
$stmt->execute([$cid]);
$paymentsConfigured = (string) $stmt->fetchColumn() !== '';

$stmt = $pdo->prepare(
    'SELECT al.table_name, al.action, al.created_at, u.name AS user_name
     FROM audit_logs al LEFT JOIN users u ON u.id = al.user_id
     WHERE al.company_id = ? ORDER BY al.created_at DESC, al.id DESC LIMIT 8'
);
$stmt->execute([$cid]);
$recentChanges = $stmt->fetchAll();

$statusBadge = [
    'active' => 'bg-success-subtle text-success',
    'trial' => 'bg-warning-subtle text-warning',
    'expired' => 'bg-danger-subtle text-danger',
    'cancelled' => 'bg-secondary-subtle text-secondary',
    'invited' => 'bg-warning-subtle text-warning',
    'disabled' => 'bg-secondary-subtle text-secondary',
];
?>
<a href="/hef/admin/super-companies.php" class="text-muted small"><i class="bi bi-arrow-left"></i> Back to all companies</a>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mt-2 mb-3">
    <div>
        <h4 class="mb-1">
            <?= htmlspecialchars($company['name']) ?>
            <span class="badge <?= $statusBadge[$company['status']] ?? 'bg-secondary-subtle text-secondary' ?> text-capitalize fs-6 align-middle"><?= htmlspecialchars($company['status']) ?></span>
            <?php if ($isOperator): ?><span class="badge bg-dark fs-6 align-middle">You</span><?php endif; ?>
        </h4>
        <div class="text-muted small">
            Joined <?= date('d M Y', strtotime($company['created_at'])) ?>
            · <?= htmlspecialchars($company['timezone']) ?> · <?= htmlspecialchars($company['currency_code']) ?>
            <?php if ($company['address'] || $company['pincode']): ?>
                · <i class="bi bi-geo-alt"></i> <?= htmlspecialchars(trim(($company['address'] ?? '') . ' ' . ($company['pincode'] ?? ''))) ?>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($storefrontOn): ?>
        <a href="<?= htmlspecialchars($storeUrl) ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm"><i class="bi bi-shop me-1"></i>Open public store</a>
    <?php endif; ?>
</div>

<?php if ($status): ?><div class="alert alert-success py-2"><?= htmlspecialchars($status) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if (! $isOperator && $expiryTs !== null && ($expiryPast || $expiryDays <= 7)): ?>
    <div class="alert alert-<?= $expiryPast ? 'danger' : 'warning' ?> py-2">
        <i class="bi bi-hourglass-split me-1"></i>
        <?php if ($expiryPast): ?>
            Access ended on <strong><?= date('d M Y', $expiryTs) ?></strong> (<?= hef_days_text($expiryDays, false) ?>).
        <?php else: ?>
            Access ends on <strong><?= date('d M Y', $expiryTs) ?></strong> (<?= hef_days_text($expiryDays, true) ?>).
        <?php endif; ?>
    </div>
<?php endif; ?>

<!-- Headline cards -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card p-3 h-100 border-<?= $expiryTone ?>" style="border-width:2px;">
            <div class="text-muted small text-uppercase" style="font-size:11px;">Access <?= $expiryPast ? 'ended' : 'expires' ?></div>
            <?php if ($expiryTs !== null): ?>
                <div class="fs-4 fw-bold text-<?= $expiryTone ?>"><?= date('d M Y', $expiryTs) ?></div>
                <div class="small"><?= hef_days_text($expiryDays, ! $expiryPast) ?></div>
            <?php else: ?>
                <div class="fs-4 fw-bold text-<?= $expiryTone ?>"><?= htmlspecialchars($expiryTitle) ?></div>
            <?php endif; ?>
            <div class="text-muted small mt-1"><?= htmlspecialchars($expirySource) ?></div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card p-3 h-100">
            <div class="text-muted small text-uppercase" style="font-size:11px;">Plan &amp; limits</div>
            <div class="fs-5 fw-bold"><?= $plan ? htmlspecialchars($plan['name']) : ($isOperator ? 'Platform owner' : 'No paid plan') ?></div>
            <div class="small">
                Team members <strong><?= $usersUsed ?></strong><?= $usersLimit !== null ? ' of ' . $usersLimit : '' ?>
                <?= $usersLimit === null ? '<span class="text-muted">(no limit)</span>' : '' ?><br>
                Active batches <strong><?= $batchesUsed ?></strong><?= $batchesLimit !== null ? ' of ' . $batchesLimit : '' ?>
                <?= $batchesLimit === null ? '<span class="text-muted">(no limit)</span>' : '' ?>
            </div>
            <div class="small mt-1">
                <span class="badge <?= $storefrontOn ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?>">Storefront <?= $storefrontOn ? 'on' : 'off' ?></span>
                <?php if ($plan): ?>
                    <span class="badge <?= (int) $plan['pro_features_enabled'] === 1 ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?>">Pro <?= (int) $plan['pro_features_enabled'] === 1 ? 'yes' : 'no' ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card p-3 h-100">
            <div class="text-muted small text-uppercase" style="font-size:11px;">Team</div>
            <div class="fs-4 fw-bold"><?= count($users) ?> <span class="fs-6 fw-normal text-muted">user<?= count($users) === 1 ? '' : 's' ?></span></div>
            <div class="small text-muted"><?= $userStatusCounts['active'] ?> active · <?= $userStatusCounts['invited'] ?> invited · <?= $userStatusCounts['disabled'] ?> disabled</div>
            <div class="small mt-1">Last seen: <strong><?= htmlspecialchars(hef_ago($lastActive)) ?></strong></div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card p-3 h-100">
            <div class="text-muted small text-uppercase" style="font-size:11px;">Farm</div>
            <div class="fs-4 fw-bold"><?= number_format($animalsAlive) ?> <span class="fs-6 fw-normal text-muted">animals alive</span></div>
            <div class="small text-muted"><?= $batchesUsed ?> active batch<?= $batchesUsed === 1 ? '' : 'es' ?></div>
            <div class="small mt-1">Last batch added: <strong><?= htmlspecialchars(hef_ago($lastBatchAt ?: null)) ?></strong></div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-7">
        <h6 class="mb-2">Users</h6>
        <div class="card mb-4">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" style="font-size:0.9rem;">
                    <thead class="table-light">
                        <tr><th>Name</th><th>Role</th><th>Status</th><th>Last seen</th><th>Joined</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars($u['name']) ?> <?= $u['is_super_admin'] ? '<span class="badge bg-dark">Super Admin</span>' : '' ?></div>
                                    <div class="text-muted small"><?= htmlspecialchars($u['email']) ?><?= $u['phone'] ? ' · ' . htmlspecialchars($u['phone']) : '' ?></div>
                                </td>
                                <td class="text-capitalize"><?= htmlspecialchars($u['role']) ?></td>
                                <td><span class="badge <?= $statusBadge[$u['status']] ?? 'bg-secondary-subtle text-secondary' ?> text-capitalize"><?= htmlspecialchars($u['status']) ?></span></td>
                                <td class="small text-muted"><?= htmlspecialchars(hef_ago($u['last_active'])) ?></td>
                                <td class="small text-muted"><?= $u['created_at'] ? date('d M Y', strtotime($u['created_at'])) : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($users)): ?>
                            <tr><td colspan="5" class="text-muted small">No users.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <h6 class="mb-2">Subscription history</h6>
        <div class="card">
            <div class="list-group list-group-flush">
                <?php if (empty($subscriptions)): ?>
                    <div class="list-group-item text-muted small">No subscriptions yet.</div>
                <?php endif; ?>
                <?php foreach ($subscriptions as $s): ?>
                    <?php
                        $subEnd = $s['ends_at'] ? strtotime($s['ends_at']) : null;
                        $isCurrent = $activeSub && (int) $activeSub['id'] === (int) $s['id'];
                    ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-center">
                            <span>
                                <?= htmlspecialchars($s['plan_name']) ?> — ₹<?= number_format($s['price'], 0) ?>/<?= $s['billing_cycle'] === 'monthly' ? 'mo' : 'yr' ?>
                                <?php if ($isCurrent): ?><span class="badge bg-success ms-1">Current</span><?php endif; ?>
                            </span>
                            <span class="badge <?= $statusBadge[$s['status']] ?? 'bg-secondary-subtle text-secondary' ?> text-capitalize"><?= htmlspecialchars($s['status']) ?></span>
                        </div>
                        <div class="text-muted small">
                            <?= date('d M Y', strtotime($s['starts_at'])) ?> → <?= $subEnd ? date('d M Y', $subEnd) : 'ongoing' ?>
                            <?php if ($subEnd && $isCurrent): ?> · <?= hef_days_text(hef_days_until($subEnd), true) ?><?php endif; ?>
                            <?php if ($s['razorpay_subscription_id']): ?> · <span title="Razorpay subscription id"><?= htmlspecialchars($s['razorpay_subscription_id']) ?></span><?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-5">
        <h6 class="mb-2">Admin actions</h6>
        <div class="card p-3 mb-4">
            <?php if ($isOperator): ?>
                <p class="text-muted small mb-0">This is the platform owner's own company — exempt from all billing controls.</p>
            <?php else: ?>
                <form method="POST" class="mb-3">
                    <input type="hidden" name="grant_free" value="1">
                    <button type="submit" class="btn btn-outline-success btn-sm w-100" onclick="return confirm('Grant this company free active access, bypassing billing?');">
                        Grant Free Active Access
                    </button>
                </form>

                <form method="POST" class="mb-3 d-flex gap-2">
                    <input type="hidden" name="extend_trial" value="1">
                    <input type="number" name="extend_days" class="form-control form-control-sm" value="14" style="max-width:80px;">
                    <button type="submit" class="btn btn-outline-secondary btn-sm text-nowrap">Extend Trial (days)</button>
                </form>

                <form method="POST" class="mb-3">
                    <input type="hidden" name="reactivate" value="1">
                    <button type="submit" class="btn btn-outline-primary btn-sm w-100">Reactivate</button>
                </form>

                <form method="POST" onsubmit="return confirm('Suspend this company? They will lose access until reactivated.');">
                    <input type="hidden" name="suspend" value="1">
                    <button type="submit" class="btn btn-outline-danger btn-sm w-100">Suspend Company</button>
                </form>
            <?php endif; ?>
        </div>

        <h6 class="mb-2">Company</h6>
        <div class="card mb-4">
            <ul class="list-group list-group-flush small">
                <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Store address (slug)</span><span><?= htmlspecialchars($company['slug']) ?></span></li>
                <?php if ($owner): ?>
                    <li class="list-group-item d-flex justify-content-between gap-3"><span class="text-muted">Owner</span><span class="text-end"><?= htmlspecialchars($owner['name']) ?><br><a href="mailto:<?= htmlspecialchars($owner['email']) ?>"><?= htmlspecialchars($owner['email']) ?></a><?= $owner['phone'] ? '<br>' . htmlspecialchars($owner['phone']) : '' ?></span></li>
                <?php endif; ?>
                <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Online payments (Razorpay)</span><span><?= $paymentsConfigured ? '<span class="text-success">Configured</span>' : '<span class="text-muted">Not set up</span>' ?></span></li>
                <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Public store</span><span><?= $storefrontOn ? $listingsPublished . ' published listing' . ($listingsPublished === 1 ? '' : 's') : '<span class="text-muted">Off (plan)</span>' ?></span></li>
                <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Bookings (last 30 days)</span><span><?= $bookingsRecent ?></span></li>
                <?php foreach ($bookingsByStatus as $bs): ?>
                    <li class="list-group-item d-flex justify-content-between ps-4"><span class="text-muted text-capitalize"><?= htmlspecialchars(str_replace('_', ' ', $bs['status'])) ?></span><span><?= (int) $bs['cnt'] ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>

        <h6 class="mb-2">Data in the account</h6>
        <div class="card mb-4">
            <ul class="list-group list-group-flush small">
                <?php foreach ($footprint as $label => $value): ?>
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted"><?= htmlspecialchars($label) ?></span><span class="fw-semibold"><?= htmlspecialchars((string) $value) ?></span></li>
                <?php endforeach; ?>
            </ul>
        </div>

        <h6 class="mb-2">Recent changes</h6>
        <div class="card">
            <ul class="list-group list-group-flush small">
                <?php if (empty($recentChanges)): ?>
                    <li class="list-group-item text-muted">Nothing edited or deleted yet.</li>
                <?php endif; ?>
                <?php foreach ($recentChanges as $ch): ?>
                    <li class="list-group-item d-flex justify-content-between gap-2">
                        <span><span class="text-capitalize"><?= htmlspecialchars($ch['action']) ?></span> in <?= htmlspecialchars(str_replace('_', ' ', $ch['table_name'])) ?><?= $ch['user_name'] ? ' by ' . htmlspecialchars($ch['user_name']) : '' ?></span>
                        <span class="text-muted text-nowrap"><?= htmlspecialchars(hef_ago($ch['created_at'])) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="text-muted small mt-1">Edit or delete this company from <a href="/hef/admin/super-companies.php">All Companies</a>.</div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
