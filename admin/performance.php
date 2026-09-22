<?php
require_once __DIR__ . '/bootstrap.php';

// Batch performance: mortality, sales and sell-by dates for everyone; costs,
// revenue and profit for the Owner only. See includes/batch_stats.php for
// exactly how each figure is worked out.

$companyId = (int) $currentUser['company_id'];
$isOwner = $currentUser['role'] === 'owner';
$today = hef_company_today_for($pdo, $companyId);

$statusOptions = ['all' => 'All batches', 'active' => 'Active', 'sold_out' => 'Sold out', 'closed' => 'Closed'];
$show = (string) ($_GET['show'] ?? 'all');
if (! isset($statusOptions[$show])) {
    $show = 'all';
}

$rows = array_values(array_filter(
    hef_batch_performance($pdo, $companyId, $today),
    function ($p) use ($show) {
        return $show === 'all' || $p['status'] === $show;
    }
));

// Summary of the batches shown.
$totalRaised = 0;
$totalDead = 0;
$pastSellBy = 0;
$activeCount = 0;
$totalProfit = 0.0;
foreach ($rows as $p) {
    $totalRaised += $p['initial'];
    $totalDead += $p['dead'];
    $totalProfit += $p['profit'];
    if ($p['status'] === 'active') {
        $activeCount++;
        if ($p['alive'] > 0 && $p['days_left'] !== null && $p['days_left'] < 0) {
            $pastSellBy++;
        }
    }
}
$overallMortality = $totalRaised > 0 ? round($totalDead / $totalRaised * 100, 1) : null;

require_once __DIR__ . '/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-graph-up-arrow me-2"></i>Batch performance</h4>
    <div class="d-flex gap-2 align-items-center">
        <a href="/hef/admin/export.php?type=performance&amp;show=<?= urlencode($show) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-download me-1"></i>Export CSV</a>
        <select class="form-select form-select-sm" style="width:auto;" onchange="window.location.href = '?show=' + this.value">
            <?php foreach ($statusOptions as $key => $label): ?>
                <option value="<?= $key ?>" <?= $key === $show ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center">
            <div class="fs-3 fw-bold"><?= count($rows) ?></div>
            <div class="text-muted small">Batches shown<?= $activeCount ? ' (' . $activeCount . ' active)' : '' ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center">
            <div class="fs-3 fw-bold"><?= $overallMortality !== null ? $overallMortality . '%' : '—' ?></div>
            <div class="text-muted small">Overall mortality (<?= $totalDead ?> of <?= $totalRaised ?>)</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center">
            <div class="fs-3 fw-bold <?= $pastSellBy > 0 ? 'text-danger' : '' ?>"><?= $pastSellBy ?></div>
            <div class="text-muted small">Active batches past sell-by</div>
        </div>
    </div>
    <?php if ($isOwner): ?>
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center">
            <div class="fs-3 fw-bold <?= $totalProfit >= 0 ? 'text-success' : 'text-danger' ?>"><?= $totalProfit < 0 ? '−' : '' ?>₹<?= number_format(abs($totalProfit), 0) ?></div>
            <div class="text-muted small">Profit / loss (batches shown)</div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if (empty($rows)): ?>
    <div class="card p-4 text-muted">No batches to show.</div>
<?php else: ?>
<div class="card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" style="font-size:0.9rem;">
            <thead class="table-light">
                <tr>
                    <th>Batch</th>
                    <th>Status</th>
                    <th class="text-end">Age</th>
                    <th>Sell by</th>
                    <th class="text-end">Raised</th>
                    <th class="text-end">Dead</th>
                    <th class="text-end">Mortality</th>
                    <th class="text-end">Sold</th>
                    <th class="text-end">Alive</th>
                    <?php if ($isOwner): ?>
                        <th class="text-end">Cost</th>
                        <th class="text-end">Revenue</th>
                        <th class="text-end">Profit</th>
                        <th class="text-end">Cost / animal</th>
                        <th class="text-end">Profit / animal</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $p): ?>
                    <?php $sellBadge = hef_sell_by_badge($p); ?>
                    <tr>
                        <td>
                            <a href="/hef/admin/batch-view.php?id=<?= $p['id'] ?>" class="fw-semibold text-decoration-none"><?= htmlspecialchars($p['batch_code']) ?></a>
                            <div class="text-muted small"><?= htmlspecialchars(hef_species_breed_label($p['species_name'], $p['breed_name'])) ?></div>
                        </td>
                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $p['status']))) ?></span></td>
                        <td class="text-end"><?= $p['status'] === 'active' ? $p['age_days'] . ' d' : '—' ?></td>
                        <td>
                            <?php if ($sellBadge): ?>
                                <span class="badge <?= $sellBadge[1] ?>"><?= htmlspecialchars($sellBadge[0]) ?></span>
                            <?php elseif ($p['sell_by'] !== null): ?>
                                <span class="text-muted"><?= date('d M Y', strtotime($p['sell_by'])) ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= $p['initial'] ?></td>
                        <td class="text-end"><?= $p['dead'] ?></td>
                        <td class="text-end">
                            <?php if ($p['mortality_pct'] !== null): ?>
                                <span class="badge <?= hef_mortality_class($p['mortality_pct']) ?>"><?= $p['mortality_pct'] ?>%</span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="text-end"><?= $p['sold'] ?></td>
                        <td class="text-end"><?= $p['alive'] ?></td>
                        <?php if ($isOwner): ?>
                            <td class="text-end">₹<?= number_format($p['total_cost'], 0) ?></td>
                            <td class="text-end">₹<?= number_format($p['revenue'], 0) ?></td>
                            <td class="text-end fw-semibold <?= $p['profit'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= $p['profit'] < 0 ? '−' : '' ?>₹<?= number_format(abs($p['profit']), 0) ?></td>
                            <td class="text-end"><?= $p['cost_per_animal'] !== null ? '₹' . number_format($p['cost_per_animal'], 0) : '—' ?></td>
                            <td class="text-end"><?= $p['profit_per_animal'] !== null ? ($p['profit_per_animal'] < 0 ? '−' : '') . '₹' . number_format(abs($p['profit_per_animal']), 0) : '—' ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<p class="text-muted small mt-3 mb-0">
    Mortality is animals recorded dead out of the number the batch started with (red from 10%, amber from 5%).
    Sell by comes from each species' "max days to sell".
    <?php if ($isOwner): ?>Cost adds the batch's expenses (including its purchase cost), feed, vaccination and medicine costs recorded against it, so log each cost in one place only.<?php endif; ?>
</p>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
