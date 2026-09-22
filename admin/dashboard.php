<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/line_items.php';

$companyId = $currentUser['company_id'];

$stmt = $pdo->prepare("SELECT COUNT(*) FROM batches WHERE company_id = ? AND status = 'active'");
$stmt->execute([$companyId]);
$activeBatches = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE company_id = ? AND status IN ('pending_payment','paid','confirmed')");
$stmt->execute([$companyId]);
$pendingBookings = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM alerts_log al
     JOIN batches b ON b.id = al.reference_id AND al.reference_type = 'batch_vaccination_schedule'
     WHERE al.company_id = ? AND al.type = 'vaccination_due' AND al.status != 'failed' AND al.resolved_at IS NULL"
);
$stmt->execute([$companyId]);
$vaccinationsDue = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM income WHERE company_id = ? AND MONTH(date) = MONTH(CURDATE()) AND YEAR(date) = YEAR(CURDATE())");
$stmt->execute([$companyId]);
$monthIncome = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM income WHERE company_id = ?');
$stmt->execute([$companyId]);
$totalIncome = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE company_id = ?');
$stmt->execute([$companyId]);
$totalExpenses = (float) $stmt->fetchColumn();

$netPL = $totalIncome - $totalExpenses;

// Batch-wise current animal counts
$stmt = $pdo->prepare(
    "SELECT b.batch_code, s.name AS species_name, br.name AS breed_name,
        b.current_count_male, b.current_count_female, b.current_count_unknown
     FROM batches b
     JOIN species s ON s.id = b.species_id
     LEFT JOIN breeds br ON br.id = b.breed_id
     WHERE b.company_id = ? AND b.status = 'active'
     ORDER BY s.name, br.name, b.batch_code"
);
$stmt->execute([$companyId]);
$batchAnimalCounts = $stmt->fetchAll();

// Recent activity: last 5 mortality events across all this company's batches
$stmt = $pdo->prepare(
    "SELECT ml.date, ml.count, ml.gender, ml.cause, b.batch_code, s.name AS species_name, br.name AS breed_name
     FROM mortality_log ml
     JOIN batches b ON b.id = ml.batch_id
     JOIN species s ON s.id = b.species_id
     LEFT JOIN breeds br ON br.id = b.breed_id
     WHERE b.company_id = ?
     ORDER BY ml.created_at DESC LIMIT 5"
);
$stmt->execute([$companyId]);
$recentMortality = $stmt->fetchAll();

// Feed stock balance per species+unit (purchases minus consumption)
$stmt = $pdo->prepare(
    "SELECT s.id, s.name, fp.unit, COALESCE(SUM(fp.quantity), 0) AS purchased
     FROM species s
     LEFT JOIN feed_purchases fp ON fp.species_id = s.id
     WHERE s.company_id = ? AND fp.unit IS NOT NULL
     GROUP BY s.id, s.name, fp.unit"
);
$stmt->execute([$companyId]);
$purchasedBySpeciesUnit = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT s.id AS species_id, fc.unit, COALESCE(SUM(fc.quantity), 0) AS consumed
     FROM feed_consumption fc
     JOIN batches b ON b.id = fc.batch_id
     JOIN species s ON s.id = b.species_id
     WHERE b.company_id = ?
     GROUP BY s.id, fc.unit"
);
$stmt->execute([$companyId]);
$consumedBySpeciesUnit = [];
foreach ($stmt->fetchAll() as $row) {
    $consumedBySpeciesUnit[$row['species_id'] . '|' . $row['unit']] = (float) $row['consumed'];
}

$feedStockBalances = [];
foreach ($purchasedBySpeciesUnit as $row) {
    $key = $row['id'] . '|' . $row['unit'];
    $feedStockBalances[] = [
        'species_name' => $row['name'],
        'unit' => $row['unit'],
        'balance' => (float) $row['purchased'] - ($consumedBySpeciesUnit[$key] ?? 0),
    ];
}

// Open customer demand (Pro owners only): the same species + breed + age
// buckets as the Requirements page, biggest first, each with the cheapest
// supplier that offers exactly that species, breed and age.
$demandBuckets = [];
$supplierLines = [];
if (hef_user_can_access_pro_pages($pdo, $currentUser)) {
    $stmt = $pdo->prepare(
        "SELECT s.id AS species_id, s.name AS species_name, cs.breed_id, br.name AS breed_name, cs.age_days,
                SUM(cs.quantity) AS total_needed, COUNT(DISTINCT cs.customer_id) AS customer_count
         FROM customer_species cs
         JOIN customers c ON c.id = cs.customer_id AND c.company_id = ?
         JOIN species s ON s.id = cs.species_id AND s.company_id = ?
         LEFT JOIN breeds br ON br.id = cs.breed_id
         WHERE cs.quantity > 0
         GROUP BY s.id, s.name, cs.breed_id, br.name, cs.age_days
         ORDER BY total_needed DESC, s.name, br.name, cs.age_days"
    );
    $stmt->execute([$companyId, $companyId]);
    $demandBuckets = $stmt->fetchAll();

    if ($demandBuckets) {
        $stmt = $pdo->prepare(
            "SELECT ss.species_id, ss.breed_id, ss.age_days, sup.id AS supplier_id, sup.name, ss.rate, ss.unit
             FROM supplier_species ss
             JOIN suppliers sup ON sup.id = ss.supplier_id
             WHERE sup.company_id = ?"
        );
        $stmt->execute([$companyId]);
        $supplierLines = $stmt->fetchAll();
    }
}
$demandShownLimit = 5;

