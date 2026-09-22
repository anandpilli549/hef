<?php
require_once __DIR__ . '/bootstrap.php';
hef_require_role(['owner', 'worker'], $currentUser['role']);

// Staff is a Pro feature. Owners without Pro are sent to Billing; Workers back to the dashboard.
if (! hef_company_has_pro($pdo, (int) $currentUser['company_id'])) {
    header('Location: ' . ($currentUser['role'] === 'owner' ? '/hef/admin/billing.php?upgrade=pro' : '/hef/admin/dashboard.php?error=access_denied'));
    exit;
}

// Daily attendance. Owner and Worker can mark it; salary figures are never
// shown here. Everyone is PRESENT by default: only the exceptions (absent,
// half day, leave, week off) are recorded, and choosing Present clears one.

$companyId = (int) $currentUser['company_id'];
hef_reconcile_staff_periods($pdo, $companyId);
$userId = (int) $currentUser['user_id'];
$settings = hef_payroll_settings($pdo, $companyId);
$today = hef_company_today($settings['timezone']);
$error = '';
$status = '';

$validDate = function ($v) {
    $d = DateTime::createFromFormat('Y-m-d', (string) $v);

    return $d && $d->format('Y-m-d') === $v;
};

$date = (string) ($_POST['date'] ?? $_GET['date'] ?? $today);
if (! $validDate($date) || $date > $today) {
    $date = $today; // can't mark days that haven't happened yet
}
$monthStart = substr($date, 0, 8) . '01';
$monthEnd = (new DateTimeImmutable($monthStart))->modify('last day of this month')->format('Y-m-d');

/** Staff who were working on a given day. */
function hef_staff_on_day(PDO $pdo, int $companyId, string $day): array
{
    // A period with no end (still working) or one that covers this day.
    $stmt = $pdo->prepare(
        "SELECT DISTINCT s.id, s.name, s.designation FROM staff s
         JOIN staff_employment_periods p ON p.staff_id = s.id
         WHERE s.company_id = ? AND p.started_on <= ? AND (p.ended_on IS NULL OR p.ended_on >= ?)
         ORDER BY s.name"
    );
    $stmt->execute([$companyId, $day, $day]);

    return $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_attendance'])) {
    $marks = is_array($_POST['status'] ?? null) ? $_POST['status'] : [];
    $saved = 0;
    foreach (hef_staff_on_day($pdo, $companyId, $date) as $s) {
        $mark = (string) ($marks[$s['id']] ?? '');
        if (! isset(HEF_ATTENDANCE_STATUSES[$mark])) {
            continue;
        }
        if ($mark === 'present') {
            // Present is the default, so there is nothing to keep.
            $pdo->prepare('DELETE FROM staff_attendance WHERE staff_id = ? AND date = ? AND company_id = ?')->execute([$s['id'], $date, $companyId]);
            $saved++;
            continue;
        }
        $pdo->prepare(
            'INSERT INTO staff_attendance (company_id, staff_id, date, status, marked_by_user_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE status = ?, marked_by_user_id = ?, updated_at = NOW()'
        )->execute([$companyId, $s['id'], $date, $mark, $userId, $mark, $userId]);
        $saved++;
    }
    $status = 'Saved for ' . date('D, d M Y', strtotime($date)) . '. Everyone not marked otherwise is present.';
}

$staffToday = hef_staff_on_day($pdo, $companyId, $date);

// Marks already saved for the chosen day.
$stmt = $pdo->prepare('SELECT staff_id, status FROM staff_attendance WHERE company_id = ? AND date = ?');
$stmt->execute([$companyId, $date]);
$dayMarks = [];
foreach ($stmt->fetchAll() as $r) {
    $dayMarks[(int) $r['staff_id']] = $r['status'];
}

