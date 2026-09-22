<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/permissions.php';
hef_require_role(['owner', 'worker'], $currentUser['role']);

$companyId = $currentUser['company_id'];
$error = '';

$stmt = $pdo->prepare('SELECT id, name FROM species WHERE company_id = ? ORDER BY name');
$stmt->execute([$companyId]);
$speciesOptions = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT b.id, b.batch_code, s.name AS species_name
     FROM batches b JOIN species s ON s.id = b.species_id
     WHERE b.company_id = ? AND b.status = 'active' ORDER BY b.batch_code"
);
$stmt->execute([$companyId]);
$batchOptions = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_purchase_id'])) {
    $id = (int) $_POST['delete_purchase_id'];
    $pdo->prepare('DELETE FROM feed_purchases WHERE id = ? AND company_id = ?')->execute([$id, $companyId]);
    header('Location: /hef/admin/feed.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_consumption_id'])) {
    $id = (int) $_POST['delete_consumption_id'];
    $stmt = $pdo->prepare('SELECT fc.id FROM feed_consumption fc JOIN batches b ON b.id = fc.batch_id WHERE fc.id = ? AND b.company_id = ?');
    $stmt->execute([$id, $companyId]);
    if ($stmt->fetch()) {
        $pdo->prepare('DELETE FROM feed_consumption WHERE id = ?')->execute([$id]);
    }
    header('Location: /hef/admin/feed.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_purchase_id'])) {
    $id = (int) $_POST['edit_purchase_id'];
    $speciesId = (int) ($_POST['species_id'] ?? 0);
    $feedTypeName = trim($_POST['feed_type_name'] ?? '');
    $quantity = (float) ($_POST['quantity'] ?? 0);
    $unit = $_POST['unit'] ?? 'kg';
    $cost = (float) ($_POST['cost'] ?? 0);
    $supplier = trim($_POST['supplier'] ?? '');
    $date = $_POST['date'] ?: date('Y-m-d');

    if (! $speciesId || ! $feedTypeName || $quantity <= 0) {
        $error = 'Species, feed type, and a positive quantity are required.';
    } else {
        try {
            $pdo->prepare(
                'UPDATE feed_purchases SET species_id=?, feed_type_name=?, quantity=?, unit=?, cost=?, supplier=?, date=?, updated_at=NOW()
                 WHERE id = ? AND company_id = ?'
            )->execute([$speciesId, $feedTypeName, $quantity, $unit, $cost, $supplier ?: null, $date, $id, $companyId]);
            header('Location: /hef/admin/feed.php');
            exit;
        } catch (PDOException $e) {
            error_log('feed_purchases update failed: ' . $e->getMessage());
            $error = 'Could not update purchase. Please try again.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_consumption_id'])) {
    $id = (int) $_POST['edit_consumption_id'];
    $batchId = (int) ($_POST['batch_id'] ?? 0);
    $quantity = (float) ($_POST['consumption_quantity'] ?? 0);
    $unit = $_POST['consumption_unit'] ?? 'kg';
    $cost = isset($_POST['consumption_cost']) && $_POST['consumption_cost'] !== '' ? (float) $_POST['consumption_cost'] : null;
    $date = $_POST['consumption_date'] ?: date('Y-m-d');

    $stmt = $pdo->prepare('SELECT id FROM batches WHERE id = ? AND company_id = ?');
    $stmt->execute([$batchId, $companyId]);

    if (! $batchId || $quantity <= 0 || ! $stmt->fetch()) {
        $error = 'A valid batch and a positive quantity are required.';
    } else {
        try {
            $pdo->prepare(
                'UPDATE feed_consumption SET batch_id=?, quantity=?, unit=?, cost=?, date=?, updated_at=NOW() WHERE id = ?'
            )->execute([$batchId, $quantity, $unit, $cost, $date, $id]);
            header('Location: /hef/admin/feed.php');
            exit;
        } catch (PDOException $e) {
            error_log('feed_consumption update failed: ' . $e->getMessage());
            $error = 'Could not update consumption entry. Please try again.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_purchase'])) {
    $speciesId = (int) ($_POST['species_id'] ?? 0);
    $feedTypeName = trim($_POST['feed_type_name'] ?? '');
    $quantity = (float) ($_POST['quantity'] ?? 0);
    $unit = $_POST['unit'] ?? 'kg';
    $cost = (float) ($_POST['cost'] ?? 0);
    $supplier = trim($_POST['supplier'] ?? '');
    $date = $_POST['date'] ?: date('Y-m-d');

    if (! $speciesId || ! $feedTypeName || $quantity <= 0) {
        $error = 'Species, feed type, and a positive quantity are required.';
    } else {
        try {
            $pdo->prepare(
                'INSERT INTO feed_purchases (company_id, species_id, feed_type_name, quantity, unit, cost, supplier, date, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
            )->execute([$companyId, $speciesId, $feedTypeName, $quantity, $unit, $cost, $supplier ?: null, $date]);

            // Mirror to expenses so it shows up in financial reports too
            $pdo->prepare(
                "INSERT INTO expenses (company_id, category, amount, currency_code, date, notes, created_at, updated_at)
                 VALUES (?, 'feed', ?, 'INR', ?, ?, NOW(), NOW())"
            )->execute([$companyId, $cost, $date, "Feed purchase: {$feedTypeName} ({$quantity}{$unit})"]);

            header('Location: /hef/admin/feed.php');
            exit;
        } catch (PDOException $e) {
            error_log('feed_purchases insert failed: ' . $e->getMessage());
            $error = 'Could not save purchase. Please check the values and try again.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_consumption'])) {
    $batchId = (int) ($_POST['batch_id'] ?? 0);
    $quantity = (float) ($_POST['consumption_quantity'] ?? 0);
    $unit = $_POST['consumption_unit'] ?? 'kg';
    $cost = isset($_POST['consumption_cost']) && $_POST['consumption_cost'] !== '' ? (float) $_POST['consumption_cost'] : null;
    $date = $_POST['consumption_date'] ?: date('Y-m-d');

    // Confirm batch belongs to this company
    $stmt = $pdo->prepare('SELECT id FROM batches WHERE id = ? AND company_id = ?');
    $stmt->execute([$batchId, $companyId]);

    if (! $batchId || $quantity <= 0 || ! $stmt->fetch()) {
        $error = 'A valid batch and a positive quantity are required.';
    } else {
        try {
            $pdo->prepare(
                'INSERT INTO feed_consumption (batch_id, date, quantity, unit, cost, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
            )->execute([$batchId, $date, $quantity, $unit, $cost]);
            header('Location: /hef/admin/feed.php');
            exit;
        } catch (PDOException $e) {
            error_log('feed_consumption insert failed: ' . $e->getMessage());
            $error = 'Could not save consumption entry. Please try again.';
        }
    }
}