// Salary that is due soon or overdue (Owner only). Skipped quietly if the
// staff tables haven't been created yet.
$backupAge = null;      // days since the last backup, or -1 if never
if ($currentUser['role'] === 'owner') {
    try {
        $stmt = $pdo->prepare("SELECT MAX(created_at) FROM backup_events WHERE company_id = ? AND action = 'backup'");
        $stmt->execute([(int) $companyId]);
        $lastBackupAt = $stmt->fetchColumn();
        $backupAge = $lastBackupAt ? (int) floor((time() - strtotime($lastBackupAt)) / 86400) : -1;
    } catch (PDOException $e) {
        $backupAge = null; // backup_events not created yet
    }
}
$payrollDue = null;
if ($currentUser['role'] === 'owner') {
    try {
        $stmt = $pdo->prepare('SELECT timezone FROM companies WHERE id = ?');
        $stmt->execute([(int) $companyId]);
        $payrollDue = hef_payroll_due_info($pdo, (int) $companyId, $stmt->fetchColumn() ?: null);
    } catch (PDOException $e) {
        error_log('dashboard payroll skipped: ' . $e->getMessage());
    }
}

// The user's own open reminders that are due, overdue or coming up this week.
// Wrapped so the home page still loads if the notes table isn't created yet.
$myReminders = [];
$reminderToday = '';
try {
    $stmt = $pdo->prepare('SELECT timezone FROM companies WHERE id = ?');
    $stmt->execute([$companyId]);
    $reminderToday = hef_company_today($stmt->fetchColumn() ?: null);
    $link = hef_note_link_sql();
    $stmt = $pdo->prepare(
        "SELECT n.id, n.title, n.remind_on, n.link_type, n.link_id, n.is_shared, {$link['select']}
         FROM user_notes n
         {$link['joins']}
         WHERE n.status = 'open' AND n.remind_on IS NOT NULL AND n.remind_on <= ?
           AND (n.user_id = ? OR (n.is_shared = 1 AND n.company_id = ?))
         ORDER BY n.remind_on, n.id LIMIT 6"
    );
    $stmt->execute([date('Y-m-d', strtotime($reminderToday . ' +7 days')), (int) $currentUser['user_id'], (int) $companyId]);
    $myReminders = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('dashboard reminders skipped: ' . $e->getMessage());
}

require_once __DIR__ . '/header.php';
?>
<h3 class="mb-4">Welcome, <?= htmlspecialchars($currentUser['name']) ?> 👋</h3>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center">
            <div class="fs-2 fw-bold text-success"><?= $activeBatches ?></div>
            <div class="text-muted small">Active Batches</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center">
            <div class="fs-2 fw-bold text-success"><?= $pendingBookings ?></div>
            <div class="text-muted small">Pending Bookings</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center">
            <div class="fs-2 fw-bold <?= $vaccinationsDue > 0 ? 'text-danger' : 'text-success' ?>"><?= $vaccinationsDue ?></div>
            <div class="text-muted small">Vaccinations Due</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <?php if ($currentUser['role'] === 'owner'): ?>
            <div class="card p-3 text-center">
                <div class="fs-2 fw-bold <?= $netPL >= 0 ? 'text-success' : 'text-danger' ?>">₹<?= number_format($netPL, 0) ?></div>
                <div class="text-muted small">Net Profit / Loss</div>
            </div>
        <?php else: ?>
            <div class="card p-3 text-center">
                <div class="fs-2 fw-bold text-success"><?= ucfirst($currentUser['role']) ?></div>
                <div class="text-muted small">Your Role</div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($vaccinationsDue > 0): ?>
    <div class="alert alert-warning d-flex justify-content-between align-items-center">
        <span><i class="bi bi-exclamation-triangle me-2"></i><?= $vaccinationsDue ?> vaccination(s) due across your batches.</span>
        <?php if (in_array($currentUser['role'], ['owner', 'vet'], true)): ?>
            <a href="/hef/admin/health.php" class="btn btn-sm btn-outline-dark">Review</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($backupAge !== null && ($backupAge < 0 || $backupAge >= 30)): ?>
    <div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap gap-2 py-2">
        <span><i class="bi bi-cloud-arrow-down me-2"></i><?= $backupAge < 0 ? 'You haven\'t downloaded a backup of your farm data yet.' : 'Your last backup was ' . $backupAge . ' days ago.' ?></span>
        <a href="/hef/admin/backup.php" class="btn btn-sm btn-outline-dark">Back up now</a>
    </div>
