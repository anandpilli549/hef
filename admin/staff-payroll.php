<?php
require_once __DIR__ . '/bootstrap.php';
hef_require_pro($pdo, $currentUser); // Staff & Payroll is a Pro feature (Owner only)

// Monthly payroll. Work for a month is paid on the company's salary day of the
// FOLLOWING month, so the page opens on last month. See includes/payroll.php
// for how every figure is worked out.

$companyId = (int) $currentUser['company_id'];
hef_reconcile_staff_periods($pdo, $companyId);
$settings = hef_payroll_settings($pdo, $companyId);
$today = hef_company_today($settings['timezone']);
$lastMonth = (new DateTimeImmutable($today))->modify('first day of last month')->format('Y-m-d');
$month = hef_month_start($_POST['month'] ?? $_GET['month'] ?? null, $lastMonth);
$monthLabel = date('F Y', strtotime($month));
$dueDate = hef_salary_due_date($month, $settings['salary_day']);

$flash = function (string $type, string $message) use ($month) {
    $_SESSION['payroll_flash'] = [$type, $message];
    header('Location: /hef/admin/staff-payroll.php?month=' . substr($month, 0, 7));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['generate'])) {
        $r = hef_payroll_generate($pdo, $companyId, $month);
        $flash('ok', 'Payroll for ' . $monthLabel . ' calculated: ' . $r['created'] . ' new, ' . $r['updated'] . ' refreshed'
            . ($r['skipped_paid'] ? ', ' . $r['skipped_paid'] . ' already paid (left as they are)' : '') . '.');
    }

    if (isset($_POST['pay_payroll_id'])) {
        $err = hef_payroll_mark_paid($pdo, $companyId, (int) $_POST['pay_payroll_id'], (string) ($_POST['paid_on'] ?? ''), (string) ($_POST['payment_mode'] ?? ''));
        $flash($err ? 'err' : 'ok', $err ?: 'Marked as paid and booked in Finances.');
    }

    if (isset($_POST['pay_all'])) {
        $stmt = $pdo->prepare("SELECT id FROM staff_payroll WHERE company_id = ? AND month = ? AND status = 'draft'");
        $stmt->execute([$companyId, $month]);
        $n = 0;
        $err = null;
        foreach ($stmt->fetchAll() as $row) {
            $err = hef_payroll_mark_paid($pdo, $companyId, (int) $row['id'], (string) ($_POST['paid_on'] ?? ''), (string) ($_POST['payment_mode'] ?? ''));
            if ($err) {
                break;
            }
            $n++;
        }
        $flash($err ? 'err' : 'ok', $err ?: $n . ' salaries marked as paid and booked in Finances.');
    }

    if (isset($_POST['reopen_payroll_id'])) {
        $err = hef_payroll_reopen($pdo, $companyId, (int) $_POST['reopen_payroll_id']);
        $flash($err ? 'err' : 'ok', $err ?: 'Payment undone. Its expense was removed from Finances and advance recoveries were put back.');
    }

    if (isset($_POST['edit_payroll_id'])) {
        $id = (int) $_POST['edit_payroll_id'];
        $stmt = $pdo->prepare('SELECT p.*, s.* , p.id AS payroll_id, p.status AS payroll_status FROM staff_payroll p JOIN staff s ON s.id = p.staff_id WHERE p.id = ? AND p.company_id = ?');
        $stmt->execute([$id, $companyId]);
        $row = $stmt->fetch();
        if (! $row) {
            $flash('err', 'Payroll entry not found.');
        }
        if ($row['payroll_status'] === 'paid') {
            $flash('err', 'This one is already paid. Undo the payment first to change it.');
        }
        $recoveryRaw = trim((string) ($_POST['advance_recovery'] ?? ''));
        $manual = $recoveryRaw === '' ? null : (float) $recoveryRaw;
        $calc = hef_payroll_compute($pdo, $settings, $row, $month, $manual);
        if ($calc === null) {
            $flash('err', 'This person wasn\'t working in ' . $monthLabel . '.');
        }
        $pdo->prepare(
            'UPDATE staff_payroll SET base_salary = ?, deduction_days = ?, attendance_deduction = ?, additions = ?, other_deductions = ?,
                    advance_recovery = ?, recovery_manual = ?, net_pay = ?, notes = ?, updated_at = NOW() WHERE id = ?'
        )->execute([
            $calc['base_salary'], $calc['deduction_days'], $calc['attendance_deduction'], $calc['additions'], $calc['other_deductions'],
            $calc['advance_recovery'], $manual !== null ? 1 : 0, $calc['net_pay'], trim((string) ($_POST['notes'] ?? '')) !== '' ? substr(trim($_POST['notes']), 0, 255) : ($calc['note'] ?? null), $id,
        ]);
        $flash('ok', 'Saved.');
    }

    if (isset($_POST['add_adjustment'])) {
        $staffId = (int) $_POST['add_adjustment'];
        $kind = ($_POST['kind'] ?? '') === 'deduction' ? 'deduction' : 'addition';
        $label = trim((string) ($_POST['label'] ?? ''));
        $amount = (float) ($_POST['amount'] ?? 0);

        $stmt = $pdo->prepare("SELECT status FROM staff_payroll WHERE staff_id = ? AND month = ? AND company_id = ?");
        $stmt->execute([$staffId, $month, $companyId]);
        $payStatus = $stmt->fetchColumn();
        $stmt = $pdo->prepare('SELECT id FROM staff WHERE id = ? AND company_id = ?');
        $stmt->execute([$staffId, $companyId]);

        if (! $stmt->fetchColumn()) {
            $flash('err', 'Staff member not found.');
        } elseif ($payStatus === 'paid') {
            $flash('err', 'That salary is already paid. Undo the payment first to change it.');
        } elseif ($label === '' || strlen($label) > 100 || $amount <= 0 || $amount > 9999999.99) {
            $flash('err', 'Enter what it is for and an amount.');
        }
        $pdo->prepare(
            'INSERT INTO staff_adjustments (company_id, staff_id, month, kind, label, amount, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
        )->execute([$companyId, $staffId, $month, $kind, $label, $amount]);
        hef_payroll_generate($pdo, $companyId, $month, $staffId);
        $flash('ok', ($kind === 'addition' ? 'Addition' : 'Deduction') . ' added and payroll refreshed.');
    }

    if (isset($_POST['delete_adjustment_id'])) {
        $stmt = $pdo->prepare(
            "SELECT a.id, a.staff_id, (SELECT status FROM staff_payroll p WHERE p.staff_id = a.staff_id AND p.month = a.month) AS pay_status
             FROM staff_adjustments a WHERE a.id = ? AND a.company_id = ?"
        );
        $stmt->execute([(int) $_POST['delete_adjustment_id'], $companyId]);
        $adj = $stmt->fetch();
        if (! $adj) {
            $flash('err', 'Entry not found.');
        }
        if ($adj['pay_status'] === 'paid') {
            $flash('err', 'That salary is already paid. Undo the payment first to change it.');
        }
        $pdo->prepare('DELETE FROM staff_adjustments WHERE id = ?')->execute([$adj['id']]);
        hef_payroll_generate($pdo, $companyId, $month, (int) $adj['staff_id']);
        $flash('ok', 'Removed and payroll refreshed.');
    }
}

