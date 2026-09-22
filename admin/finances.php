<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/permissions.php';
hef_require_role(['owner'], $currentUser['role']);

$companyId = $currentUser['company_id'];
$error = '';

$stmt = $pdo->prepare(
    "SELECT b.id, b.batch_code, s.name AS species_name FROM batches b
     JOIN species s ON s.id = b.species_id WHERE b.company_id = ? ORDER BY b.batch_code"
);
$stmt->execute([$companyId]);
$batchOptions = $stmt->fetchAll();

// --- Edit expense ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_expense_id'])) {
    $id = (int) $_POST['edit_expense_id'];
    $category = $_POST['category'] ?? 'other';
    $amount = (float) ($_POST['amount'] ?? 0);
    $date = $_POST['date'] ?: date('Y-m-d');
    $batchId = $_POST['batch_id'] !== '' ? (int) $_POST['batch_id'] : null;
    $notes = trim($_POST['notes'] ?? '');

    if ($amount <= 0) {
        $error = 'Amount must be greater than zero.';
    } else {
        try {
            $pdo->prepare(
                'UPDATE expenses SET category=?, amount=?, date=?, batch_id=?, notes=?, updated_at=NOW() WHERE id=? AND company_id=?'
            )->execute([$category, $amount, $date, $batchId, $notes ?: null, $id, $companyId]);
            header('Location: /hef/admin/finances.php');
            exit;
        } catch (PDOException $e) {
            error_log('expense update failed: ' . $e->getMessage());
            $error = 'Could not update expense.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_expense'])) {
    $category = $_POST['category'] ?? 'other';
    $amount = (float) ($_POST['amount'] ?? 0);
    $date = $_POST['date'] ?: date('Y-m-d');
    $batchId = $_POST['batch_id'] !== '' ? (int) $_POST['batch_id'] : null;
    $notes = trim($_POST['notes'] ?? '');

    if ($amount <= 0) {
        $error = 'Amount must be greater than zero.';
    } else {
        try {
            $pdo->prepare(
                "INSERT INTO expenses (company_id, batch_id, category, amount, currency_code, date, notes, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'INR', ?, ?, NOW(), NOW())"
            )->execute([$companyId, $batchId, $category, $amount, $date, $notes ?: null]);
            header('Location: /hef/admin/finances.php');
            exit;
        } catch (PDOException $e) {
            error_log('expense insert failed: ' . $e->getMessage());
            $error = 'Could not save expense.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_expense_id'])) {
    $id = (int) $_POST['delete_expense_id'];
    $pdo->prepare('DELETE FROM expenses WHERE id = ? AND company_id = ?')->execute([$id, $companyId]);
    header('Location: /hef/admin/finances.php');
    exit;
}

