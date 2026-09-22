<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/uploads.php';
require_once __DIR__ . '/../includes/inventory-helpers.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/line_items.php';

// View is open to everyone; only Owner can add/edit/delete a batch.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $currentUser['role'] !== 'owner') {
    header('Location: /hef/admin/batches.php?error=access_denied');
    exit;
}

$companyId = $currentUser['company_id'];
$error = '';

$stmt = $pdo->prepare('SELECT id, name FROM species WHERE company_id = ? ORDER BY name');
$stmt->execute([$companyId]);
$speciesOptions = $stmt->fetchAll();

// Breeds are optional. The breed field only appears once at least one exists.
$stmt = $pdo->prepare('SELECT id, species_id, name FROM breeds WHERE company_id = ? ORDER BY name');
$stmt->execute([$companyId]);
$breedOptions = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT id, name FROM rooms WHERE company_id = ? ORDER BY name');
$stmt->execute([$companyId]);
$roomOptions = $stmt->fetchAll();

/**
 * Keeps a batch's purchase cost in sync with a single auto-linked
 * "purchase" expense row (identified by batch_id + category=purchase).
 * Creates/updates/removes that row as needed so Finances always
 * reflects what's on the batch.
 */
function hef_sync_purchase_expense(PDO $pdo, int $companyId, int $batchId, ?float $cost, string $date, string $batchCode): void
{
    $stmt = $pdo->prepare("SELECT id FROM expenses WHERE batch_id = ? AND category = 'purchase' LIMIT 1");
    $stmt->execute([$batchId]);
    $existing = $stmt->fetch();

    if (! $cost || $cost <= 0) {
        if ($existing) {
            $pdo->prepare('DELETE FROM expenses WHERE id = ?')->execute([$existing['id']]);
        }
        return;
    }

    if ($existing) {
        $pdo->prepare('UPDATE expenses SET amount = ?, date = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$cost, $date, $existing['id']]);
    } else {
        $pdo->prepare(
            "INSERT INTO expenses (company_id, batch_id, category, amount, currency_code, date, notes, created_at, updated_at)
             VALUES (?, ?, 'purchase', ?, 'INR', ?, ?, NOW(), NOW())"
        )->execute([$companyId, $batchId, $cost, $date, "Batch purchase: {$batchCode}"]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_batch_id'])) {
    $id = (int) $_POST['delete_batch_id'];
    try {
        $stmt = $pdo->prepare('SELECT * FROM batches WHERE id = ? AND company_id = ?');
        $stmt->execute([$id, $companyId]);
        $oldRow = $stmt->fetch();
        $photoToDelete = $oldRow['photo_path'] ?? null;

        $pdo->prepare('DELETE FROM batches WHERE id = ? AND company_id = ?')->execute([$id, $companyId]);
        if ($oldRow) {
            hef_audit_log($pdo, $companyId, $currentUser['user_id'], 'batches', $id, 'delete', $oldRow, null);
        }
        // alerts_log.reference_id isn't a real foreign key, so it doesn't
        // clean up on its own — do it explicitly or these alerts (and the
        // "Vaccinations Due" count) would reference a batch that no longer exists.
        $pdo->prepare(
            "DELETE FROM alerts_log WHERE reference_type = 'batch_vaccination_schedule' AND reference_id = ?"
        )->execute([$id]);
        hef_delete_uploaded_image($photoToDelete);
        header('Location: /hef/admin/batches.php');
        exit;
    } catch (PDOException $e) {
        error_log('batch delete failed: ' . $e->getMessage());
        $error = 'Could not delete batch.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_batch_id'])) {
    $id = (int) $_POST['edit_batch_id'];
    $speciesId = (int) ($_POST['species_id'] ?? 0);
    $breedId = hef_resolve_breed_id($_POST['breed_id'] ?? 0, $speciesId, $breedOptions);
    $roomId = $_POST['room_id'] !== '' ? (int) $_POST['room_id'] : null;
    $batchCode = trim($_POST['batch_code'] ?? '');
    $dateAcquired = $_POST['date_acquired'] ?? '';
    $ageAtAcquisition = (int) ($_POST['age_at_acquisition_days'] ?? 0);
    $male = (int) ($_POST['current_count_male'] ?? 0);
    $female = (int) ($_POST['current_count_female'] ?? 0);
    $unknown = (int) ($_POST['current_count_unknown'] ?? 0);
    $sourceSupplier = trim($_POST['source_supplier'] ?? '');
    $purchaseCost = $_POST['purchase_cost'] !== '' ? (float) $_POST['purchase_cost'] : null;
    $status = $_POST['status'] ?? 'active';

    if (! $speciesId || ! $batchCode || ! $dateAcquired) {
        $error = 'Species, batch code, and date are required.';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT * FROM batches WHERE id = ? AND company_id = ?');
            $stmt->execute([$id, $companyId]);
            $oldRow = $stmt->fetch();

            // Moving a sold-out or closed batch back to active uses a slot again.
            if ($status === 'active' && $oldRow && $oldRow['status'] !== 'active') {
                $limitMessage = hef_limit_reached($pdo, (int) $companyId, 'batches');
                if ($limitMessage) {
                    throw new RuntimeException($limitMessage);
                }
            }

            $newPhotoPath = hef_handle_image_upload('photo', 'batches');

            if ($newPhotoPath) {
                $oldPhoto = $oldRow['photo_path'] ?? null;

                $pdo->prepare(
                    'UPDATE batches SET species_id = ?, breed_id = ?, room_id = ?, batch_code = ?, date_acquired = ?, age_at_acquisition_days = ?,
                        current_count_male = ?, current_count_female = ?, current_count_unknown = ?,
                        source_supplier = ?, purchase_cost = ?, status = ?, photo_path = ?, updated_at = NOW()
                     WHERE id = ? AND company_id = ?'
                )->execute([
                    $speciesId, $breedId, $roomId, $batchCode, $dateAcquired, $ageAtAcquisition,
                    $male, $female, $unknown,
                    $sourceSupplier ?: null, $purchaseCost, $status, $newPhotoPath,
                    $id, $companyId,
                ]);
                hef_delete_uploaded_image($oldPhoto);
            } else {
                $pdo->prepare(
                    'UPDATE batches SET species_id = ?, breed_id = ?, room_id = ?, batch_code = ?, date_acquired = ?, age_at_acquisition_days = ?,
                        current_count_male = ?, current_count_female = ?, current_count_unknown = ?,
                        source_supplier = ?, purchase_cost = ?, status = ?, updated_at = NOW()
                     WHERE id = ? AND company_id = ?'
                )->execute([
                    $speciesId, $breedId, $roomId, $batchCode, $dateAcquired, $ageAtAcquisition,
                    $male, $female, $unknown,
                    $sourceSupplier ?: null, $purchaseCost, $status,
                    $id, $companyId,
                ]);
            }
            if ($oldRow) {
                hef_audit_log($pdo, $companyId, $currentUser['user_id'], 'batches', $id, 'update', $oldRow, [
                    'species_id' => $speciesId, 'breed_id' => $breedId, 'room_id' => $roomId, 'batch_code' => $batchCode,
                    'date_acquired' => $dateAcquired, 'age_at_acquisition_days' => $ageAtAcquisition,
                    'current_count_male' => $male, 'current_count_female' => $female, 'current_count_unknown' => $unknown,
                    'source_supplier' => $sourceSupplier ?: null, 'purchase_cost' => $purchaseCost, 'status' => $status,
                ]);
            }
            hef_sync_purchase_expense($pdo, $companyId, $id, $purchaseCost, $dateAcquired, $batchCode);
            hef_reconcile_batch_inventory($pdo, $id);
            header('Location: /hef/admin/batches.php');
            exit;
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            error_log('batches update failed: ' . $e->getMessage());
            $error = str_contains($e->getMessage(), 'Duplicate')
                ? 'That batch code is already in use.'
                : 'Could not update batch. Please check the values and try again.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $speciesId = (int) ($_POST['species_id'] ?? 0);
    $breedId = hef_resolve_breed_id($_POST['breed_id'] ?? 0, $speciesId, $breedOptions);
    $roomId = $_POST['room_id'] !== '' ? (int) $_POST['room_id'] : null;
    $batchCode = trim($_POST['batch_code'] ?? '');
    $dateAcquired = $_POST['date_acquired'] ?? '';
    $ageAtAcquisition = (int) ($_POST['age_at_acquisition_days'] ?? 0);
    $male = (int) ($_POST['initial_count_male'] ?? 0);
    $female = (int) ($_POST['initial_count_female'] ?? 0);
    $unknown = (int) ($_POST['initial_count_unknown'] ?? 0);
    $sourceSupplier = trim($_POST['source_supplier'] ?? '');
    $purchaseCost = $_POST['purchase_cost'] !== '' ? (float) $_POST['purchase_cost'] : null;

    if (! $speciesId || ! $batchCode || ! $dateAcquired || ($male + $female + $unknown) === 0) {
        $error = 'Species, batch code, date, and at least one animal count are required.';
    } elseif (empty($_FILES['photo']) || $_FILES['photo']['error'] === UPLOAD_ERR_NO_FILE) {
        $error = 'A photo is required for every batch.';
    } elseif ($limitMessage = hef_limit_reached($pdo, (int) $companyId, 'batches')) {
        $error = $limitMessage; // plan's active-batch limit reached
    } else {
        try {
            $photoPath = hef_handle_image_upload('photo', 'batches');

            $stmt = $pdo->prepare(
                'INSERT INTO batches
                    (company_id, species_id, breed_id, room_id, batch_code, date_acquired, age_at_acquisition_days,
                     initial_count_male, initial_count_female, initial_count_unknown,
                     current_count_male, current_count_female, current_count_unknown,
                     source_supplier, purchase_cost, photo_path, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "active", NOW(), NOW())'
            );
            $stmt->execute([
                $companyId, $speciesId, $breedId, $roomId, $batchCode, $dateAcquired, $ageAtAcquisition,
                $male, $female, $unknown,
                $male, $female, $unknown,
                $sourceSupplier ?: null, $purchaseCost, $photoPath,
            ]);
            $newBatchId = (int) $pdo->lastInsertId();
            hef_sync_purchase_expense($pdo, $companyId, $newBatchId, $purchaseCost, $dateAcquired, $batchCode);
            header('Location: /hef/admin/batches.php');
            exit;
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            error_log('batches insert failed: ' . $e->getMessage());
            $error = str_contains($e->getMessage(), 'Duplicate')
                ? 'That batch code is already in use.'
                : 'Could not save batch. Please check the values and try again.';
        }
    }
}

require_once __DIR__ . '/header.php';

$perPage = 12;
$page = hef_current_page();

$stmt = $pdo->prepare('SELECT COUNT(*) FROM batches WHERE company_id = ?');
$stmt->execute([$companyId]);
$totalBatches = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    'SELECT b.*, s.name AS species_name, br.name AS breed_name, r.name AS room_name
     FROM batches b
     JOIN species s ON s.id = b.species_id
     LEFT JOIN breeds br ON br.id = b.breed_id
     LEFT JOIN rooms r ON r.id = b.room_id
     WHERE b.company_id = ?
     ORDER BY b.date_acquired DESC
     LIMIT ' . $perPage . ' OFFSET ' . hef_offset($page, $perPage)
);
$stmt->execute([$companyId]);
$batches = $stmt->fetchAll();

// Mortality and sell-by info for the cards (no money figures here).
$batchPerf = hef_batch_performance($pdo, (int) $companyId, hef_company_today_for($pdo, (int) $companyId));
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0"><i class="bi bi-egg-fried me-2"></i>Batches</h4>
    <div class="d-flex gap-2">
        <a href="/hef/admin/export.php?type=batches" class="btn btn-outline-secondary btn-sm"><i class="bi bi-download me-1"></i>Export CSV</a>
        <button class="btn btn-hef text-white btn-sm" data-bs-toggle="modal" data-bs-target="#addBatchModal">
            <i class="bi bi-plus-lg me-1"></i>Add batch
        </button>
    </div>
</div>

<?php if (empty($speciesOptions)): ?>
    <div class="alert alert-warning">
        You need at least one <a href="/hef/admin/species.php">species</a> added before creating a batch.
    </div>
<?php endif; ?>

<div class="row g-3">
    <?php if (empty($batches)): ?>
        <div class="col-12"><div class="card p-4 text-muted">No batches yet. Add your first one above.</div></div>
    <?php endif; ?>

    <?php foreach ($batches as $b): ?>
        <?php $totalCurrent = $b['current_count_male'] + $b['current_count_female'] + $b['current_count_unknown']; ?>
        <?php $totalInitial = $b['initial_count_male'] + $b['initial_count_female'] + $b['initial_count_unknown']; ?>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card p-3 h-100">
                <?php if ($b['photo_path']): ?>
                    <img src="/hef/<?= htmlspecialchars($b['photo_path']) ?>" alt="" class="rounded mb-2" style="width:100%; height:140px; object-fit:cover;">
                <?php endif; ?>
                <div class="d-flex justify-content-between align-items-start">
                    <a href="/hef/admin/batch-view.php?id=<?= $b['id'] ?>" class="text-decoration-none text-dark flex-fill">
                        <div class="fw-semibold"><?= htmlspecialchars($b['batch_code']) ?></div>
                        <div class="text-muted small"><?= htmlspecialchars(hef_species_breed_label($b['species_name'], $b['breed_name'])) ?></div>
                    </a>
                    <div class="d-flex gap-1">
                        <span class="badge bg-success-subtle text-success align-self-start"><?= htmlspecialchars($b['status']) ?></span>
                        <button class="btn btn-sm btn-outline-secondary py-0 px-1" data-bs-toggle="modal" data-bs-target="#editBatch<?= $b['id'] ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form method="POST" onsubmit="return confirm('Delete this batch and ALL its mortality, health, vaccination and feed records? This cannot be undone.');" class="d-inline">
                            <input type="hidden" name="delete_batch_id" value="<?= $b['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                </div>
                <a href="/hef/admin/batch-view.php?id=<?= $b['id'] ?>" class="text-decoration-none text-dark">
                    <div class="mt-2 small text-muted">
                        <i class="bi bi-door-open me-1"></i><?= htmlspecialchars($b['room_name'] ?? 'No room assigned') ?>
                    </div>
                    <div class="mt-2">
                        <span class="fs-5 fw-bold"><?= $totalCurrent ?></span>
                        <span class="text-muted small">/ <?= $totalInitial ?> alive</span>
                    </div>
                    <div class="text-muted small">Acquired <?= htmlspecialchars($b['date_acquired']) ?><?= $b['age_at_acquisition_days'] > 0 ? ' (age ' . (int) $b['age_at_acquisition_days'] . ' days)' : '' ?></div>
                    <?php $perf = $batchPerf[(int) $b['id']] ?? null; ?>
                    <?php if ($perf): ?>
                        <div class="mt-2 d-flex flex-wrap gap-1 small">
                            <?php if ($perf['mortality_pct'] !== null): ?>
                                <span class="badge <?= hef_mortality_class($perf['mortality_pct']) ?>">Mortality <?= $perf['mortality_pct'] ?>%</span>
                            <?php endif; ?>
                            <?php $sellBadge = hef_sell_by_badge($perf); ?>
                            <?php if ($sellBadge): ?>
                                <span class="badge <?= $sellBadge[1] ?>"><?= htmlspecialchars($sellBadge[0]) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </a>
            </div>
        </div>

        <!-- Edit modal for this batch -->
        <div class="modal fade" id="editBatch<?= $b['id'] ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="edit_batch_id" value="<?= $b['id'] ?>">
                        <div class="modal-header">
                            <h5 class="modal-title">Edit batch</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-2">
                                <label class="form-label small">Photo <?= $b['photo_path'] ? '(leave blank to keep current)' : '' ?></label>
                                <input type="file" name="photo" class="form-control" accept="image/*">
                            </div>
                            <div class="mb-2">
                                <label class="form-label small">Species</label>
                                <select name="species_id" class="form-select hef-species-select" required>
                                    <?php foreach ($speciesOptions as $s): ?>
                                        <option value="<?= $s['id'] ?>" <?= $s['id'] == $b['species_id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php if ($breedOptions): ?>
                            <div class="mb-2">
                                <label class="form-label small">Breed</label>
                                <?php hef_render_breed_select($breedOptions, (int) $b['species_id'], $b['breed_id'] !== null ? (int) $b['breed_id'] : null); ?>
                            </div>
                            <?php endif; ?>
                            <div class="mb-2">
                                <label class="form-label small">Room</label>
                                <select name="room_id" class="form-select">
                                    <option value="">Unassigned</option>
                                    <?php foreach ($roomOptions as $r): ?>
                                        <option value="<?= $r['id'] ?>" <?= $r['id'] == $b['room_id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small">Batch code</label>
                                <input type="text" name="batch_code" class="form-control" required value="<?= htmlspecialchars($b['batch_code']) ?>">
                            </div>
                            <div class="row g-2 mb-2">
                                <div class="col-7">
                                    <label class="form-label small">Date acquired</label>
                                    <input type="date" name="date_acquired" class="form-control" required value="<?= htmlspecialchars($b['date_acquired']) ?>">
                                </div>
                                <div class="col-5">
                                    <label class="form-label small">Age then (days)</label>
                                    <input type="number" name="age_at_acquisition_days" class="form-control" min="0" value="<?= (int) $b['age_at_acquisition_days'] ?>">
                                </div>
                            </div>
                            <div class="row g-2 mb-2">
                                <div class="col-4">
                                    <label class="form-label small">Male (current)</label>
                                    <input type="number" name="current_count_male" class="form-control" value="<?= (int) $b['current_count_male'] ?>" min="0">
                                </div>
                                <div class="col-4">
                                    <label class="form-label small">Female (current)</label>
                                    <input type="number" name="current_count_female" class="form-control" value="<?= (int) $b['current_count_female'] ?>" min="0">
                                </div>
                                <div class="col-4">
                                    <label class="form-label small">Unknown (current)</label>
                                    <input type="number" name="current_count_unknown" class="form-control" value="<?= (int) $b['current_count_unknown'] ?>" min="0">
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small">Status</label>
                                <select name="status" class="form-select">
                                    <?php foreach (['active', 'sold_out', 'closed'] as $st): ?>
                                        <option value="<?= $st ?>" <?= $st === $b['status'] ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $st)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small">Source / supplier</label>
                                <input type="text" name="source_supplier" class="form-control" value="<?= htmlspecialchars($b['source_supplier'] ?? '') ?>">
                            </div>
                            <div class="mb-2">
                                <label class="form-label small">Purchase cost (₹)</label>
                                <input type="number" step="0.01" name="purchase_cost" class="form-control" value="<?= htmlspecialchars((string) $b['purchase_cost']) ?>">
                            </div>
                            <div class="alert alert-info small py-2 mb-0">
                                Editing current counts directly here does not create a mortality log entry — use "Record mortality" on the batch page for that instead. Purchase cost automatically stays in sync with Finances.
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
<?= hef_pagination_links($page, $totalBatches, $perPage) ?>

<!-- Add Batch Modal -->
<div class="modal fade" id="addBatchModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">Add batch</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>

                    <div class="mb-2">
                        <label class="form-label small">Photo (required)</label>
                        <input type="file" name="photo" class="form-control" accept="image/*" required>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Species</label>
                        <select name="species_id" class="form-select hef-species-select" required>
                            <option value="">Select…</option>
                            <?php foreach ($speciesOptions as $s): ?>
                                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($breedOptions): ?>
                    <div class="mb-2">
                        <label class="form-label small">Breed</label>
                        <?php hef_render_breed_select($breedOptions, 0, null); ?>
                    </div>
                    <?php endif; ?>
                    <div class="mb-2">
                        <label class="form-label small">Room</label>
                        <select name="room_id" class="form-select">
                            <option value="">Unassigned</option>
                            <?php foreach ($roomOptions as $r): ?>
                                <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Batch code</label>
                        <input type="text" name="batch_code" class="form-control" required placeholder="e.g. CHK-2026-01">
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-7">
                            <label class="form-label small">Date acquired</label>
                            <input type="date" name="date_acquired" class="form-control" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-5">
                            <label class="form-label small">Age then (days)</label>
                            <input type="number" name="age_at_acquisition_days" class="form-control" min="0" value="0">
                        </div>
                    </div>
                    <div class="form-text mb-2" style="margin-top:-8px;">
                        Leave as 0 if these were day-old. For your original chickens (~2 months old when acquired) that would be ~60.
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-4">
                            <label class="form-label small">Male</label>
                            <input type="number" name="initial_count_male" class="form-control" value="0" min="0">
                        </div>
                        <div class="col-4">
                            <label class="form-label small">Female</label>
                            <input type="number" name="initial_count_female" class="form-control" value="0" min="0">
                        </div>
                        <div class="col-4">
                            <label class="form-label small">Unknown</label>
                            <input type="number" name="initial_count_unknown" class="form-control" value="0" min="0">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Source / supplier</label>
                        <input type="text" name="source_supplier" class="form-control">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Purchase cost (₹)</label>
                        <input type="number" step="0.01" name="purchase_cost" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-hef text-white w-100">Save batch</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($breedOptions) { hef_breed_select_script($breedOptions); } ?>
<?php require_once __DIR__ . '/footer.php'; ?>