[$flashType, $flashMsg] = $_SESSION['payroll_flash'] ?? [null, null];
unset($_SESSION['payroll_flash']);

$stmt = $pdo->prepare(
    'SELECT p.*, s.name, s.designation, s.monthly_salary,
            COALESCE((SELECT SUM(a.amount - a.recovered) FROM staff_advances a WHERE a.staff_id = s.id), 0) AS advance_balance
     FROM staff_payroll p JOIN staff s ON s.id = p.staff_id
     WHERE p.company_id = ? AND p.month = ? ORDER BY s.name'
);
$stmt->execute([$companyId, $month]);
$rows = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT * FROM staff_adjustments WHERE company_id = ? AND month = ? ORDER BY id');
$stmt->execute([$companyId, $month]);
$adjustmentsByStaff = [];
foreach ($stmt->fetchAll() as $a) {
    $adjustmentsByStaff[(int) $a['staff_id']][] = $a;
}

$totals = ['base' => 0, 'att' => 0, 'add' => 0, 'ded' => 0, 'adv' => 0, 'net' => 0, 'paid' => 0, 'pending' => 0];
$draftCount = 0;
$rowStaffIds = [];
foreach ($rows as $r) {
    $rowStaffIds[(int) $r['staff_id']] = true;
    $totals['base'] += $r['base_salary'];
    $totals['att'] += $r['attendance_deduction'];
    $totals['add'] += $r['additions'];
    $totals['ded'] += $r['other_deductions'];
    $totals['adv'] += $r['advance_recovery'];
    $totals['net'] += $r['net_pay'];
    if ($r['status'] === 'paid') {
        $totals['paid'] += $r['net_pay'];
    } else {
        $totals['pending'] += $r['net_pay'];
        $draftCount++;
    }
}