require_once __DIR__ . '/header.php';

// --- Running stock balance per species + unit: purchases minus consumption ---
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
$consumedRows = $stmt->fetchAll();
$consumedBySpeciesUnit = [];
foreach ($consumedRows as $row) {
    $consumedBySpeciesUnit[$row['species_id'] . '|' . $row['unit']] = (float) $row['consumed'];
}

$stockBalances = [];
foreach ($purchasedBySpeciesUnit as $row) {
    $key = $row['id'] . '|' . $row['unit'];
    $consumed = $consumedBySpeciesUnit[$key] ?? 0;
    $stockBalances[] = [
        'species_name' => $row['name'],
        'unit' => $row['unit'],
        'balance' => (float) $row['purchased'] - $consumed,
    ];
}

// Recent purchases + consumption for the activity lists
$perPage = 10;
$ppage = hef_current_page('ppage');
$cpage = hef_current_page('cpage');

$stmt = $pdo->prepare('SELECT COUNT(*) FROM feed_purchases WHERE company_id = ?');
$stmt->execute([$companyId]);
$totalPurchases = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT fp.*, s.name AS species_name FROM feed_purchases fp
     JOIN species s ON s.id = fp.species_id
     WHERE fp.company_id = ? ORDER BY fp.date DESC, fp.id DESC LIMIT {$perPage} OFFSET " . hef_offset($ppage, $perPage)
);
$stmt->execute([$companyId]);
$recentPurchases = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM feed_consumption fc JOIN batches b ON b.id = fc.batch_id WHERE b.company_id = ?"
);
$stmt->execute([$companyId]);
$totalConsumption = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT fc.*, b.batch_code, s.name AS species_name FROM feed_consumption fc
     JOIN batches b ON b.id = fc.batch_id
     JOIN species s ON s.id = b.species_id
     WHERE b.company_id = ? ORDER BY fc.date DESC, fc.id DESC LIMIT {$perPage} OFFSET " . hef_offset($cpage, $perPage)
);
$stmt->execute([$companyId]);
$recentConsumption = $stmt->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-basket me-2"></i>Feed</h4>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#consumptionModal">
            <i class="bi bi-dash-circle me-1"></i>Log consumption
        </button>
        <button class="btn btn-hef text-white btn-sm" data-bs-toggle="modal" data-bs-target="#purchaseModal">
            <i class="bi bi-plus-lg me-1"></i>Log purchase
        </button>
    </div>
</div>

