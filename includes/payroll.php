<?php
/**
 * Staff payroll: how a month's salary is worked out, paid and reversed.
 *
 * Payroll is for a WORK month (e.g. September) and is paid on the company's
 * salary day of the FOLLOWING month (default the 5th). For each staff member:
 *
 *   base pay      = monthly salary (pro-rated by calendar days if they joined
 *                   or left part-way through the month)
 *   - absences    = a day's pay x deduction days, where a day's pay is the
 *                   monthly salary / the company's divisor (actual days in the
 *                   month by default, or a fixed 30 / 26). Absent and unpaid
 *                   leave count 1 day, a half day counts 0.5. A day with no
 *                   attendance mark counts as present.
 *   + additions   = bonus / overtime / allowance entered for that month
 *   - deductions  = fines etc. entered for that month
 *   - advances    = instalments of salary advances still outstanding
 *   = net pay
 *
 * Draft rows are recalculated on demand; marking a row paid books the net
 * amount as a "labor" expense in Finances and applies the advance recoveries.
 * A salary advance is booked as a "labor" expense when it is given, so the
 * recovery later is not an expense again and totals stay right.
 *
 * Attendance only records EXCEPTIONS (absent, half day, leave, week off);
 * every other day is present.
 *
 * Staff & Payroll is a Pro feature (see hef_require_pro / hef_company_has_pro).
 */

const HEF_ATTENDANCE_STATUSES = [
    'present' => ['label' => 'Present', 'code' => 'P', 'deduct' => 0.0, 'badge' => 'bg-success'],
    'absent' => ['label' => 'Absent', 'code' => 'A', 'deduct' => 1.0, 'badge' => 'bg-danger'],
    'half_day' => ['label' => 'Half day', 'code' => 'H', 'deduct' => 0.5, 'badge' => 'bg-warning text-dark'],
    'paid_leave' => ['label' => 'Paid leave', 'code' => 'PL', 'deduct' => 0.0, 'badge' => 'bg-info text-dark'],
    'unpaid_leave' => ['label' => 'Unpaid leave', 'code' => 'UL', 'deduct' => 1.0, 'badge' => 'bg-secondary'],
    'week_off' => ['label' => 'Week off', 'code' => 'WO', 'deduct' => 0.0, 'badge' => 'bg-light text-dark border'],
];

/** 'YYYY-MM' (or any date) -> first day of that month, 'YYYY-MM-01'; $default when invalid. */
function hef_month_start(?string $value, string $default): string
{
    if ($value !== null && preg_match('/^(\d{4})-(\d{2})(-\d{2})?$/', $value, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
        return $m[1] . '-' . $m[2] . '-01';
    }

    return substr($default, 0, 8) . '01';
}

/** The day salary is paid for a work month: the salary day of the next month (short months use their last day). */
function hef_salary_due_date(string $monthStart, int $salaryDay): string
{
    $next = (new DateTimeImmutable($monthStart))->modify('first day of next month');
    $day = max(1, min($salaryDay, (int) $next->format('t')));

    return $next->format('Y-m-') . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
}

/** Company payroll settings. */
function hef_payroll_settings(PDO $pdo, int $companyId): array
{
    $stmt = $pdo->prepare('SELECT name, timezone, currency_code, salary_day, salary_divisor FROM companies WHERE id = ?');
    $stmt->execute([$companyId]);
    $row = $stmt->fetch() ?: [];

    return [
        'name' => $row['name'] ?? '',
        'timezone' => $row['timezone'] ?? 'Asia/Kolkata',
        'currency' => $row['currency_code'] ?? 'INR',
        'salary_day' => max(1, min(31, (int) ($row['salary_day'] ?? 5))),
        'salary_divisor' => (int) ($row['salary_divisor'] ?? 0),
    ];
}

/**
 * The days a staff member was employed in a month, or null if not at all.
 * ['from' => DateTimeImmutable, 'to' => DateTimeImmutable, 'days' => int, 'month_days' => int]
 */
function hef_staff_month_window(array $staff, string $monthStart): ?array
{
    $first = new DateTimeImmutable($monthStart);
    $last = $first->modify('last day of this month');
    $from = $first;
    $to = $last;

    if (! empty($staff['joined_on'])) {
        $joined = new DateTimeImmutable($staff['joined_on']);
        if ($joined > $from) {
            $from = $joined;
        }
    }
    if (! empty($staff['left_on'])) {
        $left = new DateTimeImmutable($staff['left_on']);
        if ($left < $to) {
            $to = $left;
        }
    }
    if ($from > $to) {
        return null;
    }

    return [
        'from' => $from,
        'to' => $to,
        'days' => (int) $from->diff($to)->days + 1,
        'month_days' => (int) $first->format('t'),
    ];
}

/** Advances with something still to recover, oldest first (given on or before $upTo). */
function hef_open_advances(PDO $pdo, int $staffId, string $upTo): array
{
    $stmt = $pdo->prepare(
        'SELECT id, given_on, amount, monthly_recovery, recovered
         FROM staff_advances
         WHERE staff_id = ? AND given_on <= ? AND amount > recovered
         ORDER BY given_on, id'
    );
    $stmt->execute([$staffId, $upTo]);

    return $stmt->fetchAll();
}

/** Total salary advances still to recover from a staff member. */
function hef_advance_balance(PDO $pdo, int $staffId): float
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount - recovered), 0) FROM staff_advances WHERE staff_id = ?');
    $stmt->execute([$staffId]);

    return (float) $stmt->fetchColumn();
}