// Staff who should have a row for this month but don't yet.
$missing = 0;
foreach (hef_staff_for_month($pdo, $companyId, $month) as $s) {
    if (! isset($rowStaffIds[(int) $s['id']]) && hef_staff_month_window($pdo, $s, $month) !== null) {
        $missing++;
    }
}

$prev = (new DateTimeImmutable($month))->modify('-1 month')->format('Y-m');
$next = (new DateTimeImmutable($month))->modify('+1 month')->format('Y-m');
$dueIn = (int) (new DateTimeImmutable($today))->diff(new DateTimeImmutable($dueDate))->format('%r%a');

require_once __DIR__ . '/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-cash-stack me-2"></i>Payroll</h4>
    <div class="d-flex gap-2 align-items-center">
        <a href="?month=<?= $prev ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i></a>
        <strong><?= $monthLabel ?></strong>
        <a href="?month=<?= $next ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-right"></i></a>
    </div>
</div>

<?php if ($flashMsg): ?><div class="alert alert-<?= $flashType === 'err' ? 'danger' : 'success' ?> py-2"><?= htmlspecialchars($flashMsg) ?></div><?php endif; ?>

<div class="alert alert-light border d-flex justify-content-between align-items-center flex-wrap gap-2 py-2">
    <span>
        <i class="bi bi-calendar-event me-1"></i>Salary for <strong><?= $monthLabel ?></strong> is due on <strong><?= date('d M Y', strtotime($dueDate)) ?></strong>
        <span class="text-muted">(<?= $dueIn > 0 ? 'in ' . $dueIn . ' day' . ($dueIn === 1 ? '' : 's') : ($dueIn === 0 ? 'today' : abs($dueIn) . ' day' . (abs($dueIn) === 1 ? '' : 's') . ' ago') ?>)</span>
        · <a href="/hef/admin/settings.php">change salary day</a>
    </span>
    <span class="d-flex gap-2">
        <form method="POST" class="d-inline"><input type="hidden" name="month" value="<?= substr($month, 0, 7) ?>"><input type="hidden" name="generate" value="1">
            <button type="submit" class="btn btn-sm btn-hef text-white"><i class="bi bi-calculator me-1"></i><?= $rows ? 'Recalculate' : 'Calculate payroll' ?></button>
        </form>
        <?php if ($draftCount > 0): ?>
            <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#payAllModal"><i class="bi bi-check2-all me-1"></i>Mark all paid</button>
        <?php endif; ?>
        <?php if ($rows): ?>
            <a href="/hef/admin/export.php?type=payroll&amp;month=<?= substr($month, 0, 7) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-download me-1"></i>Export CSV</a>
        <?php endif; ?>
    </span>
</div>

<?php if ($missing > 0): ?>
    <div class="alert alert-warning py-2 small"><?= $missing ?> staff member<?= $missing === 1 ? '' : 's' ?> not calculated yet for <?= $monthLabel ?> — press <strong>Calculate payroll</strong>.</div>
<?php endif; ?>

