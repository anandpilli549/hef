<?php
require_once __DIR__ . '/bootstrap.php';
hef_require_pro($pdo, $currentUser); // Staff & Payroll is a Pro feature (Owner only)

// One staff member: details, advances, payroll history and this month's attendance.

$companyId = (int) $currentUser['company_id'];
hef_reconcile_staff_periods($pdo, $companyId);
$settings = hef_payroll_settings($pdo, $companyId);
$today = hef_company_today($settings['timezone']);
$error = '';
$status = '';

$stmt = $pdo->prepare('SELECT * FROM staff WHERE id = ? AND company_id = ?');
$stmt->execute([(int) ($_GET['id'] ?? 0), $companyId]);
$staff = $stmt->fetch();
if (! $staff) {
    header('Location: /hef/admin/staff.php');
    exit;
}
$staffId = (int) $staff['id'];

// Delete an advance that hasn't been recovered from anything yet (and its expense).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_advance_id'])) {
    $stmt = $pdo->prepare('SELECT id, recovered, expense_id FROM staff_advances WHERE id = ? AND staff_id = ? AND company_id = ?');
    $stmt->execute([(int) $_POST['delete_advance_id'], $staffId, $companyId]);
    $adv = $stmt->fetch();
    if (! $adv) {
        $error = 'Advance not found.';
    } elseif ((float) $adv['recovered'] > 0) {
        $error = 'Part of this advance has already been recovered from salary, so it can\'t be deleted.';
    } else {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM staff_advances WHERE id = ?')->execute([$adv['id']]);
            if ($adv['expense_id']) {
                $pdo->prepare('DELETE FROM expenses WHERE id = ? AND company_id = ?')->execute([$adv['expense_id'], $companyId]);
            }
            $pdo->commit();
            $status = 'Advance deleted, and its expense removed from Finances.';
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('advance delete failed: ' . $e->getMessage());
            $error = 'Could not delete the advance.';
        }
    }
}

/** Rebuilds staff.joined_on / left_on / status from the earliest and latest period, after a period is edited. */
function hef_sync_staff_from_periods(PDO $pdo, int $staffId): void
{
    $stmt = $pdo->prepare('SELECT MIN(started_on) AS first_start, MAX(ended_on) AS last_end,
                                   SUM(ended_on IS NULL) AS open_count
                            FROM staff_employment_periods WHERE staff_id = ?');
    $stmt->execute([$staffId]);
    $row = $stmt->fetch();
    $isActive = (int) $row['open_count'] > 0;
    $pdo->prepare('UPDATE staff SET joined_on = ?, left_on = ?, status = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$row['first_start'], $isActive ? null : $row['last_end'], $isActive ? 'active' : 'inactive', $staffId]);
}

// Add / edit / delete an employment period (for correcting history).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_period'])) {
    $start = trim((string) ($_POST['started_on'] ?? ''));
    $end = trim((string) ($_POST['ended_on'] ?? ''));
    $d1 = DateTime::createFromFormat('Y-m-d', $start);
    $d2 = $end === '' ? null : DateTime::createFromFormat('Y-m-d', $end);
    if (! $d1 || $d1->format('Y-m-d') !== $start) {
        $error = 'Enter a valid start date.';
    } elseif ($end !== '' && (! $d2 || $d2->format('Y-m-d') !== $end || $end < $start)) {
        $error = 'The end date must be a valid date on or after the start date.';
    } else {
        $pdo->prepare('INSERT INTO staff_employment_periods (company_id, staff_id, started_on, ended_on, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())')
            ->execute([$companyId, $staffId, $start, $end ?: null, trim((string) ($_POST['notes'] ?? '')) ?: null]);
        hef_sync_staff_from_periods($pdo, $staffId);
        $status = 'Period added.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_period_id'])) {
    $pid = (int) $_POST['edit_period_id'];
    $start = trim((string) ($_POST['started_on'] ?? ''));
    $end = trim((string) ($_POST['ended_on'] ?? ''));
    $d1 = DateTime::createFromFormat('Y-m-d', $start);
    $d2 = $end === '' ? null : DateTime::createFromFormat('Y-m-d', $end);
    $stmt = $pdo->prepare('SELECT id FROM staff_employment_periods WHERE id = ? AND staff_id = ?');
    $stmt->execute([$pid, $staffId]);
    if (! $stmt->fetchColumn()) {
        $error = 'Period not found.';
    } elseif (! $d1 || $d1->format('Y-m-d') !== $start) {
        $error = 'Enter a valid start date.';
    } elseif ($end !== '' && (! $d2 || $d2->format('Y-m-d') !== $end || $end < $start)) {
        $error = 'The end date must be a valid date on or after the start date.';
    } else {
        $pdo->prepare('UPDATE staff_employment_periods SET started_on = ?, ended_on = ?, notes = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$start, $end ?: null, trim((string) ($_POST['notes'] ?? '')) ?: null, $pid]);
        hef_sync_staff_from_periods($pdo, $staffId);
        $status = 'Period updated.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_period_id'])) {
    $pid = (int) $_POST['delete_period_id'];
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM staff_employment_periods WHERE staff_id = ?');
    $stmt->execute([$staffId]);
    if ((int) $stmt->fetchColumn() <= 1) {
        $error = 'A staff member needs at least one period of employment, so the last one can\'t be deleted.';
    } else {
        $pdo->prepare('DELETE FROM staff_employment_periods WHERE id = ? AND staff_id = ?')->execute([$pid, $staffId]);
        hef_sync_staff_from_periods($pdo, $staffId);
        $status = 'Period deleted.';
    }
}