// Which days of the month have any marks (for the day strip).
$stmt = $pdo->prepare("SELECT DISTINCT date FROM staff_attendance WHERE company_id = ? AND status != 'present' AND date BETWEEN ? AND ?");
$stmt->execute([$companyId, $monthStart, $monthEnd]);
$markedDays = array_flip(array_column($stmt->fetchAll(), 'date'));

// Month summary per staff member.
$stmt = $pdo->prepare(
    'SELECT staff_id, status, COUNT(*) AS c FROM staff_attendance
     WHERE company_id = ? AND date BETWEEN ? AND ? GROUP BY staff_id, status'
);
$stmt->execute([$companyId, $monthStart, $monthEnd]);
$summary = [];
foreach ($stmt->fetchAll() as $r) {
    $summary[(int) $r['staff_id']][$r['status']] = (int) $r['c'];
}
$monthStaff = hef_staff_for_month($pdo, $companyId, $monthStart);

$prevMonth = (new DateTimeImmutable($monthStart))->modify('-1 month')->format('Y-m-d');
$nextMonthFirst = (new DateTimeImmutable($monthStart))->modify('+1 month')->format('Y-m-d');
$daysInMonth = (int) (new DateTimeImmutable($monthStart))->format('t');

require_once __DIR__ . '/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-calendar-check me-2"></i>Attendance</h4>
    <form method="GET" class="d-flex gap-2 align-items-center">
        <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($date) ?>" max="<?= htmlspecialchars($today) ?>" onchange="this.form.submit()">
    </form>
</div>

<?php if ($status): ?><div class="alert alert-success py-2"><?= htmlspecialchars($status) ?></div><?php endif; ?>

<!-- Month strip: jump to any day -->
<div class="card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <a href="?date=<?= htmlspecialchars(min($today, $prevMonth)) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i></a>
        <strong><?= date('F Y', strtotime($monthStart)) ?></strong>
        <?php if ($nextMonthFirst <= $today): ?>
            <a href="?date=<?= htmlspecialchars($nextMonthFirst) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-right"></i></a>
        <?php else: ?>
            <span class="btn btn-sm btn-outline-secondary disabled"><i class="bi bi-chevron-right"></i></span>
        <?php endif; ?>
    </div>
    <div class="d-flex flex-wrap gap-1">
        <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
            <?php
                $dayStr = substr($monthStart, 0, 8) . str_pad((string) $d, 2, '0', STR_PAD_LEFT);
                $future = $dayStr > $today;
                $isMarked = isset($markedDays[$dayStr]);
                $isCurrent = $dayStr === $date;
                $cls = $isCurrent ? 'btn-hef text-white' : ($isMarked ? 'btn-warning' : 'btn-outline-secondary');
            ?>
            <?php if ($future): ?>
                <span class="btn btn-sm btn-outline-secondary disabled" style="width:2.4rem;"><?= $d ?></span>
            <?php else: ?>
                <a href="?date=<?= $dayStr ?>" class="btn btn-sm <?= $cls ?>" style="width:2.4rem;" title="<?= $isMarked ? 'Has absences or leave' : 'Everyone present' ?>"><?= $d ?></a>
            <?php endif; ?>
        <?php endfor; ?>
    </div>
    <div class="text-muted small mt-2"><span class="badge bg-warning">&nbsp;</span> day with an absence or leave &nbsp; <span class="badge bg-hef" style="background:var(--hef-green);">&nbsp;</span> selected &nbsp; Everyone is present unless marked otherwise.</div>
</div>