<?php if ($rows): ?>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card p-3 text-center"><div class="fs-5 fw-bold"><?= hef_money($totals['base']) ?></div><div class="text-muted small">Basic salaries</div></div></div>
    <div class="col-6 col-lg-3"><div class="card p-3 text-center"><div class="fs-5 fw-bold text-danger">− <?= hef_money($totals['att'] + $totals['ded'] + $totals['adv']) ?></div><div class="text-muted small">Absences, deductions &amp; advances</div></div></div>
    <div class="col-6 col-lg-3"><div class="card p-3 text-center"><div class="fs-5 fw-bold"><?= hef_money($totals['net']) ?></div><div class="text-muted small">Net payable</div></div></div>
    <div class="col-6 col-lg-3"><div class="card p-3 text-center"><div class="fs-5 fw-bold text-success"><?= hef_money($totals['paid']) ?></div><div class="text-muted small">Paid · <?= hef_money($totals['pending']) ?> pending</div></div></div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table align-middle mb-0" style="font-size:0.9rem;">
            <thead class="table-light">
                <tr>
                    <th>Staff</th><th class="text-end">Basic</th><th class="text-end">Absences</th><th class="text-end">Additions</th>
                    <th class="text-end">Deductions</th><th class="text-end">Advance</th><th class="text-end">Net pay</th><th>Status</th><th style="width:1%;"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td>
                            <div class="fw-semibold"><?= htmlspecialchars($r['name']) ?></div>
                            <?php if ($r['notes']): ?><div class="small text-muted"><?= htmlspecialchars($r['notes']) ?></div><?php endif; ?>
                        </td>
                        <td class="text-end"><?= hef_money($r['base_salary']) ?></td>
                        <td class="text-end"><?= (float) $r['attendance_deduction'] > 0 ? '<span class="text-danger">−' . hef_money($r['attendance_deduction']) . '</span><div class="small text-muted">' . rtrim(rtrim(number_format((float) $r['deduction_days'], 1), '0'), '.') . ' day(s)</div>' : '—' ?></td>
                        <td class="text-end"><?= (float) $r['additions'] > 0 ? '<span class="text-success">+' . hef_money($r['additions']) . '</span>' : '—' ?></td>
                        <td class="text-end"><?= (float) $r['other_deductions'] > 0 ? '<span class="text-danger">−' . hef_money($r['other_deductions']) . '</span>' : '—' ?></td>
                        <td class="text-end"><?= (float) $r['advance_recovery'] > 0 ? '<span class="text-danger">−' . hef_money($r['advance_recovery']) . '</span>' : '—' ?></td>
                        <td class="text-end fw-bold"><?= hef_money($r['net_pay']) ?></td>
                        <td>
                            <?php if ($r['status'] === 'paid'): ?>
                                <span class="badge bg-success">Paid</span><div class="small text-muted"><?= date('d M', strtotime($r['paid_on'])) ?> · <?= htmlspecialchars($r['payment_mode']) ?></div>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="/hef/admin/staff-payslip.php?id=<?= (int) $r['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Payslip"><i class="bi bi-receipt"></i></a>
                                <button type="button" class="btn btn-sm btn-outline-secondary" title="Bonus, overtime, fines" data-bs-toggle="modal" data-bs-target="#adjust<?= (int) $r['id'] ?>"><i class="bi bi-plus-slash-minus"></i></button>
                                <?php if ($r['status'] === 'draft'): ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" title="Edit advance recovery / note" data-bs-toggle="modal" data-bs-target="#editPay<?= (int) $r['id'] ?>"><i class="bi bi-pencil"></i></button>
                                    <button type="button" class="btn btn-sm btn-success" title="Mark paid" data-bs-toggle="modal" data-bs-target="#pay<?= (int) $r['id'] ?>"><i class="bi bi-check-lg"></i></button>
                                <?php else: ?>
                                    <form method="POST" onsubmit="return confirm('Undo this payment? Its expense is removed from Finances and any advance recovered goes back on the balance.');">
                                        <input type="hidden" name="month" value="<?= substr($month, 0, 7) ?>"><input type="hidden" name="reopen_payroll_id" value="<?= (int) $r['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-warning" title="Undo payment"><i class="bi bi-arrow-counterclockwise"></i></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<p class="text-muted small mt-3 mb-0">
    A day's pay is the monthly salary divided by <?= $settings['salary_divisor'] > 0 ? (int) $settings['salary_divisor'] : 'the actual days in the month' ?>.
    Recalculate after changing attendance, advances or staff details. Paid rows never change. Marking a salary paid books it as a "Labor" expense in Finances.
</p>
<?php elseif ($missing === 0): ?>
    <div class="card p-4 text-muted">No staff were working in <?= $monthLabel ?>. <a href="/hef/admin/staff.php">Add staff</a></div>
<?php endif; ?>