<h6 class="mb-2">Stock balance</h6>
<div class="row g-3 mb-4">
    <?php if (empty($stockBalances)): ?>
        <div class="col-12"><div class="card p-4 text-muted">No feed purchases logged yet.</div></div>
    <?php endif; ?>
    <?php foreach ($stockBalances as $sb): ?>
        <div class="col-6 col-lg-3">
            <div class="card p-3 text-center">
                <div class="fs-3 fw-bold <?= $sb['balance'] <= 0 ? 'text-danger' : 'text-success' ?>">
                    <?= number_format($sb['balance'], 1) ?> <?= htmlspecialchars($sb['unit']) ?>
                </div>
                <div class="text-muted small"><?= htmlspecialchars($sb['species_name']) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-6">
        <h6 class="mb-2">Recent purchases</h6>
        <div class="card">
            <div class="list-group list-group-flush">
                <?php if (empty($recentPurchases)): ?>
                    <div class="list-group-item text-muted small">None yet.</div>
                <?php endif; ?>
                <?php foreach ($recentPurchases as $p): ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between">
                            <span><?= htmlspecialchars($p['feed_type_name']) ?> — <?= htmlspecialchars($p['species_name']) ?></span>
                            <div class="d-flex align-items-center gap-2">
                                <span class="text-muted small"><?= htmlspecialchars($p['date']) ?></span>
                                <button class="btn btn-sm btn-outline-secondary py-0 px-1" data-bs-toggle="modal" data-bs-target="#editPurchase<?= $p['id'] ?>"><i class="bi bi-pencil"></i></button>
                                <form method="POST" onsubmit="return confirm('Delete this purchase entry?');" class="d-inline">
                                    <input type="hidden" name="delete_purchase_id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1"><i class="bi bi-trash"></i></button>
                                </form>
                            </div>
                        </div>
                        <div class="text-muted small"><?= number_format($p['quantity'], 1) ?> <?= htmlspecialchars($p['unit']) ?> · ₹<?= number_format($p['cost'], 0) ?><?= $p['supplier'] ? ' · ' . htmlspecialchars($p['supplier']) : '' ?></div>
                    </div>

                    <div class="modal fade" id="editPurchase<?= $p['id'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <form method="POST">
                                    <input type="hidden" name="edit_purchase_id" value="<?= $p['id'] ?>">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Edit purchase</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="mb-2">
                                            <label class="form-label small">Species</label>
                                            <select name="species_id" class="form-select" required>
                                                <?php foreach ($speciesOptions as $s): ?>
                                                    <option value="<?= $s['id'] ?>" <?= $s['id'] == $p['species_id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small">Feed type</label>
                                            <input type="text" name="feed_type_name" class="form-control" required value="<?= htmlspecialchars($p['feed_type_name']) ?>">
                                        </div>
                                        <div class="row g-2 mb-2">
                                            <div class="col-8">
                                                <label class="form-label small">Quantity</label>
                                                <input type="number" step="0.01" name="quantity" class="form-control" required value="<?= htmlspecialchars((string) $p['quantity']) ?>">
                                            </div>
                                            <div class="col-4">
                                                <label class="form-label small">Unit</label>
                                                <select name="unit" class="form-select">
                                                    <?php foreach (['kg', 'g', 'l', 'ml'] as $u): ?>
                                                        <option value="<?= $u ?>" <?= $u === $p['unit'] ? 'selected' : '' ?>><?= $u ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small">Cost (₹)</label>
                                            <input type="number" step="0.01" name="cost" class="form-control" required value="<?= htmlspecialchars((string) $p['cost']) ?>">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small">Supplier</label>
                                            <input type="text" name="supplier" class="form-control" value="<?= htmlspecialchars($p['supplier'] ?? '') ?>">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small">Date</label>
                                            <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($p['date']) ?>">
                                        </div>
                                        <div class="alert alert-info small py-2 mb-0">Note: this won't update the matching expense entry created when the purchase was first logged.</div>
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
        <?= hef_pagination_links($ppage, $totalPurchases, $perPage, 'ppage') ?>
    </div>

    <div class="col-12 col-lg-6">
        <h6 class="mb-2">Recent consumption</h6>
        <div class="card">
            <div class="list-group list-group-flush">
                <?php if (empty($recentConsumption)): ?>
                    <div class="list-group-item text-muted small">None yet.</div>
                <?php endif; ?>
                <?php foreach ($recentConsumption as $c): ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between">
                            <span><?= htmlspecialchars($c['batch_code']) ?> — <?= htmlspecialchars($c['species_name']) ?></span>
                            <div class="d-flex align-items-center gap-2">
                                <span class="text-muted small"><?= htmlspecialchars($c['date']) ?></span>
                                <button class="btn btn-sm btn-outline-secondary py-0 px-1" data-bs-toggle="modal" data-bs-target="#editConsumption<?= $c['id'] ?>"><i class="bi bi-pencil"></i></button>
                                <form method="POST" onsubmit="return confirm('Delete this consumption entry?');" class="d-inline">
                                    <input type="hidden" name="delete_consumption_id" value="<?= $c['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1"><i class="bi bi-trash"></i></button>
                                </form>
                            </div>
                        </div>
                        <div class="text-muted small"><?= number_format($c['quantity'], 1) ?> <?= htmlspecialchars($c['unit']) ?></div>
                    </div>

                    <div class="modal fade" id="editConsumption<?= $c['id'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <form method="POST">
                                    <input type="hidden" name="edit_consumption_id" value="<?= $c['id'] ?>">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Edit consumption</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="mb-2">
                                            <label class="form-label small">Batch</label>
                                            <select name="batch_id" class="form-select" required>
                                                <?php foreach ($batchOptions as $b): ?>
                                                    <option value="<?= $b['id'] ?>" <?= $b['id'] == $c['batch_id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['batch_code']) ?> — <?= htmlspecialchars($b['species_name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="row g-2 mb-2">
                                            <div class="col-8">
                                                <label class="form-label small">Quantity</label>
                                                <input type="number" step="0.01" name="consumption_quantity" class="form-control" required value="<?= htmlspecialchars((string) $c['quantity']) ?>">
                                            </div>
                                            <div class="col-4">
                                                <label class="form-label small">Unit</label>
                                                <select name="consumption_unit" class="form-select">
                                                    <?php foreach (['kg', 'g', 'l', 'ml'] as $u): ?>
                                                        <option value="<?= $u ?>" <?= $u === $c['unit'] ? 'selected' : '' ?>><?= $u ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small">Cost (₹, optional)</label>
                                            <input type="number" step="0.01" name="consumption_cost" class="form-control" value="<?= htmlspecialchars((string) ($c['cost'] ?? '')) ?>">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small">Date</label>
                                            <input type="date" name="consumption_date" class="form-control" value="<?= htmlspecialchars($c['date']) ?>">
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
        <?= hef_pagination_links($cpage, $totalConsumption, $perPage, 'cpage') ?>
    </div>
</div>

<!-- Log Purchase Modal -->
<div class="modal fade" id="purchaseModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="add_purchase" value="1">
                <div class="modal-header">
                    <h5 class="modal-title">Log feed purchase</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
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
                        <label class="form-label small">Feed type</label>
                        <input type="text" name="feed_type_name" class="form-control" required placeholder="e.g. Layer mash">
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-8">
                            <label class="form-label small">Quantity</label>
                            <input type="number" step="0.01" name="quantity" class="form-control" required min="0.01">
                        </div>
                        <div class="col-4">
                            <label class="form-label small">Unit</label>
                            <select name="unit" class="form-select">
                                <option value="kg">kg</option>
                                <option value="g">g</option>
                                <option value="l">l</option>
                                <option value="ml">ml</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Cost (₹)</label>
                        <input type="number" step="0.01" name="cost" class="form-control" required min="0">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Supplier</label>
                        <input type="text" name="supplier" class="form-control">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Date</label>
                        <input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-hef text-white w-100">Save purchase</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Log Consumption Modal -->
<div class="modal fade" id="consumptionModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="add_consumption" value="1">
                <div class="modal-header">
                    <h5 class="modal-title">Log feed consumption</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if (empty($batchOptions)): ?>
                        <div class="alert alert-warning py-2">No active batches yet.</div>
                    <?php else: ?>
                    <div class="mb-2">
                        <label class="form-label small">Batch</label>
                        <select name="batch_id" class="form-select" required>
                            <option value="">Select…</option>
                            <?php foreach ($batchOptions as $b): ?>
                                <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['batch_code']) ?> — <?= htmlspecialchars($b['species_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-8">
                            <label class="form-label small">Quantity</label>
                            <input type="number" step="0.01" name="consumption_quantity" class="form-control" required min="0.01">
                        </div>
                        <div class="col-4">
                            <label class="form-label small">Unit</label>
                            <select name="consumption_unit" class="form-select">
                                <option value="kg">kg</option>
                                <option value="g">g</option>
                                <option value="l">l</option>
                                <option value="ml">ml</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Cost (₹, optional)</label>
                        <input type="number" step="0.01" name="consumption_cost" class="form-control" value="">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Date</label>
                        <input type="date" name="consumption_date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-hef text-white w-100" <?= empty($batchOptions) ? 'disabled' : '' ?>>Save consumption</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