/** Applies (or reverts, with negative $sign) a sale's effect on a batch's live count. */
function hef_adjust_batch_for_sale(PDO $pdo, int $batchId, ?int $quantity, ?string $gender, int $sign): void
{
    if (! $quantity || $quantity <= 0) {
        return;
    }
    $field = match ($gender) {
        'male' => 'current_count_male',
        'female' => 'current_count_female',
        default => 'current_count_unknown',
    };
    $delta = $sign * $quantity;
    $pdo->prepare("UPDATE batches SET {$field} = {$field} + ? WHERE id = ?")->execute([$delta, $batchId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_income_id'])) {
    $id = (int) $_POST['delete_income_id'];
    $stmt = $pdo->prepare('SELECT * FROM income WHERE id = ? AND company_id = ?');
    $stmt->execute([$id, $companyId]);
    $row = $stmt->fetch();
    if ($row) {
        $pdo->beginTransaction();
        try {
            if ($row['batch_id'] && $row['quantity_sold']) {
                hef_adjust_batch_for_sale($pdo, $row['batch_id'], $row['quantity_sold'], $row['gender_sold'], +1); // give back
            }
            $pdo->prepare('DELETE FROM income WHERE id = ?')->execute([$id]);
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('income delete failed: ' . $e->getMessage());
        }
    }
    header('Location: /hef/admin/finances.php');
    exit;
}

// --- Edit income ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_income_id'])) {
    $id = (int) $_POST['edit_income_id'];
    $source = $_POST['source'] ?? 'other';
    $amount = (float) ($_POST['amount'] ?? 0);
    $date = $_POST['date'] ?: date('Y-m-d');
    $batchId = $_POST['batch_id'] !== '' ? (int) $_POST['batch_id'] : null;
    $notes = trim($_POST['notes'] ?? '');
    $newQty = $_POST['quantity_sold'] !== '' ? (int) $_POST['quantity_sold'] : null;
    $newGender = $_POST['gender_sold'] ?: null;

    if ($amount <= 0) {
        $error = 'Amount must be greater than zero.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM income WHERE id = ? AND company_id = ?');
        $stmt->execute([$id, $companyId]);
        $old = $stmt->fetch();

        $pdo->beginTransaction();
        try {
            // Revert old sale effect (if any), then apply the new one
            if ($old && $old['batch_id'] && $old['quantity_sold']) {
                hef_adjust_batch_for_sale($pdo, $old['batch_id'], $old['quantity_sold'], $old['gender_sold'], +1);
            }
            if ($batchId && $newQty) {
                hef_adjust_batch_for_sale($pdo, $batchId, $newQty, $newGender, -1);
            }

            $pdo->prepare(
                'UPDATE income SET source=?, amount=?, date=?, batch_id=?, quantity_sold=?, gender_sold=?, notes=?, updated_at=NOW() WHERE id=? AND company_id=?'
            )->execute([$source, $amount, $date, $batchId, $newQty, $newGender, $notes ?: null, $id, $companyId]);

            $pdo->commit();
            header('Location: /hef/admin/finances.php');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('income update failed: ' . $e->getMessage());
            $error = 'Could not update income — check the quantity is available.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_income'])) {
    $source = $_POST['source'] ?? 'other';
    $amount = (float) ($_POST['amount'] ?? 0);
    $date = $_POST['date'] ?: date('Y-m-d');
    $batchId = $_POST['batch_id'] !== '' ? (int) $_POST['batch_id'] : null;
    $notes = trim($_POST['notes'] ?? '');
    $qtySold = $_POST['quantity_sold'] !== '' ? (int) $_POST['quantity_sold'] : null;
    $genderSold = $_POST['gender_sold'] ?: null;

    if ($amount <= 0) {
        $error = 'Amount must be greater than zero.';
    } else {
        $pdo->beginTransaction();
        try {
            if ($batchId && $qtySold) {
                $field = match ($genderSold) {
                    'male' => 'current_count_male',
                    'female' => 'current_count_female',
                    default => 'current_count_unknown',
                };
                $stmt = $pdo->prepare("SELECT {$field} AS avail FROM batches WHERE id = ? AND company_id = ?");
                $stmt->execute([$batchId, $companyId]);
                $row = $stmt->fetch();
                if (! $row || $qtySold > $row['avail']) {
                    throw new RuntimeException("Only {$row['avail']} available for that gender in this batch.");
                }
                hef_adjust_batch_for_sale($pdo, $batchId, $qtySold, $genderSold, -1);
            }

            $pdo->prepare(
                "INSERT INTO income (company_id, batch_id, source, amount, currency_code, date, quantity_sold, gender_sold, notes, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'INR', ?, ?, ?, ?, NOW(), NOW())"
            )->execute([$companyId, $batchId, $source, $amount, $date, $qtySold, $genderSold, $notes ?: null]);

            $pdo->commit();
            header('Location: /hef/admin/finances.php');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('income insert failed: ' . $e->getMessage());
            $error = $e->getMessage() ?: 'Could not save income.';
        }
    }
}

require_once __DIR__ . '/header.php';

// --- Totals ---
$stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM expenses WHERE company_id = ?');
$stmt->execute([$companyId]);
$totalExpenses = (float) $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM income WHERE company_id = ?');
$stmt->execute([$companyId]);
$totalIncome = (float) $stmt->fetchColumn();

$netPL = $totalIncome - $totalExpenses;