<?php foreach ($rows as $r): ?>
    <?php $adjs = $adjustmentsByStaff[(int) $r['staff_id']] ?? []; ?>
    <!-- Bonus / overtime / fines -->
    <div class="modal fade" id="adjust<?= (int) $r['id'] ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title"><?= htmlspecialchars($r['name']) ?> — <?= $monthLabel ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <?php if ($adjs): ?>
                        <ul class="list-group mb-3">
                            <?php foreach ($adjs as $a): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><?= $a['kind'] === 'addition' ? '<span class="text-success">+</span>' : '<span class="text-danger">−</span>' ?> <?= htmlspecialchars($a['label']) ?> <strong><?= hef_money($a['amount']) ?></strong></span>
                                    <?php if ($r['status'] === 'draft'): ?>
                                        <form method="POST"><input type="hidden" name="month" value="<?= substr($month, 0, 7) ?>"><input type="hidden" name="delete_adjustment_id" value="<?= (int) $a['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?><p class="text-muted small">Nothing added yet for this month.</p><?php endif; ?>
                    <?php if ($r['status'] === 'draft'): ?>
                        <form method="POST">
                            <input type="hidden" name="month" value="<?= substr($month, 0, 7) ?>"><input type="hidden" name="add_adjustment" value="<?= (int) $r['staff_id'] ?>">
                            <div class="row g-2">
                                <div class="col-12 col-sm-4"><select name="kind" class="form-select"><option value="addition">Add (bonus, overtime…)</option><option value="deduction">Deduct (fine, damage…)</option></select></div>
                                <div class="col-7 col-sm-5"><input type="text" name="label" class="form-control" maxlength="100" required placeholder="e.g. Overtime"></div>
                                <div class="col-5 col-sm-3"><input type="number" name="amount" class="form-control" min="1" step="0.01" required placeholder="₹"></div>
                            </div>
                            <button type="submit" class="btn btn-hef text-white btn-sm mt-2">Add</button>
                        </form>
                    <?php else: ?><p class="text-muted small mb-0">Already paid. Undo the payment to change this.</p><?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($r['status'] === 'draft'): ?>
    <!-- Edit advance recovery / note -->
    <div class="modal fade" id="editPay<?= (int) $r['id'] ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="month" value="<?= substr($month, 0, 7) ?>"><input type="hidden" name="edit_payroll_id" value="<?= (int) $r['id'] ?>">
                    <div class="modal-header"><h5 class="modal-title"><?= htmlspecialchars($r['name']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <div class="mb-2">
                            <label class="form-label small">Advance to recover this month (₹)</label>
                            <input type="number" name="advance_recovery" class="form-control" min="0" step="0.01" value="<?= $r['recovery_manual'] ? htmlspecialchars((string) $r['advance_recovery']) : '' ?>" placeholder="Automatic: <?= htmlspecialchars((string) $r['advance_recovery']) ?>">
                            <div class="form-text">Advances still owed: <strong><?= hef_money($r['advance_balance']) ?></strong>. Leave empty to use the automatic instalment. It can't take pay below zero.</div>
                        </div>
                        <div class="mb-2"><label class="form-label small">Note on the payslip</label><input type="text" name="notes" class="form-control" maxlength="255" value="<?= htmlspecialchars((string) $r['notes']) ?>"></div>
                    </div>
                    <div class="modal-footer"><button type="submit" class="btn btn-hef text-white w-100">Save</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Mark paid -->
    <div class="modal fade" id="pay<?= (int) $r['id'] ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="month" value="<?= substr($month, 0, 7) ?>"><input type="hidden" name="pay_payroll_id" value="<?= (int) $r['id'] ?>">
                    <div class="modal-header"><h5 class="modal-title">Pay <?= htmlspecialchars($r['name']) ?> <?= hef_money($r['net_pay']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <div class="row g-2">
                            <div class="col-6"><label class="form-label small">Paid on</label><input type="date" name="paid_on" class="form-control" required value="<?= htmlspecialchars($today) ?>"></div>
                            <div class="col-6"><label class="form-label small">How</label>
                                <select name="payment_mode" class="form-select"><option value="cash">Cash</option><option value="bank">Bank transfer</option><option value="upi">UPI</option><option value="other">Other</option></select></div>
                        </div>
                        <div class="form-text mt-2">This is booked as a "Labor" expense in Finances.</div>
                    </div>
                    <div class="modal-footer"><button type="submit" class="btn btn-success w-100">Mark as paid</button></div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
<?php endforeach; ?>

<?php if ($draftCount > 0): ?>
<div class="modal fade" id="payAllModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="month" value="<?= substr($month, 0, 7) ?>"><input type="hidden" name="pay_all" value="1">
                <div class="modal-header"><h5 class="modal-title">Mark all <?= $draftCount ?> pending as paid</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <p class="small mb-2">Total <strong><?= hef_money($totals['pending']) ?></strong> for <?= $monthLabel ?>.</p>
                    <div class="row g-2">
                        <div class="col-6"><label class="form-label small">Paid on</label><input type="date" name="paid_on" class="form-control" required value="<?= htmlspecialchars($today) ?>"></div>
                        <div class="col-6"><label class="form-label small">How</label>
                            <select name="payment_mode" class="form-select"><option value="cash">Cash</option><option value="bank">Bank transfer</option><option value="upi">UPI</option><option value="other">Other</option></select></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="submit" class="btn btn-success w-100">Mark all as paid</button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