/**
 * Works out one staff member's pay for a month, or null if they weren't
 * employed in it. $manualRecovery, when given, replaces the suggested advance
 * recovery (it is still capped so net pay can't go below zero).
 */
function hef_payroll_compute(PDO $pdo, array $settings, array $staff, string $monthStart, ?float $manualRecovery = null): ?array
{
    $window = hef_staff_month_window($staff, $monthStart);
    if ($window === null) {
        return null;
    }

    $salary = (float) $staff['monthly_salary'];
    $divisor = $settings['salary_divisor'] > 0 ? $settings['salary_divisor'] : $window['month_days'];
    $perDay = $divisor > 0 ? $salary / $divisor : 0.0;

    $partial = $window['days'] < $window['month_days'];
    $base = $partial ? min($salary, round($perDay * $window['days'], 2)) : $salary;

    // Attendance inside the employed window.
    $stmt = $pdo->prepare(
        'SELECT status, COUNT(*) AS c FROM staff_attendance
         WHERE staff_id = ? AND date BETWEEN ? AND ? GROUP BY status'
    );
    $stmt->execute([(int) $staff['id'], $window['from']->format('Y-m-d'), $window['to']->format('Y-m-d')]);
    $deductionDays = 0.0;
    foreach ($stmt->fetchAll() as $r) {
        $deductionDays += (HEF_ATTENDANCE_STATUSES[$r['status']]['deduct'] ?? 0.0) * (int) $r['c'];
    }
    $attendanceDeduction = min($base, round($perDay * $deductionDays, 2));

    // One-off additions / deductions entered for this month.
    $stmt = $pdo->prepare(
        'SELECT kind, COALESCE(SUM(amount), 0) AS total FROM staff_adjustments
         WHERE staff_id = ? AND month = ? GROUP BY kind'
    );
    $stmt->execute([(int) $staff['id'], $monthStart]);
    $additions = 0.0;
    $otherDeductions = 0.0;
    foreach ($stmt->fetchAll() as $r) {
        if ($r['kind'] === 'addition') {
            $additions = (float) $r['total'];
        } else {
            $otherDeductions = (float) $r['total'];
        }
    }

    // Advance recovery: this month's instalment of each open advance.
    $monthEnd = (new DateTimeImmutable($monthStart))->modify('last day of this month')->format('Y-m-d');
    $suggested = 0.0;
    foreach (hef_open_advances($pdo, (int) $staff['id'], $monthEnd) as $adv) {
        $outstanding = (float) $adv['amount'] - (float) $adv['recovered'];
        $instalment = (float) $adv['monthly_recovery'] > 0 ? min($outstanding, (float) $adv['monthly_recovery']) : $outstanding;
        $suggested += $instalment;
    }

    $available = max(0.0, $base - $attendanceDeduction + $additions - $otherDeductions);
    $recovery = min($manualRecovery !== null ? max(0.0, $manualRecovery) : $suggested, $available);

    $note = $partial ? 'Pro-rated: ' . $window['days'] . ' of ' . $window['month_days'] . ' days' : null;

    return [
        'base_salary' => round($base, 2),
        'deduction_days' => round($deductionDays, 1),
        'attendance_deduction' => round($attendanceDeduction, 2),
        'additions' => round($additions, 2),
        'other_deductions' => round($otherDeductions, 2),
        'advance_recovery' => round($recovery, 2),
        'net_pay' => round($available - $recovery, 2),
        'note' => $note,
    ];
}

/** Staff who may need pay for a month: active ones, plus anyone who left during or after it. */
function hef_staff_for_month(PDO $pdo, int $companyId, string $monthStart): array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM staff
         WHERE company_id = ? AND (status = 'active' OR left_on >= ?)
         ORDER BY name"
    );
    $stmt->execute([$companyId, $monthStart]);

    return $stmt->fetchAll();
}