$stmt = $pdo->prepare('SELECT * FROM staff_advances WHERE staff_id = ? ORDER BY given_on DESC, id DESC');
$stmt->execute([$staffId]);
$advances = $stmt->fetchAll();
$advanceBalance = 0.0;
foreach ($advances as $a) {
    $advanceBalance += (float) $a['amount'] - (float) $a['recovered'];
}

$stmt = $pdo->prepare('SELECT * FROM staff_payroll WHERE staff_id = ? ORDER BY month DESC');
$stmt->execute([$staffId]);
$payrolls = $stmt->fetchAll();

$monthStart = substr($today, 0, 8) . '01';
$stmt = $pdo->prepare('SELECT status, COUNT(*) AS c FROM staff_attendance WHERE staff_id = ? AND date BETWEEN ? AND ? GROUP BY status');
$stmt->execute([$staffId, $monthStart, $today]);
$attendance = [];
$exceptions = 0;
foreach ($stmt->fetchAll() as $r) {
    $attendance[$r['status']] = (int) $r['c'];
    if ($r['status'] !== 'present') {
        $exceptions += (int) $r['c'];
    }
}
// Present = every day they worked so far this month, minus the exceptions.
$w = hef_staff_month_window($pdo, $staff, $monthStart);
$todayDt = new DateTimeImmutable($today);
$endDay = ($w && $w['to'] < $todayDt) ? $w['to'] : $todayDt;
$daysSoFar = ($w && $w['from'] <= $endDay) ? (int) $w['from']->diff($endDay)->days + 1 : 0;
$attendance['present'] = max(0, $daysSoFar - $exceptions);

$stmt = $pdo->prepare('SELECT name, role FROM users WHERE id = ?');
$stmt->execute([(int) $staff['user_id']]);
$linkedUser = $staff['user_id'] ? $stmt->fetch() : null;

$stmt = $pdo->prepare('SELECT * FROM staff_employment_periods WHERE staff_id = ? ORDER BY started_on DESC');
$stmt->execute([$staffId]);
$periods = $stmt->fetchAll();
// Re-fetch the staff row in case a period edit just changed joined_on / left_on / status.
$stmt = $pdo->prepare('SELECT * FROM staff WHERE id = ?');
$stmt->execute([$staffId]);
$staff = $stmt->fetch();

