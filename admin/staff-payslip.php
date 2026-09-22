<?php
require_once __DIR__ . '/bootstrap.php';
hef_require_pro($pdo, $currentUser); // Staff & Payroll is a Pro feature (Owner only)

// A printable payslip for one payroll row (use the browser's Print / Save as PDF).

$companyId = (int) $currentUser['company_id'];
$stmt = $pdo->prepare(
    'SELECT p.*, s.name, s.designation, s.phone, s.joined_on, s.monthly_salary
     FROM staff_payroll p JOIN staff s ON s.id = p.staff_id
     WHERE p.id = ? AND p.company_id = ?'
);
$stmt->execute([(int) ($_GET['id'] ?? 0), $companyId]);
$p = $stmt->fetch();
if (! $p) {
    http_response_code(404);
    die('Payslip not found.');
}

$settings = hef_payroll_settings($pdo, $companyId);
$stmt = $pdo->prepare('SELECT kind, label, amount FROM staff_adjustments WHERE staff_id = ? AND month = ? ORDER BY id');
$stmt->execute([$p['staff_id'], $p['month']]);
$adjustments = $stmt->fetchAll();

$earnings = [['Basic salary' . ($p['notes'] && strpos($p['notes'], 'Pro-rated') === 0 ? ' (' . $p['notes'] . ')' : ''), $p['base_salary']]];
$deductions = [];
foreach ($adjustments as $a) {
    if ($a['kind'] === 'addition') {
        $earnings[] = [$a['label'], $a['amount']];
    } else {
        $deductions[] = [$a['label'], $a['amount']];
    }
}
if ((float) $p['attendance_deduction'] > 0) {
    $deductions[] = ['Absences (' . rtrim(rtrim(number_format((float) $p['deduction_days'], 1), '0'), '.') . ' day(s))', $p['attendance_deduction']];
}
if ((float) $p['advance_recovery'] > 0) {
    $deductions[] = ['Advance recovery', $p['advance_recovery']];
}
$totalEarnings = array_sum(array_column($earnings, 1));
$totalDeductions = array_sum(array_column($deductions, 1));
$rowsCount = max(count($earnings), count($deductions));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payslip — <?= htmlspecialchars($p['name']) ?> — <?= date('F Y', strtotime($p['month'])) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f4f5f7; }
        .slip { max-width: 760px; margin: 24px auto; background: #fff; padding: 32px; border: 1px solid #ddd; }
        @media print { body { background: #fff; } .slip { border: 0; margin: 0; max-width: none; padding: 0; } .no-print { display: none !important; } }
    </style>
</head>
<body>
<div class="slip">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h4 class="mb-0"><?= htmlspecialchars($settings['name']) ?></h4>
            <div class="text-muted">Payslip for <?= date('F Y', strtotime($p['month'])) ?></div>
        </div>
        <button class="btn btn-outline-secondary btn-sm no-print" onclick="window.print()">Print / Save as PDF</button>
    </div>

    <table class="table table-sm table-borderless mb-3">
        <tr><td class="text-muted" style="width:25%;">Employee</td><td class="fw-semibold"><?= htmlspecialchars($p['name']) ?></td><td class="text-muted" style="width:25%;">Designation</td><td><?= htmlspecialchars((string) $p['designation']) ?: '—' ?></td></tr>
        <tr><td class="text-muted">Joined</td><td><?= $p['joined_on'] ? date('d M Y', strtotime($p['joined_on'])) : '—' ?></td><td class="text-muted">Monthly salary</td><td><?= hef_money($p['monthly_salary']) ?></td></tr>
        <tr><td class="text-muted">Status</td><td><?= $p['status'] === 'paid' ? 'Paid on ' . date('d M Y', strtotime($p['paid_on'])) . ' (' . htmlspecialchars($p['payment_mode']) . ')' : 'Not paid yet' ?></td><td class="text-muted">Salary day</td><td><?= date('d M Y', strtotime(hef_salary_due_date($p['month'], $settings['salary_day']))) ?></td></tr>
    </table>

    <table class="table table-bordered align-middle">
        <thead class="table-light"><tr><th>Earnings</th><th class="text-end" style="width:16%;">Amount</th><th>Deductions</th><th class="text-end" style="width:16%;">Amount</th></tr></thead>
        <tbody>
            <?php for ($i = 0; $i < $rowsCount; $i++): ?>
                <tr>
                    <td><?= isset($earnings[$i]) ? htmlspecialchars($earnings[$i][0]) : '' ?></td>
                    <td class="text-end"><?= isset($earnings[$i]) ? hef_money($earnings[$i][1]) : '' ?></td>
                    <td><?= isset($deductions[$i]) ? htmlspecialchars($deductions[$i][0]) : '' ?></td>
                    <td class="text-end"><?= isset($deductions[$i]) ? hef_money($deductions[$i][1]) : '' ?></td>
                </tr>
            <?php endfor; ?>
        </tbody>
        <tfoot>
            <tr class="fw-semibold"><td>Total earnings</td><td class="text-end"><?= hef_money($totalEarnings) ?></td><td>Total deductions</td><td class="text-end"><?= hef_money($totalDeductions) ?></td></tr>
            <tr class="table-light fs-5"><td colspan="3" class="fw-bold">Net pay</td><td class="text-end fw-bold"><?= hef_money($p['net_pay']) ?></td></tr>
        </tfoot>
    </table>

    <div class="row mt-5 pt-4 text-center text-muted small">
        <div class="col-6"><div class="border-top pt-1 mx-4">Employee signature</div></div>
        <div class="col-6"><div class="border-top pt-1 mx-4">Employer signature</div></div>
    </div>
</div>
</body>
</html>