// --- By category (expenses) ---
$stmt = $pdo->prepare('SELECT category, SUM(amount) AS total FROM expenses WHERE company_id = ? GROUP BY category ORDER BY total DESC');
$stmt->execute([$companyId]);
$expensesByCategory = $stmt->fetchAll();

// --- By source (income) ---
$stmt = $pdo->prepare('SELECT source, SUM(amount) AS total FROM income WHERE company_id = ? GROUP BY source ORDER BY total DESC');
$stmt->execute([$companyId]);
$incomeBySource = $stmt->fetchAll();

// --- By batch P&L ---
$stmt = $pdo->prepare(
    "SELECT b.id, b.batch_code, s.name AS species_name,
        COALESCE((SELECT SUM(amount) FROM income WHERE batch_id = b.id), 0) AS batch_income,
        COALESCE((SELECT SUM(amount) FROM expenses WHERE batch_id = b.id), 0) AS batch_expenses
     FROM batches b JOIN species s ON s.id = b.species_id
     WHERE b.company_id = ?
     ORDER BY b.date_acquired DESC"
);
$stmt->execute([$companyId]);
$batchPL = $stmt->fetchAll();

// --- Recent transactions (merged) ---
$perPage = 15;
$page = hef_current_page();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM (
        (SELECT id FROM expenses WHERE company_id = ?)
        UNION ALL
        (SELECT id FROM income WHERE company_id = ?)
     ) t"
);
$stmt->execute([$companyId, $companyId]);
$totalTransactions = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "(SELECT 'expense' AS type, id, category AS label, amount, date, batch_id, NULL AS quantity_sold, NULL AS gender_sold, notes FROM expenses WHERE company_id = ?)
     UNION ALL
     (SELECT 'income' AS type, id, source AS label, amount, date, batch_id, quantity_sold, gender_sold, notes FROM income WHERE company_id = ?)
     ORDER BY date DESC, id DESC LIMIT {$perPage} OFFSET " . hef_offset($page, $perPage)
);
$stmt->execute([$companyId, $companyId]);
$recentTransactions = $stmt->fetchAll();

$expenseCategories = ['purchase', 'vaccination', 'feed', 'equipment', 'utility', 'labor', 'transportation', 'other'];
$incomeSources = ['animal_sale', 'egg_sale', 'meat_sale', 'booking'];

function hef_batch_label(array $options, ?int $id): string
{
    foreach ($options as $o) {
        if ($o['id'] == $id) {
            return $o['batch_code'];
        }
    }
    return '—';
}
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-cash-coin me-2"></i>Finances</h4>
    <div class="d-flex gap-2 flex-wrap">
        <a href="/hef/admin/export.php?type=finances" class="btn btn-outline-secondary btn-sm"><i class="bi bi-download me-1"></i>Export CSV</a>
        <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#expenseModal"><i class="bi bi-dash-circle me-1"></i>Add expense</button>
        <button class="btn btn-hef text-white btn-sm" data-bs-toggle="modal" data-bs-target="#incomeModal"><i class="bi bi-plus-circle me-1"></i>Add income</button>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-4">
        <div class="card p-3 text-center">
            <div class="fs-3 fw-bold text-success">₹<?= number_format($totalIncome, 0) ?></div>
            <div class="text-muted small">Total Income</div>
        </div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="card p-3 text-center">
            <div class="fs-3 fw-bold text-danger">₹<?= number_format($totalExpenses, 0) ?></div>
            <div class="text-muted small">Total Expenses</div>
        </div>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card p-3 text-center">
            <div class="fs-3 fw-bold <?= $netPL >= 0 ? 'text-success' : 'text-danger' ?>">₹<?= number_format($netPL, 0) ?></div>
            <div class="text-muted small">Net Profit / Loss</div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-lg-6">
        <h6 class="mb-2">Expenses by category</h6>
        <div class="card">
            <div class="list-group list-group-flush">
                <?php if (empty($expensesByCategory)): ?><div class="list-group-item text-muted small">None yet.</div><?php endif; ?>
                <?php foreach ($expensesByCategory as $row): ?>
                    <div class="list-group-item d-flex justify-content-between">
                        <span><?= ucfirst(str_replace('_', ' ', $row['category'])) ?></span>
                        <span class="fw-semibold">₹<?= number_format($row['total'], 0) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <h6 class="mb-2">Income by source</h6>
        <div class="card">
            <div class="list-group list-group-flush">
                <?php if (empty($incomeBySource)): ?><div class="list-group-item text-muted small">None yet.</div><?php endif; ?>
                <?php foreach ($incomeBySource as $row): ?>
                    <div class="list-group-item d-flex justify-content-between">
                        <span><?= ucfirst(str_replace('_', ' ', $row['source'])) ?></span>
                        <span class="fw-semibold">₹<?= number_format($row['total'], 0) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<h6 class="mb-2">Profit & loss by batch</h6>