<?php endif; ?>

<?php if ($payrollDue): ?>
    <div class="alert alert-<?= $payrollDue['days'] < 0 ? 'danger' : 'warning' ?> d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-cash-stack me-2"></i><?= htmlspecialchars(date('F Y', strtotime($payrollDue['month']))) ?> salary is
            <?= $payrollDue['days'] > 0 ? 'due in ' . $payrollDue['days'] . ' day' . ($payrollDue['days'] === 1 ? '' : 's') : ($payrollDue['days'] === 0 ? 'due today' : 'overdue by ' . abs($payrollDue['days']) . ' day' . (abs($payrollDue['days']) === 1 ? '' : 's')) ?>
            (<?= date('d M', strtotime($payrollDue['due'])) ?>) — <?= (int) $payrollDue['unpaid'] ?> staff not paid yet.</span>
        <a href="/hef/admin/staff-payroll.php?month=<?= substr($payrollDue['month'], 0, 7) ?>" class="btn btn-sm btn-outline-dark">Open payroll</a>
    </div>
<?php endif; ?>

<?php if (! empty($myReminders)): ?>
<div class="d-flex justify-content-between align-items-center mb-2">
    <h6 class="mb-0"><i class="bi bi-alarm me-1"></i>Reminders</h6>
    <a href="/hef/admin/notes.php" class="small">View all</a>
</div>
<div class="card mb-4">
    <div class="list-group list-group-flush">
        <?php foreach ($myReminders as $rem): ?>
            <?php
                $remDiff = (int) round((strtotime($rem['remind_on']) - strtotime($reminderToday)) / 86400);
                if ($remDiff < 0) {
                    $remLabel = 'Overdue by ' . abs($remDiff) . (abs($remDiff) === 1 ? ' day' : ' days');
                    $remClass = 'bg-danger';
                } elseif ($remDiff === 0) {
                    $remLabel = 'Today';
                    $remClass = 'bg-warning text-dark';
                } elseif ($remDiff === 1) {
                    $remLabel = 'Tomorrow';
                    $remClass = 'bg-info text-dark';
                } else {
                    $remLabel = 'In ' . $remDiff . ' days';
                    $remClass = 'bg-light text-dark border';
                }
            ?>
            <a href="/hef/admin/notes.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2">
                <span style="overflow-wrap:anywhere;">
                    <?= htmlspecialchars($rem['title']) ?>
                    <?php if ((int) $rem['is_shared'] === 1): ?><span class="text-muted small ms-1"><i class="bi bi-people"></i></span><?php endif; ?>
                    <?php $remLink = hef_note_link_info($rem, hef_user_can_access_pro_pages($pdo, $currentUser)); ?>
                    <?php if ($remLink): ?><span class="text-muted small ms-1"><i class="bi <?= $remLink['icon'] ?>"></i> <?= htmlspecialchars($remLink['label']) ?></span><?php endif; ?>
                </span>
                <span class="badge <?= $remClass ?> flex-shrink-0"><?= htmlspecialchars($remLabel) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($currentUser['role'] === 'owner'): ?>
<h6 class="mb-2">Finances overview</h6>
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center">
            <div class="fs-3 fw-bold text-success">₹<?= number_format($totalIncome, 0) ?></div>
            <div class="text-muted small">Total Income</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center">
            <div class="fs-3 fw-bold text-danger">₹<?= number_format($totalExpenses, 0) ?></div>
            <div class="text-muted small">Total Expenses</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center">
            <div class="fs-3 fw-bold <?= $netPL >= 0 ? 'text-success' : 'text-danger' ?>">₹<?= number_format($netPL, 0) ?></div>
            <div class="text-muted small">Net Profit / Loss</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center">
            <div class="fs-3 fw-bold text-success">₹<?= number_format($monthIncome, 0) ?></div>
            <div class="text-muted small">This Month's Income</div>
        </div>
    </div>
</div>
<?php endif; ?>