/**
 * Creates or refreshes the draft payroll rows for a month. Paid rows are left
 * alone. A row whose advance recovery was edited by hand keeps that figure.
 *
 * @return array{created: int, updated: int, skipped_paid: int}
 */
function hef_payroll_generate(PDO $pdo, int $companyId, string $monthStart, ?int $onlyStaffId = null): array
{
    $settings = hef_payroll_settings($pdo, $companyId);
    $result = ['created' => 0, 'updated' => 0, 'skipped_paid' => 0];

    foreach (hef_staff_for_month($pdo, $companyId, $monthStart) as $staff) {
        if ($onlyStaffId !== null && (int) $staff['id'] !== $onlyStaffId) {
            continue;
        }

        $stmt = $pdo->prepare('SELECT * FROM staff_payroll WHERE staff_id = ? AND month = ?');
        $stmt->execute([(int) $staff['id'], $monthStart]);
        $existing = $stmt->fetch();

        if ($existing && $existing['status'] === 'paid') {
            $result['skipped_paid']++;
            continue;
        }

        $manual = $existing && (int) $existing['recovery_manual'] === 1 ? (float) $existing['advance_recovery'] : null;
        $calc = hef_payroll_compute($pdo, $settings, $staff, $monthStart, $manual);
        if ($calc === null) {
            continue;
        }

        if ($existing) {
            $pdo->prepare(
                'UPDATE staff_payroll SET base_salary = ?, deduction_days = ?, attendance_deduction = ?, additions = ?,
                        other_deductions = ?, advance_recovery = ?, net_pay = ?, updated_at = NOW()
                 WHERE id = ?'
            )->execute([
                $calc['base_salary'], $calc['deduction_days'], $calc['attendance_deduction'], $calc['additions'],
                $calc['other_deductions'], $calc['advance_recovery'], $calc['net_pay'], $existing['id'],
            ]);
            $result['updated']++;
        } else {
            $pdo->prepare(
                "INSERT INTO staff_payroll (company_id, staff_id, month, base_salary, deduction_days, attendance_deduction,
                        additions, other_deductions, advance_recovery, net_pay, status, notes, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, NOW(), NOW())"
            )->execute([
                $companyId, $staff['id'], $monthStart, $calc['base_salary'], $calc['deduction_days'],
                $calc['attendance_deduction'], $calc['additions'], $calc['other_deductions'],
                $calc['advance_recovery'], $calc['net_pay'], $calc['note'],
            ]);
            $result['created']++;
        }
    }

    return $result;
}

/**
 * Marks a draft payroll row paid: books the net pay as a "labor" expense and
 * applies the advance recovery to the oldest open advances. Returns an error
 * message, or null on success.
 */