<div class="card mb-4">
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead>
                <tr><th>Batch</th><th>Species</th><th class="text-end">Income</th><th class="text-end">Expenses</th><th class="text-end">Net</th></tr>
            </thead>
            <tbody>
                <?php if (empty($batchPL)): ?>
                    <tr><td colspan="5" class="text-muted text-center py-3">No batches yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($batchPL as $bp): ?>
                    <?php $net = $bp['batch_income'] - $bp['batch_expenses']; ?>
                    <tr>
                        <td><a href="/hef/admin/batch-view.php?id=<?= $bp['id'] ?>"><?= htmlspecialchars($bp['batch_code']) ?></a></td>
                        <td class="text-muted"><?= htmlspecialchars($bp['species_name']) ?></td>
                        <td class="text-end text-success">₹<?= number_format($bp['batch_income'], 0) ?></td>
                        <td class="text-end text-danger">₹<?= number_format($bp['batch_expenses'], 0) ?></td>
                        <td class="text-end fw-semibold <?= $net >= 0 ? 'text-success' : 'text-danger' ?>">₹<?= number_format($net, 0) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<h6 class="mb-2">Recent transactions</h6>
<div class="card">
    <div class="list-group list-group-flush">
        <?php if (empty($recentTransactions)): ?><div class="list-group-item text-muted small">None yet.</div><?php endif; ?>
        <?php foreach ($recentTransactions as $t): ?>
            <div class="list-group-item d-flex justify-content-between align-items-center">
                <div>
                    <span class="badge <?= $t['type'] === 'income' ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' ?> me-2"><?= $t['type'] === 'income' ? 'Income' : 'Expense' ?></span>
                    <?= ucfirst(str_replace('_', ' ', $t['label'])) ?>
                    <?php if ($t['batch_id']): ?><span class="text-muted small">— <?= htmlspecialchars(hef_batch_label($batchOptions, $t['batch_id'])) ?></span><?php endif; ?>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="fw-semibold <?= $t['type'] === 'income' ? 'text-success' : 'text-danger' ?>">₹<?= number_format($t['amount'], 0) ?></span>
                    <span class="text-muted small"><?= htmlspecialchars($t['date']) ?></span>
                    <button class="btn btn-sm btn-outline-secondary py-0 px-1" data-bs-toggle="modal" data-bs-target="#edit<?= ucfirst($t['type']) ?><?= $t['id'] ?>"><i class="bi bi-pencil"></i></button>
                    <form method="POST" onsubmit="return confirm('Delete this <?= $t['type'] ?> entry?<?= $t['quantity_sold'] ? ' This will add ' . (int) $t['quantity_sold'] . ' back to the batch count.' : '' ?>');" class="d-inline">
                        <input type="hidden" name="delete_<?= $t['type'] ?>_id" value="<?= $t['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1"><i class="bi bi-trash"></i></button>
                    </form>
                </div>
            </div>

            <div class="modal fade" id="edit<?= ucfirst($t['type']) ?><?= $t['id'] ?>" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <form method="POST">
                            <input type="hidden" name="edit_<?= $t['type'] ?>_id" value="<?= $t['id'] ?>">
                            <div class="modal-header">
                                <h5 class="modal-title">Edit <?= $t['type'] ?></h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="mb-2">
                                    <label class="form-label small"><?= $t['type'] === 'income' ? 'Source' : 'Category' ?></label>
                                    <select name="<?= $t['type'] === 'income' ? 'source' : 'category' ?>" class="form-select">
                                        <?php foreach (($t['type'] === 'income' ? $incomeSources : $expenseCategories) as $opt): ?>
                                            <option value="<?= $opt ?>" <?= $opt === $t['label'] ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $opt)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small">Amount (₹)</label>
                                    <input type="number" step="0.01" name="amount" class="form-control" required value="<?= htmlspecialchars((string) $t['amount']) ?>">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small">Batch (optional)</label>
                                    <select name="batch_id" class="form-select">
                                        <option value="">None</option>
                                        <?php foreach ($batchOptions as $b): ?>
                                            <option value="<?= $b['id'] ?>" <?= $b['id'] == $t['batch_id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['batch_code']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small">Date</label>
                                    <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($t['date']) ?>">
                                </div>
                                <?php if ($t['type'] === 'income'): ?>
                                <div class="row g-2 mb-2">
                                    <div class="col-6">
                                        <label class="form-label small">Qty sold (if animal/meat)</label>
                                        <input type="number" name="quantity_sold" class="form-control" min="0" value="<?= htmlspecialchars((string) ($t['quantity_sold'] ?? '')) ?>">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small">Gender sold</label>
                                        <select name="gender_sold" class="form-select">
                                            <option value="">—</option>
                                            <?php foreach (['male', 'female', 'unknown'] as $g): ?>
                                                <option value="<?= $g ?>" <?= $g === $t['gender_sold'] ? 'selected' : '' ?>><?= ucfirst($g) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="alert alert-info small py-2 mb-2">Changing quantity/gender automatically adjusts the batch's live count.</div>
                                <?php endif; ?>
                                <div class="mb-2">
                                    <label class="form-label small">Notes</label>
                                    <textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars($t['notes'] ?? '') ?></textarea>
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
<?= hef_pagination_links($page, $totalTransactions, $perPage) ?>

<!-- Add Expense Modal -->
<div class="modal fade" id="expenseModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="add_expense" value="1">
                <div class="modal-header">
                    <h5 class="modal-title">Add expense</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                    <div class="mb-2">
                        <label class="form-label small">Category</label>
                        <select name="category" class="form-select">
                            <?php foreach ($expenseCategories as $c): ?>
                                <option value="<?= $c ?>"><?= ucfirst(str_replace('_', ' ', $c)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Amount (₹)</label>
                        <input type="number" step="0.01" name="amount" class="form-control" required min="0.01">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Batch (optional)</label>
                        <select name="batch_id" class="form-select">
                            <option value="">None</option>
                            <?php foreach ($batchOptions as $b): ?>
                                <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['batch_code']) ?></option>
                            <?php endforeach; ?>
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
                    <button type="submit" class="btn btn-hef text-white w-100">Save expense</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Income Modal -->
<div class="modal fade" id="incomeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="add_income" value="1">
                <div class="modal-header">
                    <h5 class="modal-title">Add income</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label small">Source</label>
                        <select name="source" class="form-select">
                            <?php foreach ($incomeSources as $s): ?>
                                <option value="<?= $s ?>"><?= ucfirst(str_replace('_', ' ', $s)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Amount (₹)</label>
                        <input type="number" step="0.01" name="amount" class="form-control" required min="0.01">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Batch (optional)</label>
                        <select name="batch_id" class="form-select">
                            <option value="">None</option>
                            <?php foreach ($batchOptions as $b): ?>
                                <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['batch_code']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small">Qty sold (if animal/meat)</label>
                            <input type="number" name="quantity_sold" class="form-control" min="0" placeholder="e.g. 2">
                        </div>
                        <div class="col-6">
                            <label class="form-label small">Gender sold</label>
                            <select name="gender_sold" class="form-select">
                                <option value="">—</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                                <option value="unknown">Unknown</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-text mb-2" style="margin-top:-8px;">
                        For animal/meat sales: fill this in and the batch's live count will be reduced automatically. Leave blank for egg sales or anything not reducing headcount.
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
                    <button type="submit" class="btn btn-hef text-white w-100">Save income</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