<h6 class="mb-2">Animals by batch</h6>
<div class="card mb-4">
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead>
                <tr><th>Batch</th><th>Species</th><th class="text-end">Male</th><th class="text-end">Female</th><th class="text-end">Unknown</th><th class="text-end">Total</th></tr>
            </thead>
            <tbody>
                <?php if (empty($batchAnimalCounts)): ?>
                    <tr><td colspan="6" class="text-muted text-center py-3">No active batches yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($batchAnimalCounts as $bc): ?>
                    <?php $total = $bc['current_count_male'] + $bc['current_count_female'] + $bc['current_count_unknown']; ?>
                    <tr>
                        <td><?= htmlspecialchars($bc['batch_code']) ?></td>
                        <td class="text-muted"><?= htmlspecialchars(hef_species_breed_label($bc['species_name'], $bc['breed_name'])) ?></td>
                        <td class="text-end"><?= (int) $bc['current_count_male'] ?></td>
                        <td class="text-end"><?= (int) $bc['current_count_female'] ?></td>
                        <td class="text-end"><?= (int) $bc['current_count_unknown'] ?></td>
                        <td class="text-end fw-semibold"><?= $total ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (! empty($demandBuckets)): ?>
<div class="d-flex justify-content-between align-items-center mb-2">
    <h6 class="mb-0">Open customer demand</h6>
    <a href="/hef/admin/requirements.php" class="small">View all requirements</a>
</div>
<div class="card mb-4">
    <div class="list-group list-group-flush">
        <?php foreach (array_slice($demandBuckets, 0, $demandShownLimit) as $d): ?>
            <?php
                $age = $d['age_days'] !== null ? (int) $d['age_days'] : null;
                $match = hef_match_suppliers($supplierLines, $d['species_id'], $d['breed_id'], $age);
                $supplierCount = count(array_unique(array_column($match['rows'], 'supplier_id')));
            ?>
            <div class="list-group-item d-flex justify-content-between align-items-center">
                <div>
                    <div class="fw-semibold"><?= htmlspecialchars(hef_line_label($d['species_name'], $d['breed_name'], $age)) ?></div>
                    <div class="small">
                        <?php if ($match['mode'] === 'exact'): ?>
                            <?php $best = $match['rows'][0]; ?>
                            <span class="text-muted"><i class="bi bi-star-fill text-warning me-1"></i>₹<?= htmlspecialchars($best['rate']) ?><?= $best['unit'] ? '/' . htmlspecialchars($best['unit']) : '' ?> · <?= htmlspecialchars($best['name']) ?></span>
                        <?php elseif ($match['mode'] === 'any'): ?>
                            <span class="text-muted"><i class="bi bi-people me-1"></i><?= $supplierCount ?> supplier<?= $supplierCount === 1 ? '' : 's' ?>, various ages</span>
                        <?php elseif ($match['mode'] === 'other'): ?>
                            <span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>None at this age — <?= $supplierCount ?> at other ages</span>
                        <?php else: ?>
                            <span class="text-danger"><i class="bi bi-exclamation-triangle me-1"></i>No supplier on file</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="text-end">
                    <span class="badge text-bg-hef" style="background:var(--hef-green);">Needed: <?= (int) $d['total_needed'] ?></span>
                    <div class="text-muted small"><?= (int) $d['customer_count'] ?> customer<?= (int) $d['customer_count'] === 1 ? '' : 's' ?></div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (count($demandBuckets) > $demandShownLimit): ?>
            <a href="/hef/admin/requirements.php" class="list-group-item list-group-item-action small text-muted text-center">
                + <?= count($demandBuckets) - $demandShownLimit ?> more
            </a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if (! empty($feedStockBalances) && $currentUser['role'] !== 'vet'): ?>
<h6 class="mb-2">Feed stock</h6>
<div class="row g-3 mb-4">
    <?php foreach ($feedStockBalances as $sb): ?>
        <div class="col-6 col-lg-3">
            <div class="card p-3 text-center">
                <div class="fs-4 fw-bold <?= $sb['balance'] <= 0 ? 'text-danger' : 'text-success' ?>">
                    <?= number_format($sb['balance'], 1) ?> <?= htmlspecialchars($sb['unit']) ?>
                </div>
                <div class="text-muted small"><?= htmlspecialchars($sb['species_name']) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card p-4">
    <h6 class="mb-3">Recent mortality</h6>
    <?php if (empty($recentMortality)): ?>
        <p class="text-muted mb-0">No mortality events recorded recently.</p>
    <?php else: ?>
        <div class="list-group list-group-flush">
            <?php foreach ($recentMortality as $m): ?>
                <div class="list-group-item px-0 d-flex justify-content-between">
                    <span><?= (int) $m['count'] ?> <?= htmlspecialchars($m['gender'] ?? '') ?> <?= htmlspecialchars(hef_species_breed_label($m['species_name'], $m['breed_name'])) ?> (<?= htmlspecialchars($m['batch_code']) ?>) — <?= htmlspecialchars(ucfirst($m['cause'])) ?></span>
                    <span class="text-muted small"><?= htmlspecialchars($m['date']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