<!-- Mark the chosen day -->
<div class="card mb-4">
    <div class="card-header bg-white"><strong><?= date('l, d F Y', strtotime($date)) ?></strong><?= $date === $today ? ' <span class="badge bg-light text-dark border ms-1">Today</span>' : '' ?></div>
    <?php if (empty($staffToday)): ?>
        <div class="card-body text-muted">No staff were working on this day. <?php if ($currentUser['role'] === 'owner'): ?><a href="/hef/admin/staff.php">Add staff</a><?php endif; ?></div>
    <?php else: ?>
    <form method="POST">
        <input type="hidden" name="save_attendance" value="1">
        <input type="hidden" name="date" value="<?= htmlspecialchars($date) ?>">
        <div class="list-group list-group-flush">
            <?php foreach ($staffToday as $s): ?>
                <?php $current = $dayMarks[(int) $s['id']] ?? 'present'; if ($current === 'present') { unset($dayMarks[(int) $s['id']]); } ?>
                <div class="list-group-item d-flex justify-content-between align-items-center gap-2 flex-wrap">
                    <div>
                        <div class="fw-semibold"><?= htmlspecialchars($s['name']) ?></div>
                        <?php if ($s['designation']): ?><div class="small text-muted"><?= htmlspecialchars($s['designation']) ?></div><?php endif; ?>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <select name="status[<?= (int) $s['id'] ?>]" class="form-select form-select-sm <?= $current !== 'present' ? 'border-warning' : '' ?>" style="width:9.5rem;">
                            <?php foreach (HEF_ATTENDANCE_STATUSES as $key => $def): ?>
                                <option value="<?= $key ?>" <?= $current === $key ? 'selected' : '' ?>><?= $def['label'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="card-body">
            <button type="submit" class="btn btn-hef text-white">Save</button>
            <span class="text-muted small ms-2">Everyone is present unless you change them. Only absences, half days, leave and week offs are recorded.</span>
        </div>
    </form>
    <?php endif; ?>
</div>

<!-- Month summary -->
<h6 class="mb-2">Summary — <?= date('F Y', strtotime($monthStart)) ?></h6>
<div class="card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" style="font-size:0.9rem;">
            <thead class="table-light">
                <tr>
                    <th>Staff</th>
                    <?php foreach (HEF_ATTENDANCE_STATUSES as $def): ?><th class="text-center" title="<?= $def['label'] ?>"><?= $def['code'] ?></th><?php endforeach; ?>
                    <th class="text-center" title="Days that reduce pay (absent + unpaid leave + half days x 0.5)">Pay-cut days</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($monthStaff as $s): ?>
                    <?php
                        $counts = $summary[(int) $s['id']] ?? [];
                        $cut = 0.0;
                        $exceptions = 0;
                        foreach ($counts as $k => $c) {
                            $cut += (HEF_ATTENDANCE_STATUSES[$k]['deduct'] ?? 0) * $c;
                            if ($k !== 'present') {
                                $exceptions += $c;
                            }
                        }
                        // Present = every day employed so far this month, minus the exceptions.
                        $w = hef_staff_month_window($pdo, $s, $monthStart);
                        $todayDt = new DateTimeImmutable($today);
                        $endDay = ($w && $w['to'] < $todayDt) ? $w['to'] : $todayDt;
                        $daysSoFar = ($w && $w['from'] <= $endDay) ? (int) $w['from']->diff($endDay)->days + 1 : 0;
                        $counts['present'] = max(0, $daysSoFar - $exceptions);
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($s['name']) ?></td>
                        <?php foreach (HEF_ATTENDANCE_STATUSES as $key => $def): ?>
                            <td class="text-center"><?= ($counts[$key] ?? 0) > 0 ? '<span class="badge ' . $def['badge'] . '">' . (int) $counts[$key] . '</span>' : '<span class="text-muted">·</span>' ?></td>
                        <?php endforeach; ?>
                        <td class="text-center fw-semibold <?= $cut > 0 ? 'text-danger' : '' ?>"><?= $cut > 0 ? rtrim(rtrim(number_format($cut, 1), '0'), '.') : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($monthStaff)): ?><tr><td colspan="8" class="text-muted small">No staff.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<div class="text-muted small mt-2">P present (every other day) · A absent · H half day · PL paid leave · UL unpaid leave · WO week off. Absent and unpaid leave cut a full day's pay, a half day cuts half.</div>

<?php require_once __DIR__ . '/footer.php'; ?>