function hef_payroll_mark_paid(PDO $pdo, int $companyId, int $payrollId, string $paidOn, string $mode): ?string
{
    $stmt = $pdo->prepare(
        'SELECT p.*, s.name AS staff_name FROM staff_payroll p JOIN staff s ON s.id = p.staff_id
         WHERE p.id = ? AND p.company_id = ?'
    );
    $stmt->execute([$payrollId, $companyId]);
    $p = $stmt->fetch();

    if (! $p) {
        return 'Payroll entry not found.';
    }
    if ($p['status'] === 'paid') {
        return null; // already paid
    }
    if (! in_array($mode, ['cash', 'bank', 'upi', 'other'], true)) {
        return 'Choose how it was paid.';
    }
    $paid = DateTime::createFromFormat('Y-m-d', $paidOn);
    if (! $paid || $paid->format('Y-m-d') !== $paidOn) {
        return 'Enter a valid payment date.';
    }

    $settings = hef_payroll_settings($pdo, $companyId);
    $monthLabel = date('F Y', strtotime($p['month']));

    $pdo->beginTransaction();
    try {
        $expenseId = null;
        if ((float) $p['net_pay'] > 0) {
            $pdo->prepare(
                "INSERT INTO expenses (company_id, category, amount, currency_code, date, notes, created_at, updated_at)
                 VALUES (?, 'labor', ?, ?, ?, ?, NOW(), NOW())"
            )->execute([$companyId, $p['net_pay'], $settings['currency'], $paidOn, 'Salary ' . $monthLabel . ': ' . $p['staff_name']]);
            $expenseId = (int) $pdo->lastInsertId();
        }

        // Spread the recovery over the oldest open advances.
        $left = (float) $p['advance_recovery'];
        if ($left > 0) {
            $monthEnd = (new DateTimeImmutable($p['month']))->modify('last day of this month')->format('Y-m-d');
            foreach (hef_open_advances($pdo, (int) $p['staff_id'], $monthEnd) as $adv) {
                if ($left <= 0) {
                    break;
                }
                $take = min($left, (float) $adv['amount'] - (float) $adv['recovered']);
                if ($take <= 0) {
                    continue;
                }
                $pdo->prepare('UPDATE staff_advances SET recovered = recovered + ?, updated_at = NOW() WHERE id = ?')->execute([$take, $adv['id']]);
                $pdo->prepare('INSERT INTO staff_advance_recoveries (payroll_id, advance_id, amount) VALUES (?, ?, ?)')->execute([$payrollId, $adv['id'], $take]);
                $left -= $take;
            }
        }

        $pdo->prepare(
            "UPDATE staff_payroll SET status = 'paid', paid_on = ?, payment_mode = ?, expense_id = ?, updated_at = NOW() WHERE id = ?"
        )->execute([$paidOn, $mode, $expenseId, $payrollId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('payroll pay failed: ' . $e->getMessage());

        return 'Could not record the payment. Nothing was changed.';
    }

    return null;
}

/** Undoes a payment: removes its expense and gives the recovered advance amounts back. Error message or null. */
function hef_payroll_reopen(PDO $pdo, int $companyId, int $payrollId): ?string
{
    $stmt = $pdo->prepare('SELECT * FROM staff_payroll WHERE id = ? AND company_id = ?');
    $stmt->execute([$payrollId, $companyId]);
    $p = $stmt->fetch();

    if (! $p) {
        return 'Payroll entry not found.';
    }
    if ($p['status'] !== 'paid') {
        return null;
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT advance_id, amount FROM staff_advance_recoveries WHERE payroll_id = ?');
        $stmt->execute([$payrollId]);
        foreach ($stmt->fetchAll() as $r) {
            $pdo->prepare('UPDATE staff_advances SET recovered = GREATEST(0, recovered - ?), updated_at = NOW() WHERE id = ?')
                ->execute([$r['amount'], $r['advance_id']]);
        }
        $pdo->prepare('DELETE FROM staff_advance_recoveries WHERE payroll_id = ?')->execute([$payrollId]);

        if ($p['expense_id']) {
            $pdo->prepare('DELETE FROM expenses WHERE id = ? AND company_id = ?')->execute([$p['expense_id'], $companyId]);
        }
        $pdo->prepare(
            "UPDATE staff_payroll SET status = 'draft', paid_on = NULL, payment_mode = NULL, expense_id = NULL, updated_at = NOW() WHERE id = ?"
        )->execute([$payrollId]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('payroll reopen failed: ' . $e->getMessage());

        return 'Could not reopen this payroll. Nothing was changed.';
    }

    return null;
}

/**
 * The salary that should be paid soon or is overdue, for the dashboard and
 * the daily summary email. Looks at last month's work, due on the salary day
 * of this month, and returns info from 3 days before the due date until the
 * last unpaid staff member is paid (or the month rolls over).
 *
 * @return array{month: string, due: string, days: int, unpaid: int}|null
 */
function hef_payroll_due_info(PDO $pdo, int $companyId, ?string $timezone): ?array
{
    if (! hef_company_has_pro($pdo, $companyId)) {
        return null; // Staff & Payroll is a Pro feature
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM staff WHERE company_id = ?');
    $stmt->execute([$companyId]);
    if ((int) $stmt->fetchColumn() === 0) {
        return null;
    }

    $settings = hef_payroll_settings($pdo, $companyId);
    $today = hef_company_today($timezone);
    $prevMonth = (new DateTimeImmutable($today))->modify('first day of last month')->format('Y-m-d');
    $due = hef_salary_due_date($prevMonth, $settings['salary_day']);
    $days = (int) (new DateTimeImmutable($today))->diff(new DateTimeImmutable($due))->format('%r%a');

    if ($days > 3) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT staff_id FROM staff_payroll WHERE company_id = ? AND month = ? AND status = 'paid'");
    $stmt->execute([$companyId, $prevMonth]);
    $paid = array_flip(array_column($stmt->fetchAll(), 'staff_id'));

    $unpaid = 0;
    foreach (hef_staff_for_month($pdo, $companyId, $prevMonth) as $staff) {
        if ((float) $staff['monthly_salary'] > 0 && hef_staff_month_window($staff, $prevMonth) !== null && ! isset($paid[$staff['id']])) {
            $unpaid++;
        }
    }

    return $unpaid > 0 ? ['month' => $prevMonth, 'due' => $due, 'days' => $days, 'unpaid' => $unpaid] : null;
}

function hef_money($amount): string
{
    return '₹' . number_format((float) $amount, 2);
}
