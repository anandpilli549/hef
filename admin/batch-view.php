<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/inventory-helpers.php';
require_once __DIR__ . '/../includes/line_items.php';

// Mortality recording is an operational task (Owner + Worker), not a
// health one — Vet is blocked here but keeps full access to the
// vaccination/health-record actions further down this same file.
$hefMortalityActions = ['record_mortality', 'edit_mortality_id', 'delete_mortality_id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $currentUser['role'] === 'vet') {
    foreach ($hefMortalityActions as $action) {
        if (isset($_POST[$action])) {
            header('Location: /hef/admin/batch-view.php?id=' . (int) ($_GET['id'] ?? 0) . '&error=access_denied');
            exit;
        }
    }
}

$companyId = $currentUser['company_id'];
$batchId = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT b.*, s.name AS species_name, r.name AS room_name
     FROM batches b
     JOIN species s ON s.id = b.species_id
     LEFT JOIN rooms r ON r.id = b.room_id
     WHERE b.id = ? AND b.company_id = ?'
);
$stmt->execute([$batchId, $companyId]);
$batch = $stmt->fetch();

if (! $batch) {
    header('Location: /hef/admin/batches.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_mortality'])) {
    $count = (int) ($_POST['count'] ?? 0);
    $gender = $_POST['gender'] ?: null;
    $cause = $_POST['cause'] ?? 'unknown';
    $notes = trim($_POST['notes'] ?? '');
    $date = $_POST['date'] ?? date('Y-m-d');

    $genderField = match ($gender) {
        'male' => 'current_count_male',
        'female' => 'current_count_female',
        default => 'current_count_unknown',
    };
    $currentAvailable = $batch[$genderField];

    if ($count < 1 || $count > $currentAvailable) {
        $error = "Count must be between 1 and {$currentAvailable} for the selected gender.";
    } else {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO mortality_log (batch_id, date, count, gender, cause, notes, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
            );
            $stmt->execute([$batchId, $date, $count, $gender, $cause, $notes ?: null]);

            $pdo->prepare("UPDATE batches SET {$genderField} = {$genderField} - ? WHERE id = ?")
                ->execute([$count, $batchId]);

            $pdo->commit();
            hef_reconcile_batch_inventory($pdo, $batchId);
            header('Location: /hef/admin/batch-view.php?id=' . $batchId);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Could not record mortality. Please try again.';
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_mortality_id'])) {
    $logId = (int) $_POST['delete_mortality_id'];
    $stmt = $pdo->prepare('SELECT * FROM mortality_log WHERE id = ? AND batch_id = ?');
    $stmt->execute([$logId, $batchId]);
    $log = $stmt->fetch();

    if ($log) {
        $field = match ($log['gender']) {
            'male' => 'current_count_male',
            'female' => 'current_count_female',
            default => 'current_count_unknown',
        };
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE batches SET {$field} = {$field} + ? WHERE id = ?")->execute([$log['count'], $batchId]);
            $pdo->prepare('DELETE FROM mortality_log WHERE id = ?')->execute([$logId]);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('mortality delete failed: ' . $e->getMessage());
        }
    }
    header('Location: /hef/admin/batch-view.php?id=' . $batchId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_mortality_id'])) {
    $logId = (int) $_POST['edit_mortality_id'];
    $newCount = (int) ($_POST['count'] ?? 0);
    $newGender = $_POST['gender'] ?: null;
    $newCause = $_POST['cause'] ?? 'unknown';
    $newNotes = trim($_POST['notes'] ?? '');
    $newDate = $_POST['date'] ?? date('Y-m-d');

    $stmt = $pdo->prepare('SELECT * FROM mortality_log WHERE id = ? AND batch_id = ?');
    $stmt->execute([$logId, $batchId]);
    $oldLog = $stmt->fetch();

    if (! $oldLog || $newCount < 1) {
        $error = 'Invalid mortality entry.';
    } else {
        $genderField = fn ($g) => match ($g) {
            'male' => 'current_count_male',
            'female' => 'current_count_female',
            default => 'current_count_unknown',
        };
        $oldField = $genderField($oldLog['gender']);
        $newField = $genderField($newGender);

        $pdo->beginTransaction();
        try {
            // Revert the old entry's effect first
            $pdo->prepare("UPDATE batches SET {$oldField} = {$oldField} + ? WHERE id = ?")
                ->execute([$oldLog['count'], $batchId]);

            // Check enough are "available" to apply the new entry
            $stmt = $pdo->prepare("SELECT {$newField} AS avail FROM batches WHERE id = ?");
            $stmt->execute([$batchId]);
            $available = (int) $stmt->fetch()['avail'];

            if ($newCount > $available) {
                $pdo->rollBack();
                $error = "Only {$available} available for the selected gender after adjustment.";
            } else {
                $pdo->prepare("UPDATE batches SET {$newField} = {$newField} - ? WHERE id = ?")
                    ->execute([$newCount, $batchId]);

                $pdo->prepare(
                    'UPDATE mortality_log SET date=?, count=?, gender=?, cause=?, notes=?, updated_at=NOW() WHERE id = ?'
                )->execute([$newDate, $newCount, $newGender, $newCause, $newNotes ?: null, $logId]);

                $pdo->commit();
                hef_reconcile_batch_inventory($pdo, $batchId);
                header('Location: /hef/admin/batch-view.php?id=' . $batchId);
                exit;
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('mortality edit failed: ' . $e->getMessage());
            $error = 'Could not update mortality entry. Please try again.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_vaccination_id'])) {
    $recordId = (int) $_POST['edit_vaccination_id'];
    $dateGiven = $_POST['date_given'] ?: date('Y-m-d');
    $cost = isset($_POST['vacc_cost']) && $_POST['vacc_cost'] !== '' ? (float) $_POST['vacc_cost'] : null;

    try {
        $pdo->prepare(
            'UPDATE vaccination_records SET date_given=?, cost=?, updated_at=NOW() WHERE id = ? AND batch_id = ?'
        )->execute([$dateGiven, $cost, $recordId, $batchId]);
        header('Location: /hef/admin/batch-view.php?id=' . $batchId);
        exit;
    } catch (PDOException $e) {
        error_log('vaccination_records update failed: ' . $e->getMessage());
        $error = 'Could not update vaccination record. Please try again.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_health_id'])) {
    $recordId = (int) $_POST['edit_health_id'];
    try {
        $pdo->prepare(
            'UPDATE health_records SET date=?, symptoms=?, treatment=?, medicine_cost=?, notes=?, updated_at=NOW() WHERE id = ? AND batch_id = ?'
        )->execute([
            $_POST['health_date'] ?: date('Y-m-d'),
            trim($_POST['symptoms'] ?? '') ?: null,
            trim($_POST['treatment'] ?? '') ?: null,
            $_POST['medicine_cost'] !== '' ? (float) $_POST['medicine_cost'] : null,
            trim($_POST['health_notes'] ?? '') ?: null,
            $recordId, $batchId,
        ]);
        header('Location: /hef/admin/batch-view.php?id=' . $batchId);
        exit;
    } catch (PDOException $e) {
        error_log('health_records update failed: ' . $e->getMessage());
        $error = 'Could not update health record. Please try again.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_vaccination_id'])) {
    $recordId = (int) $_POST['delete_vaccination_id'];
    $pdo->prepare('DELETE FROM vaccination_records WHERE id = ? AND batch_id = ?')->execute([$recordId, $batchId]);
    header('Location: /hef/admin/batch-view.php?id=' . $batchId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_health_id'])) {
    $recordId = (int) $_POST['delete_health_id'];
    $pdo->prepare('DELETE FROM health_records WHERE id = ? AND batch_id = ?')->execute([$recordId, $batchId]);
    header('Location: /hef/admin/batch-view.php?id=' . $batchId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['give_vaccination'])) {
    try {
        $scheduleId = (int) $_POST['vaccination_schedule_id'];
        $dateGiven = $_POST['date_given'] ?: date('Y-m-d');
        $cost = isset($_POST['vacc_cost']) && $_POST['vacc_cost'] !== '' ? (float) $_POST['vacc_cost'] : null;

        $stmt = $pdo->prepare(
            'INSERT INTO vaccination_records (batch_id, vaccination_schedule_id, date_given, cost, given_by_user_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([$batchId, $scheduleId, $dateGiven, $cost, $currentUser['user_id']]);

        $pdo->prepare(
            "UPDATE alerts_log SET resolved_at = NOW() WHERE type = 'vaccination_due' AND reference_type = 'batch_vaccination_schedule' AND reference_id = ? AND resolved_at IS NULL"
        )->execute([$batchId]);

        header('Location: /hef/admin/batch-view.php?id=' . $batchId);
        exit;
    } catch (PDOException $e) {
        error_log('vaccination_records insert failed: ' . $e->getMessage());
        $error = 'Could not record vaccination. Please try again.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_health_record'])) {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO health_records (batch_id, date, symptoms, treatment, medicine_cost, vet_user_id, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $stmt->execute([
            $batchId,
            $_POST['health_date'] ?: date('Y-m-d'),
            trim($_POST['symptoms'] ?? '') ?: null,
            trim($_POST['treatment'] ?? '') ?: null,
            $_POST['medicine_cost'] !== '' ? (float) $_POST['medicine_cost'] : null,
            $currentUser['user_id'],
            trim($_POST['health_notes'] ?? '') ?: null,
        ]);
        header('Location: /hef/admin/batch-view.php?id=' . $batchId);
        exit;
    } catch (PDOException $e) {
        error_log('health_records insert failed: ' . $e->getMessage());
        $error = 'Could not save health record. Please try again.';
    }
}

require_once __DIR__ . '/header.php';

// Refresh batch after any update
$stmt = $pdo->prepare('SELECT b.*, s.name AS species_name, br.name AS breed_name, r.name AS room_name FROM batches b JOIN species s ON s.id=b.species_id LEFT JOIN breeds br ON br.id=b.breed_id LEFT JOIN rooms r ON r.id=b.room_id WHERE b.id = ?');
$stmt->execute([$batchId]);
$batch = $stmt->fetch();

$mortPerPage = 10;
$mpage = hef_current_page('mpage');
$stmt = $pdo->prepare('SELECT COUNT(*) FROM mortality_log WHERE batch_id = ?');
$stmt->execute([$batchId]);
$totalMortality = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT * FROM mortality_log WHERE batch_id = ? ORDER BY date DESC, id DESC LIMIT ' . $mortPerPage . ' OFFSET ' . hef_offset($mpage, $mortPerPage));
$stmt->execute([$batchId]);
$mortalityLogs = $stmt->fetchAll();

$totalCurrent = $batch['current_count_male'] + $batch['current_count_female'] + $batch['current_count_unknown'];
$totalInitial = $batch['initial_count_male'] + $batch['initial_count_female'] + $batch['initial_count_unknown'];

// Vaccination schedules for this species, with given-status
$stmt = $pdo->prepare(
    "SELECT vs.*, vr.id AS vaccination_record_id, vr.date_given, vr.cost AS given_cost
     FROM vaccination_schedules vs
     LEFT JOIN vaccination_records vr ON vr.vaccination_schedule_id = vs.id AND vr.batch_id = ?
     WHERE vs.species_id = ?
     ORDER BY vs.age_in_days"
);
$stmt->execute([$batchId, $batch['species_id']]);
$vaccineSchedules = $stmt->fetchAll();

$batchAgeDays = (int) floor((strtotime('now') - strtotime($batch['date_acquired'])) / 86400) + (int) $batch['age_at_acquisition_days'];

$healthPerPage = 10;
$hpage = hef_current_page('hpage');
$stmt = $pdo->prepare('SELECT COUNT(*) FROM health_records WHERE batch_id = ?');
$stmt->execute([$batchId]);
$totalHealthRecords = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT * FROM health_records WHERE batch_id = ? ORDER BY date DESC, id DESC LIMIT ' . $healthPerPage . ' OFFSET ' . hef_offset($hpage, $healthPerPage));
$stmt->execute([$batchId]);
$healthRecords = $stmt->fetchAll();

?>
<a href="/hef/admin/batches.php" class="text-muted small"><i class="bi bi-arrow-left"></i> Back to batches</a>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mt-2 mb-4">
    <div>
        <h4 class="mb-0"><?= htmlspecialchars($batch['batch_code']) ?></h4>
        <div class="text-muted"><?= htmlspecialchars(hef_species_breed_label($batch['species_name'], $batch['breed_name'])) ?> · <?= htmlspecialchars($batch['room_name'] ?? 'No room') ?></div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="/hef/admin/notes.php?link=batch:<?= (int) $batchId ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-alarm me-1"></i>Remind me</a>
        <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#mortalityModal">
            <i class="bi bi-exclamation-triangle me-1"></i>Record mortality
        </button>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center">
            <div class="fs-3 fw-bold"><?= $totalCurrent ?></div>
            <div class="text-muted small">Alive now (of <?= $totalInitial ?>)</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center">
            <div class="fs-3 fw-bold">♂ <?= $batch['current_count_male'] ?></div>
            <div class="text-muted small">Male</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center">
            <div class="fs-3 fw-bold">♀ <?= $batch['current_count_female'] ?></div>
            <div class="text-muted small">Female</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center">
            <div class="fs-3 fw-bold"><?= htmlspecialchars($batch['date_acquired']) ?></div>
            <div class="text-muted small">Acquired<?= (int) $batch['age_at_acquisition_days'] > 0 ? ' (age then: ' . (int) $batch['age_at_acquisition_days'] . 'd)' : '' ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center">
            <div class="fs-3 fw-bold text-success"><?= $batchAgeDays ?> days</div>
            <div class="text-muted small">Current age</div>
        </div>
    </div>
</div>

<?php
    $perfRows = hef_batch_performance($pdo, (int) $currentUser['company_id'], hef_company_today_for($pdo, (int) $currentUser['company_id']), (int) $batchId);
    $perf = $perfRows[(int) $batchId] ?? null;
    $sellBadge = $perf ? hef_sell_by_badge($perf) : null;
?>
<?php if ($perf): ?>
<h6 class="mb-2">Performance</h6>
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center">
            <div class="fs-3 fw-bold"><?= $perf['mortality_pct'] !== null ? $perf['mortality_pct'] . '%' : '—' ?></div>
            <div class="text-muted small">Mortality (<?= $perf['dead'] ?> of <?= $perf['initial'] ?>)</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center">
            <div class="fs-3 fw-bold"><?= $perf['sold'] ?></div>
            <div class="text-muted small">Sold</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center">
            <div class="fs-3 fw-bold"><?= $perf['age_days'] ?> d</div>
            <div class="text-muted small">Age today</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center">
            <?php if ($perf['sell_by'] !== null): ?>
                <div class="fs-5 fw-bold"><?= date('d M Y', strtotime($perf['sell_by'])) ?></div>
                <div class="small">
                    Sell by
                    <?php if ($sellBadge): ?><span class="badge <?= $sellBadge[1] ?>"><?= $perf['days_left'] < 0 ? abs($perf['days_left']) . ' d late' : $perf['days_left'] . ' d left' ?></span><?php endif; ?>
                </div>
            <?php else: ?>
                <div class="fs-5 fw-bold">—</div>
                <div class="text-muted small">Sell by (set "max days to sell" on the species)</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($currentUser['role'] === 'owner'): ?>
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center">
            <div class="fs-4 fw-bold text-danger">₹<?= number_format($perf['total_cost'], 0) ?></div>
            <div class="text-muted small">Total cost</div>
            <div class="text-muted" style="font-size:11px;">Purchase &amp; other ₹<?= number_format($perf['other_costs'], 0) ?> · Feed ₹<?= number_format($perf['feed_cost'], 0) ?> · Health ₹<?= number_format($perf['health_cost'], 0) ?></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center">
            <div class="fs-4 fw-bold text-success">₹<?= number_format($perf['revenue'], 0) ?></div>
            <div class="text-muted small">Revenue</div>
            <?php if ($perf['revenue_per_sold'] !== null): ?><div class="text-muted" style="font-size:11px;">₹<?= number_format($perf['revenue_per_sold'], 0) ?> per animal sold</div><?php endif; ?>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center">
            <div class="fs-4 fw-bold <?= $perf['profit'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= $perf['profit'] < 0 ? '−' : '' ?>₹<?= number_format(abs($perf['profit']), 0) ?></div>
            <div class="text-muted small">Profit / loss so far</div>
            <?php if ($perf['profit_per_animal'] !== null): ?><div class="text-muted" style="font-size:11px;"><?= $perf['profit_per_animal'] < 0 ? '−' : '' ?>₹<?= number_format(abs($perf['profit_per_animal']), 0) ?> per animal raised</div><?php endif; ?>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card p-3 text-center">
            <div class="fs-4 fw-bold"><?= $perf['cost_per_animal'] !== null ? '₹' . number_format($perf['cost_per_animal'], 0) : '—' ?></div>
            <div class="text-muted small">Cost per animal raised</div>
            <?php if ($perf['feed_cost_per_animal'] !== null): ?><div class="text-muted" style="font-size:11px;">of which feed ₹<?= number_format($perf['feed_cost_per_animal'], 1) ?></div><?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<h6 class="mb-2">Mortality history</h6>
<div class="card">
    <div class="list-group list-group-flush">
        <?php if (empty($mortalityLogs)): ?>
            <div class="list-group-item text-muted">No deaths recorded — long may it stay that way.</div>
        <?php endif; ?>
        <?php foreach ($mortalityLogs as $log): ?>
            <div class="list-group-item">
                <div class="d-flex justify-content-between">
                    <span><strong><?= (int) $log['count'] ?></strong> <?= htmlspecialchars($log['gender'] ?? 'unknown') ?> — <?= htmlspecialchars(ucfirst($log['cause'])) ?></span>
                    <div class="d-flex align-items-center gap-2">
                        <span class="text-muted small"><?= htmlspecialchars($log['date']) ?></span>
                        <button class="btn btn-sm btn-outline-secondary py-0 px-1" data-bs-toggle="modal" data-bs-target="#editMortality<?= $log['id'] ?>"><i class="bi bi-pencil"></i></button>
                        <form method="POST" onsubmit="return confirm('Delete this mortality entry? This will add the count back to the batch.');" class="d-inline">
                            <input type="hidden" name="delete_mortality_id" value="<?= $log['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                </div>
                <?php if ($log['notes']): ?><div class="text-muted small mt-1"><?= htmlspecialchars($log['notes']) ?></div><?php endif; ?>
            </div>

            <div class="modal fade" id="editMortality<?= $log['id'] ?>" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <form method="POST">
                            <input type="hidden" name="edit_mortality_id" value="<?= $log['id'] ?>">
                            <div class="modal-header">
                                <h5 class="modal-title">Edit mortality entry</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                                <div class="mb-2">
                                    <label class="form-label small">Gender</label>
                                    <select name="gender" class="form-select">
                                        <?php foreach (['male', 'female', 'unknown'] as $g): ?>
                                            <option value="<?= $g ?>" <?= $g === $log['gender'] ? 'selected' : '' ?>><?= ucfirst($g) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small">Count</label>
                                    <input type="number" name="count" class="form-control" min="1" required value="<?= (int) $log['count'] ?>">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small">Cause</label>
                                    <select name="cause" class="form-select">
                                        <?php foreach (['disease', 'predator', 'weather', 'unknown', 'other'] as $cs): ?>
                                            <option value="<?= $cs ?>" <?= $cs === $log['cause'] ? 'selected' : '' ?>><?= ucfirst($cs) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small">Date</label>
                                    <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($log['date']) ?>">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small">Notes</label>
                                    <textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars($log['notes'] ?? '') ?></textarea>
                                </div>
                                <div class="alert alert-info small py-2 mb-0">Changing the count or gender automatically adjusts the batch's live totals.</div>
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
</div>
<?= hef_pagination_links($mpage, $totalMortality, $mortPerPage, 'mpage') ?>

<div class="row g-3 mt-1">
    <div class="col-12 col-lg-6">
        <h6 class="mb-2">Vaccinations</h6>
        <div class="card">
            <div class="list-group list-group-flush">
                <?php if (empty($vaccineSchedules)): ?>
                    <div class="list-group-item text-muted small">No vaccination schedule defined for this species yet. <a href="/hef/admin/health.php">Add one</a>.</div>
                <?php endif; ?>
                <?php foreach ($vaccineSchedules as $vs): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-semibold"><?= htmlspecialchars($vs['name']) ?></div>
                            <div class="text-muted small">Due at <?= (int) $vs['age_in_days'] ?> days</div>
                        </div>
                        <?php if ($vs['date_given']): ?>
                            <span class="badge bg-success-subtle text-success">Given <?= htmlspecialchars($vs['date_given']) ?></span>
                            <button class="btn btn-sm btn-outline-secondary py-0 px-1 ms-1" data-bs-toggle="modal" data-bs-target="#editVacc<?= $vs['id'] ?>"><i class="bi bi-pencil"></i></button>
                            <form method="POST" onsubmit="return confirm('Delete this vaccination record?');" class="d-inline">
                                <input type="hidden" name="delete_vaccination_id" value="<?= $vs['vaccination_record_id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1"><i class="bi bi-trash"></i></button>
                            </form>
                            <div class="modal fade" id="editVacc<?= $vs['id'] ?>" tabindex="-1">
                                <div class="modal-dialog modal-dialog-centered">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <input type="hidden" name="edit_vaccination_id" value="<?= $vs['vaccination_record_id'] ?>">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Edit vaccination record</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="mb-2">
                                                    <label class="form-label small">Date given</label>
                                                    <input type="date" name="date_given" class="form-control" value="<?= htmlspecialchars($vs['date_given']) ?>">
                                                </div>
                                                <div class="mb-2">
                                                    <label class="form-label small">Cost (₹)</label>
                                                    <input type="number" step="0.01" name="vacc_cost" class="form-control" value="<?= htmlspecialchars((string) ($vs['given_cost'] ?? '')) ?>">
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="submit" class="btn btn-hef text-white w-100">Save changes</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php elseif ($batchAgeDays >= $vs['age_in_days']): ?>
                            <form method="POST">
                                <input type="hidden" name="give_vaccination" value="1">
                                <input type="hidden" name="vaccination_schedule_id" value="<?= $vs['id'] ?>">
                                <input type="hidden" name="date_given" value="<?= date('Y-m-d') ?>">
                                <button type="submit" class="btn btn-sm btn-hef text-white">Mark given</button>
                            </form>
                        <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary">Not due yet</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-6">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0">Health records</h6>
            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#healthModal">
                <i class="bi bi-plus-lg"></i> Add
            </button>
        </div>
        <div class="card">
            <div class="list-group list-group-flush">
                <?php if (empty($healthRecords)): ?>
                    <div class="list-group-item text-muted small">No health issues logged.</div>
                <?php endif; ?>
                <?php foreach ($healthRecords as $h): ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between">
                            <span class="fw-semibold"><?= htmlspecialchars($h['symptoms'] ?: 'Health note') ?></span>
                            <div class="d-flex align-items-center gap-2">
                                <span class="text-muted small"><?= htmlspecialchars($h['date']) ?></span>
                                <button class="btn btn-sm btn-outline-secondary py-0 px-1" data-bs-toggle="modal" data-bs-target="#editHealth<?= $h['id'] ?>"><i class="bi bi-pencil"></i></button>
                                <form method="POST" onsubmit="return confirm('Delete this health record?');" class="d-inline">
                                    <input type="hidden" name="delete_health_id" value="<?= $h['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1"><i class="bi bi-trash"></i></button>
                                </form>
                            </div>
                        </div>
                        <?php if ($h['treatment']): ?><div class="text-muted small">Treatment: <?= htmlspecialchars($h['treatment']) ?></div><?php endif; ?>
                    </div>

                    <div class="modal fade" id="editHealth<?= $h['id'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <form method="POST">
                                    <input type="hidden" name="edit_health_id" value="<?= $h['id'] ?>">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Edit health record</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="mb-2">
                                            <label class="form-label small">Date</label>
                                            <input type="date" name="health_date" class="form-control" value="<?= htmlspecialchars($h['date']) ?>">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small">Symptoms</label>
                                            <input type="text" name="symptoms" class="form-control" value="<?= htmlspecialchars($h['symptoms'] ?? '') ?>">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small">Treatment</label>
                                            <input type="text" name="treatment" class="form-control" value="<?= htmlspecialchars($h['treatment'] ?? '') ?>">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small">Medicine cost (₹)</label>
                                            <input type="number" step="0.01" name="medicine_cost" class="form-control" value="<?= htmlspecialchars((string) ($h['medicine_cost'] ?? '')) ?>">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small">Notes</label>
                                            <textarea name="health_notes" class="form-control" rows="2"><?= htmlspecialchars($h['notes'] ?? '') ?></textarea>
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
        </div>
        <?= hef_pagination_links($hpage, $totalHealthRecords, $healthPerPage, 'hpage') ?>
    </div>
</div>

<!-- Add Health Record Modal -->
<div class="modal fade" id="healthModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="add_health_record" value="1">
                <div class="modal-header">
                    <h5 class="modal-title">Add health record</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label small">Date</label>
                        <input type="date" name="health_date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Symptoms</label>
                        <input type="text" name="symptoms" class="form-control">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Treatment</label>
                        <input type="text" name="treatment" class="form-control">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Medicine cost (₹)</label>
                        <input type="number" step="0.01" name="medicine_cost" class="form-control">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Notes</label>
                        <textarea name="health_notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-hef text-white w-100">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Mortality Modal -->
<div class="modal fade" id="mortalityModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="record_mortality" value="1">
                <div class="modal-header">
                    <h5 class="modal-title">Record mortality</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                    <div class="mb-2">
                        <label class="form-label small">Gender</label>
                        <select name="gender" class="form-select">
                            <option value="male">Male (<?= $batch['current_count_male'] ?> alive)</option>
                            <option value="female">Female (<?= $batch['current_count_female'] ?> alive)</option>
                            <option value="unknown">Unknown (<?= $batch['current_count_unknown'] ?> alive)</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Count</label>
                        <input type="number" name="count" class="form-control" min="1" required value="1">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Cause</label>
                        <select name="cause" class="form-select">
                            <option value="disease">Disease</option>
                            <option value="predator">Predator</option>
                            <option value="weather">Weather</option>
                            <option value="unknown">Unknown</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Date</label>
                        <input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-outline-danger w-100">Record</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
