<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/permissions.php';
// Vaccination schedules are the vet's domain, so Owner and Vet can both manage them.
hef_require_role(['owner', 'vet'], $currentUser['role']);

$companyId = $currentUser['company_id'];
$error = '';

$stmt = $pdo->prepare('SELECT id, name FROM species WHERE company_id = ? ORDER BY name');
$stmt->execute([$companyId]);
$speciesOptions = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_schedule_id'])) {
    $id = (int) $_POST['delete_schedule_id'];
    $check = $pdo->prepare('SELECT vs.id FROM vaccination_schedules vs JOIN species s ON s.id = vs.species_id WHERE vs.id = ? AND s.company_id = ?');
    $check->execute([$id, $companyId]);
    if ($check->fetch()) {
        $pdo->prepare('DELETE FROM vaccination_schedules WHERE id = ?')->execute([$id]);
    }
    header('Location: /hef/admin/health.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_schedule_id'])) {
    $id = (int) $_POST['edit_schedule_id'];
    $speciesId = (int) ($_POST['species_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $ageInDays = (int) ($_POST['age_in_days'] ?? 0);
    $isRecurring = isset($_POST['is_recurring']) ? 1 : 0;
    $interval = $isRecurring && $_POST['recurrence_interval_days'] !== '' ? (int) $_POST['recurrence_interval_days'] : null;

    if (! $speciesId || ! $name || $ageInDays < 0) {
        $error = 'Species, vaccine name, and age (days) are required.';
    } else {
        try {
            // Confirm this schedule belongs to a species owned by this company
            $check = $pdo->prepare('SELECT vs.id FROM vaccination_schedules vs JOIN species s ON s.id = vs.species_id WHERE vs.id = ? AND s.company_id = ?');
            $check->execute([$id, $companyId]);
            if (! $check->fetch()) {
                $error = 'Schedule not found.';
            } else {
                $pdo->prepare(
                    'UPDATE vaccination_schedules SET species_id=?, name=?, age_in_days=?, is_recurring=?, recurrence_interval_days=?, updated_at=NOW()
                     WHERE id = ?'
                )->execute([$speciesId, $name, $ageInDays, $isRecurring, $interval, $id]);
                header('Location: /hef/admin/health.php');
                exit;
            }
        } catch (PDOException $e) {
            error_log('vaccination_schedules update failed: ' . $e->getMessage());
            $error = 'Could not update schedule. Please try again.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $speciesId = (int) ($_POST['species_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $ageInDays = (int) ($_POST['age_in_days'] ?? 0);
    $isRecurring = isset($_POST['is_recurring']) ? 1 : 0;
    $interval = $isRecurring && $_POST['recurrence_interval_days'] !== '' ? (int) $_POST['recurrence_interval_days'] : null;

    if (! $speciesId || ! $name || $ageInDays < 0) {
        $error = 'Species, vaccine name, and age (days) are required.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO vaccination_schedules (species_id, name, age_in_days, is_recurring, recurrence_interval_days, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
            );
            $stmt->execute([$speciesId, $name, $ageInDays, $isRecurring, $interval]);
            header('Location: /hef/admin/health.php');
            exit;
        } catch (PDOException $e) {
            error_log('vaccination_schedules insert failed: ' . $e->getMessage());
            $error = 'Could not save schedule. Please check the values and try again.';
        }
    }
}

require_once __DIR__ . '/header.php';

$perPage = 10;
$page = hef_current_page();
$stmt = $pdo->prepare('SELECT COUNT(*) FROM vaccination_schedules vs JOIN species s ON s.id = vs.species_id WHERE s.company_id = ?');
$stmt->execute([$companyId]);
$totalSchedules = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    'SELECT vs.*, s.name AS species_name
     FROM vaccination_schedules vs
     JOIN species s ON s.id = vs.species_id
     WHERE s.company_id = ?
     ORDER BY s.name, vs.age_in_days
     LIMIT ' . $perPage . ' OFFSET ' . hef_offset($page, $perPage)
);
$stmt->execute([$companyId]);
$schedules = $stmt->fetchAll();

// Pending alerts (vaccination_due), for quick review
$stmt = $pdo->prepare(
    "SELECT al.*, b.batch_code
     FROM alerts_log al
     JOIN batches b ON b.id = al.reference_id AND al.reference_type = 'batch_vaccination_schedule'
     WHERE al.company_id = ? AND al.type = 'vaccination_due' AND al.resolved_at IS NULL
     ORDER BY al.triggered_at DESC"
);
$stmt->execute([$companyId]);
$pendingAlerts = $stmt->fetchAll();
?>
<h4 class="mb-3"><i class="bi bi-heart-pulse me-2"></i>Health & Vaccinations</h4>

<?php if (! empty($pendingAlerts)): ?>
<div class="card p-3 mb-3 border-start border-danger border-4">
    <h6 class="text-danger mb-2"><i class="bi bi-exclamation-triangle me-1"></i>Vaccinations due</h6>
    <?php foreach ($pendingAlerts as $a): ?>
        <div class="d-flex justify-content-between align-items-center py-1">
            <span><?= htmlspecialchars($a['batch_code']) ?> has a vaccination due — open the batch to see which one.</span>
            <a href="/hef/admin/batch-view.php?id=<?= $a['reference_id'] ?>" class="btn btn-sm btn-outline-danger">Go to batch</a>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-lg-5">
        <div class="card p-3">
            <h6 class="mb-3">Add vaccination schedule</h6>
            <?php if (empty($speciesOptions)): ?>
                <div class="alert alert-warning py-2">Add a <a href="/hef/admin/species.php">species</a> first.</div>
            <?php else: ?>
            <?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="POST">
                <div class="mb-2">
                    <label class="form-label small">Species</label>
                    <select name="species_id" class="form-select" required>
                        <option value="">Select…</option>
                        <?php foreach ($speciesOptions as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Vaccine name</label>
                    <input type="text" name="name" class="form-control" required placeholder="e.g. Newcastle Disease">
                </div>
                <div class="mb-2">
                    <label class="form-label small">Age (days)</label>
                    <input type="number" name="age_in_days" class="form-control" required min="0" placeholder="e.g. 7">
                </div>
                <div class="form-check mb-2">
                    <input type="checkbox" name="is_recurring" class="form-check-input" id="isRecurring">
                    <label class="form-check-label small" for="isRecurring">Recurring</label>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Repeat every (days)</label>
                    <input type="number" name="recurrence_interval_days" class="form-control" placeholder="e.g. 30">
                </div>
                <button type="submit" class="btn btn-hef text-white w-100">Add schedule</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="list-group list-group-flush">
                <?php if (empty($schedules)): ?>
                    <div class="list-group-item text-muted">No vaccination schedules yet.</div>
                <?php endif; ?>
                <?php foreach ($schedules as $s): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-semibold"><?= htmlspecialchars($s['name']) ?> <span class="text-muted small">— <?= htmlspecialchars($s['species_name']) ?></span></div>
                            <div class="text-muted small">
                                Due at <?= (int) $s['age_in_days'] ?> days old
                                <?php if ($s['is_recurring']): ?> · repeats every <?= (int) $s['recurrence_interval_days'] ?> days<?php endif; ?>
                            </div>
                        </div>
                        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editSchedule<?= $s['id'] ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form method="POST" onsubmit="return confirm('Delete this vaccination schedule?');" class="d-inline ms-1">
                            <input type="hidden" name="delete_schedule_id" value="<?= $s['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>

                    <div class="modal fade" id="editSchedule<?= $s['id'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <form method="POST">
                                    <input type="hidden" name="edit_schedule_id" value="<?= $s['id'] ?>">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Edit vaccination schedule</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="mb-2">
                                            <label class="form-label small">Species</label>
                                            <select name="species_id" class="form-select" required>
                                                <?php foreach ($speciesOptions as $so): ?>
                                                    <option value="<?= $so['id'] ?>" <?= $so['id'] == $s['species_id'] ? 'selected' : '' ?>><?= htmlspecialchars($so['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small">Vaccine name</label>
                                            <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($s['name']) ?>">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small">Age (days)</label>
                                            <input type="number" name="age_in_days" class="form-control" required min="0" value="<?= (int) $s['age_in_days'] ?>">
                                        </div>
                                        <div class="form-check mb-2">
                                            <input type="checkbox" name="is_recurring" class="form-check-input" id="isRecurringEdit<?= $s['id'] ?>" <?= $s['is_recurring'] ? 'checked' : '' ?>>
                                            <label class="form-check-label small" for="isRecurringEdit<?= $s['id'] ?>">Recurring</label>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small">Repeat every (days)</label>
                                            <input type="number" name="recurrence_interval_days" class="form-control" value="<?= htmlspecialchars((string) ($s['recurrence_interval_days'] ?? '')) ?>">
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
        <?= hef_pagination_links($page, $totalSchedules, $perPage) ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