require_once __DIR__ . '/header.php';
?>
<a href="/hef/admin/staff.php" class="text-muted small"><i class="bi bi-arrow-left"></i> Back to staff</a>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mt-2 mb-3">
    <div class="d-flex align-items-start gap-3">
        <?php if ($staff['photo_path']): ?><img src="/hef/<?= htmlspecialchars($staff['photo_path']) ?>" style="width:64px; height:64px; object-fit:cover; border-radius:50%;"><?php endif; ?>
        <div>
        <h4 class="mb-1"><?= htmlspecialchars($staff['name']) ?>
            <?= $staff['status'] === 'active' ? '<span class="badge bg-success-subtle text-success fs-6 align-middle">Working</span>' : '<span class="badge bg-secondary-subtle text-secondary fs-6 align-middle">Left</span>' ?></h4>
        <div class="text-muted small">
            <?= htmlspecialchars((string) $staff['designation']) ?: 'No designation' ?>
            · <?= hef_money($staff['monthly_salary']) ?>/month
            · Joined <?= $staff['joined_on'] ? date('d M Y', strtotime($staff['joined_on'])) : '—' ?>
            <?php if ($staff['left_on']): ?>· Left <?= date('d M Y', strtotime($staff['left_on'])) ?><?php endif; ?>
            <?php if ($linkedUser): ?>· App login: <?= htmlspecialchars($linkedUser['name']) ?> (<?= htmlspecialchars($linkedUser['role']) ?>)<?php endif; ?>
            <?php if ($staff['date_of_birth']): ?>· DOB <?= date('d M Y', strtotime($staff['date_of_birth'])) ?><?php endif; ?>
        </div>
        <?php if ($staff['phone']): ?><div class="mt-1"><?= htmlspecialchars($staff['phone']) ?> <?= hef_contact_buttons($staff['phone']) ?></div><?php endif; ?>
        <?php if ($staff['id1_path'] || $staff['id2_path']): ?>
            <div class="mt-1 small">
                <?php if ($staff['id1_path']): ?><a href="/hef/<?= htmlspecialchars($staff['id1_path']) ?>" target="_blank" class="me-2"><i class="bi bi-file-earmark-image me-1"></i><?= htmlspecialchars($staff['id1_label'] ?: 'ID proof 1') ?></a><?php endif; ?>
                <?php if ($staff['id2_path']): ?><a href="/hef/<?= htmlspecialchars($staff['id2_path']) ?>" target="_blank"><i class="bi bi-file-earmark-image me-1"></i><?= htmlspecialchars($staff['id2_label'] ?: 'ID proof 2') ?></a><?php endif; ?>
            </div>
        <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($status): ?><div class="alert alert-success py-2"><?= htmlspecialchars($status) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-xl-6">
        <h6 class="mb-2">Attendance — <?= date('F Y', strtotime($monthStart)) ?> (so far)</h6>
        <div class="card p-3 mb-4">
            <div class="d-flex flex-wrap gap-2">
                <?php foreach (HEF_ATTENDANCE_STATUSES as $key => $def): ?>
                    <span class="badge <?= $def['badge'] ?> fs-6 fw-normal"><?= $def['label'] ?>: <?= (int) ($attendance[$key] ?? 0) ?></span>
                <?php endforeach; ?>
            </div>
            <div class="text-muted small mt-2">Everyone is present unless marked otherwise. <a href="/hef/admin/staff-attendance.php">Open attendance</a></div>
        </div>

        <h6 class="mb-2">Advances <span class="text-muted fw-normal">— owes <?= hef_money($advanceBalance) ?></span></h6>
        <div class="card mb-4">
            <div class="list-group list-group-flush">
                <?php if (empty($advances)): ?><div class="list-group-item text-muted small">No advances.</div><?php endif; ?>
                <?php foreach ($advances as $a): ?>
                    <?php $left = (float) $a['amount'] - (float) $a['recovered']; ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center gap-2">
                        <div>
                            <div><?= hef_money($a['amount']) ?> <span class="text-muted small">on <?= date('d M Y', strtotime($a['given_on'])) ?></span></div>
                            <div class="small text-muted">
                                Recovered <?= hef_money($a['recovered']) ?> ·
                                <?= $left > 0 ? '<span class="text-danger">' . hef_money($left) . ' left</span>' : '<span class="text-success">cleared</span>' ?>
                                <?= (float) $a['monthly_recovery'] > 0 ? ' · ' . hef_money($a['monthly_recovery']) . '/month' : ' · next salary' ?>
                                <?= $a['notes'] ? ' · ' . htmlspecialchars($a['notes']) : '' ?>
                            </div>
                        </div>
                        <?php if ((float) $a['recovered'] == 0): ?>
                            <form method="POST" onsubmit="return confirm('Delete this advance? Its expense is removed from Finances too.');">
                                <input type="hidden" name="delete_advance_id" value="<?= (int) $a['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <h6 class="mb-2">Salary history</h6>
        <div class="card">
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" style="font-size:0.9rem;">
                    <thead class="table-light"><tr><th>Month</th><th class="text-end">Net pay</th><th>Status</th><th style="width:1%;"></th></tr></thead>
                    <tbody>
                        <?php foreach ($payrolls as $p): ?>
                            <tr>
                                <td><a href="/hef/admin/staff-payroll.php?month=<?= substr($p['month'], 0, 7) ?>" class="text-decoration-none"><?= date('F Y', strtotime($p['month'])) ?></a></td>
                                <td class="text-end"><?= hef_money($p['net_pay']) ?></td>
                                <td><?= $p['status'] === 'paid' ? '<span class="badge bg-success">Paid ' . date('d M', strtotime($p['paid_on'])) . '</span>' : '<span class="badge bg-warning text-dark">Pending</span>' ?></td>
                                <td><a href="/hef/admin/staff-payslip.php?id=<?= (int) $p['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Payslip"><i class="bi bi-receipt"></i></a></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($payrolls)): ?><tr><td colspan="4" class="text-muted small">No payroll yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if ($staff['notes']): ?><div class="text-muted small mt-2"><i class="bi bi-sticky me-1"></i><?= htmlspecialchars($staff['notes']) ?></div><?php endif; ?>

        <h6 class="mb-2 mt-4">Employment periods <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" data-bs-toggle="modal" data-bs-target="#addPeriod"><i class="bi bi-plus-lg"></i></button></h6>
        <div class="card">
            <ul class="list-group list-group-flush small">
                <?php foreach ($periods as $p): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                        <span>
                            <?= date('d M Y', strtotime($p['started_on'])) ?> – <?= $p['ended_on'] ? date('d M Y', strtotime($p['ended_on'])) : '<span class="text-success">now</span>' ?>
                            <?php if ($p['notes']): ?><span class="text-muted"> · <?= htmlspecialchars($p['notes']) ?></span><?php endif; ?>
                        </span>
                        <span class="d-flex gap-1">
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" data-bs-toggle="modal" data-bs-target="#editPeriod<?= (int) $p['id'] ?>"><i class="bi bi-pencil"></i></button>
                            <?php if (count($periods) > 1): ?>
                                <form method="POST" onsubmit="return confirm('Delete this period? Any payroll already calculated for months in it is not removed automatically.');">
                                    <input type="hidden" name="delete_period_id" value="<?= (int) $p['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-1"><i class="bi bi-trash"></i></button>
                                </form>
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="text-muted small mt-1">Each time someone leaves and comes back, a new period is kept here — this is what payroll and attendance use to work out who was actually employed, month by month.</div>
    </div>
</div>

<!-- Add period -->
<div class="modal fade" id="addPeriod" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="add_period" value="1">
                <div class="modal-header"><h5 class="modal-title">Add a period of employment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row g-2 mb-2">
                        <div class="col-6"><label class="form-label small">Started</label><input type="date" name="started_on" class="form-control" required></div>
                        <div class="col-6"><label class="form-label small">Ended <span class="text-muted">(blank = still working)</span></label><input type="date" name="ended_on" class="form-control"></div>
                    </div>
                    <div class="mb-2"><label class="form-label small">Note</label><input type="text" name="notes" class="form-control" maxlength="255"></div>
                </div>
                <div class="modal-footer"><button type="submit" class="btn btn-hef text-white w-100">Add</button></div>
            </form>
        </div>
    </div>
</div>
<?php foreach ($periods as $p): ?>
<div class="modal fade" id="editPeriod<?= (int) $p['id'] ?>" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="edit_period_id" value="<?= (int) $p['id'] ?>">
                <div class="modal-header"><h5 class="modal-title">Edit period</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row g-2 mb-2">
                        <div class="col-6"><label class="form-label small">Started</label><input type="date" name="started_on" class="form-control" required value="<?= htmlspecialchars($p['started_on']) ?>"></div>
                        <div class="col-6"><label class="form-label small">Ended <span class="text-muted">(blank = still working)</span></label><input type="date" name="ended_on" class="form-control" value="<?= htmlspecialchars((string) $p['ended_on']) ?>"></div>
                    </div>
                    <div class="mb-2"><label class="form-label small">Note</label><input type="text" name="notes" class="form-control" maxlength="255" value="<?= htmlspecialchars((string) $p['notes']) ?>"></div>
                </div>
                <div class="modal-footer"><button type="submit" class="btn btn-hef text-white w-100">Save</button></div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
