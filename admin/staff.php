<?php
require_once __DIR__ . '/bootstrap.php';
hef_require_pro($pdo, $currentUser); // Staff & Payroll is a Pro feature (Owner only)

// Staff master list. Staff don't need an app login: a person can be added
// here without one, and app users can be linked to their staff record.
// Salary details are for the Owner only.

$companyId = (int) $currentUser['company_id'];
$error = '';
$status = '';
$openAdd = false;
$addValues = ['name' => '', 'phone' => '', 'designation' => '', 'monthly_salary' => '', 'joined_on' => '', 'left_on' => '', 'user_id' => '', 'status' => 'active', 'notes' => ''];
$settings = hef_payroll_settings($pdo, $companyId);
$today = hef_company_today($settings['timezone']);

/** Reads and checks the add / edit form. Returns the cleaned values plus an 'error' key. */
function hef_read_staff_form(array $post, PDO $pdo, int $companyId, int $staffId): array
{
    $isDate = function ($v) {
        $d = DateTime::createFromFormat('Y-m-d', (string) $v);

        return $d && $d->format('Y-m-d') === $v;
    };
    $name = trim((string) ($post['name'] ?? ''));
    $phone = preg_replace('/[^0-9+\-\s()]/', '', trim((string) ($post['phone'] ?? '')));
    $designation = trim((string) ($post['designation'] ?? ''));
    $salaryRaw = trim((string) ($post['monthly_salary'] ?? ''));
    $joined = trim((string) ($post['joined_on'] ?? ''));
    $left = trim((string) ($post['left_on'] ?? ''));
    $userId = (int) ($post['user_id'] ?? 0);
    $active = ($post['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    $notes = trim((string) ($post['notes'] ?? ''));
    $error = '';

    if ($name === '' || strlen($name) > 255) {
        $error = 'Enter the staff member\'s name.';
    } elseif (strlen($phone) > 20 || strlen($designation) > 100) {
        $error = 'Phone is limited to 20 characters and designation to 100.';
    } elseif ($salaryRaw === '' || ! is_numeric($salaryRaw) || (float) $salaryRaw < 0 || (float) $salaryRaw > 9999999.99) {
        $error = 'Enter the monthly salary (0 or more).';
    } elseif ($joined !== '' && ! $isDate($joined)) {
        $error = 'Enter a valid joining date.';
    } elseif ($left !== '' && ! $isDate($left)) {
        $error = 'Enter a valid last working date.';
    } elseif ($joined !== '' && $left !== '' && $left < $joined) {
        $error = 'The last working date can\'t be before the joining date.';
    }

    // Optional link to an app user of this company that isn't already another staff record.
    $linkedUser = null;
    if ($error === '' && $userId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? AND company_id = ? AND is_super_admin = 0');
        $stmt->execute([$userId, $companyId]);
        if (! $stmt->fetchColumn()) {
            $error = 'That app user was not found.';
        } else {
            $stmt = $pdo->prepare('SELECT id FROM staff WHERE user_id = ? AND id != ?');
            $stmt->execute([$userId, $staffId]);
            if ($stmt->fetchColumn()) {
                $error = 'That app user is already linked to another staff member.';
            } else {
                $linkedUser = $userId;
            }
        }
    }

    return [
        'name' => $name, 'phone' => $phone !== '' ? $phone : null, 'designation' => $designation !== '' ? $designation : null,
        'monthly_salary' => round((float) $salaryRaw, 2), 'joined_on' => $joined !== '' ? $joined : null,
        'left_on' => $left !== '' ? $left : null, 'user_id' => $linkedUser, 'status' => $active,
        'notes' => $notes !== '' ? $notes : null, 'error' => $error,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_staff'])) {
        $staffId = (int) $_POST['save_staff']; // 0 = new
        $f = hef_read_staff_form($_POST, $pdo, $companyId, $staffId);
        if ($f['error']) {
            $error = $f['error'];
            if ($staffId === 0) {
                $openAdd = true;
                $addValues = array_merge($addValues, array_map(function ($v) { return (string) $v; }, array_diff_key($f, ['error' => 1])));
                $addValues['user_id'] = (string) ($f['user_id'] ?? ($_POST['user_id'] ?? ''));
            }
        } else {
            try {
                if ($staffId === 0) {
                    $pdo->prepare(
                        'INSERT INTO staff (company_id, user_id, name, phone, designation, monthly_salary, joined_on, left_on, status, notes, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
                    )->execute([$companyId, $f['user_id'], $f['name'], $f['phone'], $f['designation'], $f['monthly_salary'], $f['joined_on'], $f['left_on'], $f['status'], $f['notes']]);
                    $status = 'Added ' . $f['name'] . '.';
                } else {
                    $pdo->prepare(
                        'UPDATE staff SET user_id = ?, name = ?, phone = ?, designation = ?, monthly_salary = ?, joined_on = ?, left_on = ?, status = ?, notes = ?, updated_at = NOW()
                         WHERE id = ? AND company_id = ?'
                    )->execute([$f['user_id'], $f['name'], $f['phone'], $f['designation'], $f['monthly_salary'], $f['joined_on'], $f['left_on'], $f['status'], $f['notes'], $staffId, $companyId]);
                    $status = 'Updated ' . $f['name'] . '. Draft payroll uses the new details when you recalculate it.';
                }
            } catch (PDOException $e) {
                error_log('staff save failed: ' . $e->getMessage());
                $error = 'Could not save this staff member.';
            }
        }
    } elseif (isset($_POST['toggle_staff_id'])) {
        $id = (int) $_POST['toggle_staff_id'];
        $stmt = $pdo->prepare('SELECT status, left_on FROM staff WHERE id = ? AND company_id = ?');
        $stmt->execute([$id, $companyId]);
        if ($row = $stmt->fetch()) {
            if ($row['status'] === 'active') {
                // Leaving: stops future payroll after their last working day.
                $pdo->prepare("UPDATE staff SET status = 'inactive', left_on = COALESCE(left_on, ?), updated_at = NOW() WHERE id = ?")->execute([$today, $id]);
                $status = 'Marked as no longer working here (last day ' . date('d M Y', strtotime($row['left_on'] ?: $today)) . ').';
            } else {
                $pdo->prepare("UPDATE staff SET status = 'active', left_on = NULL, updated_at = NOW() WHERE id = ?")->execute([$id]);
                $status = 'Staff member is active again.';
            }
        }
    } elseif (isset($_POST['delete_staff_id'])) {
        $id = (int) $_POST['delete_staff_id'];
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM staff_payroll WHERE staff_id = ? AND company_id = ? AND status = 'paid'");
        $stmt->execute([$id, $companyId]);
        if ((int) $stmt->fetchColumn() > 0) {
            $error = 'This person has paid salary records, so they can\'t be deleted. Mark them as no longer working here instead.';
        } else {
            $pdo->prepare('DELETE FROM staff WHERE id = ? AND company_id = ?')->execute([$id, $companyId]);
            $status = 'Staff member deleted along with their attendance and unpaid payroll.';
        }
    } elseif (isset($_POST['give_advance'])) {
        $id = (int) $_POST['give_advance'];
        $amount = (float) ($_POST['amount'] ?? 0);
        $recovery = (float) ($_POST['monthly_recovery'] ?? 0);
        $givenOn = trim((string) ($_POST['given_on'] ?? ''));
        $note = trim((string) ($_POST['notes'] ?? ''));
        $d = DateTime::createFromFormat('Y-m-d', $givenOn);

        $stmt = $pdo->prepare('SELECT name FROM staff WHERE id = ? AND company_id = ?');
        $stmt->execute([$id, $companyId]);
        $staffName = $stmt->fetchColumn();

        if (! $staffName) {
            $error = 'Staff member not found.';
        } elseif ($amount <= 0 || $amount > 9999999.99) {
            $error = 'Enter the advance amount.';
        } elseif ($recovery < 0 || $recovery > $amount) {
            $error = 'The monthly recovery can\'t be more than the advance itself (use 0 to recover it all from the next salary).';
        } elseif (! $d || $d->format('Y-m-d') !== $givenOn) {
            $error = 'Enter the date the advance was given.';
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    "INSERT INTO expenses (company_id, category, amount, currency_code, date, notes, created_at, updated_at)
                     VALUES (?, 'labor', ?, ?, ?, ?, NOW(), NOW())"
                )->execute([$companyId, $amount, $settings['currency'], $givenOn, 'Salary advance: ' . $staffName]);
                $expenseId = (int) $pdo->lastInsertId();
                $pdo->prepare(
                    'INSERT INTO staff_advances (company_id, staff_id, given_on, amount, monthly_recovery, notes, expense_id, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
                )->execute([$companyId, $id, $givenOn, $amount, $recovery, $note !== '' ? substr($note, 0, 255) : null, $expenseId]);
                $pdo->commit();
                $status = 'Advance of ' . hef_money($amount) . ' recorded for ' . $staffName . '. It is also booked as an expense in Finances.';
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log('advance failed: ' . $e->getMessage());
                $error = 'Could not record the advance.';
            }
        }
    }
}

// Coming from the "Add" button beside an app user who isn't staff yet.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['add_user'])) {
    $stmt = $pdo->prepare('SELECT id, name, phone FROM users WHERE id = ? AND company_id = ? AND is_super_admin = 0');
    $stmt->execute([(int) $_GET['add_user'], $companyId]);
    if ($u = $stmt->fetch()) {
        $addValues['name'] = $u['name'];
        $addValues['phone'] = (string) $u['phone'];
        $addValues['user_id'] = (string) $u['id'];
        $openAdd = true;
    }
}

// Staff, with their advance balance.
$stmt = $pdo->prepare(
    'SELECT s.*, COALESCE((SELECT SUM(a.amount - a.recovered) FROM staff_advances a WHERE a.staff_id = s.id), 0) AS advance_balance
     FROM staff s WHERE s.company_id = ? ORDER BY (s.status = "active") DESC, s.name'
);
$stmt->execute([$companyId]);
$staffList = $stmt->fetchAll();

// App users, for linking.
$stmt = $pdo->prepare('SELECT id, name, role, phone FROM users WHERE company_id = ? AND is_super_admin = 0 ORDER BY name');
$stmt->execute([$companyId]);
$allUsers = $stmt->fetchAll();
$linkedUserIds = [];
foreach ($staffList as $s) {
    if ($s['user_id']) {
        $linkedUserIds[(int) $s['user_id']] = true;
    }
}
$unlinkedUsers = array_values(array_filter($allUsers, function ($u) use ($linkedUserIds) {
    return ! isset($linkedUserIds[(int) $u['id']]);
}));

/** The staff form fields, shared by the add and edit dialogs. */
function hef_staff_form_fields(array $v, string $id, array $userChoices, bool $isEdit): void
{
    ?>
    <div class="row g-2 mb-2">
        <div class="col-12 col-md-6">
            <label class="form-label small" for="<?= $id ?>Name">Name</label>
            <input type="text" name="name" id="<?= $id ?>Name" class="form-control" maxlength="255" required value="<?= htmlspecialchars((string) $v['name']) ?>">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small" for="<?= $id ?>Phone">Phone</label>
            <input type="text" name="phone" id="<?= $id ?>Phone" class="form-control" maxlength="20" value="<?= htmlspecialchars((string) $v['phone']) ?>">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small" for="<?= $id ?>Role">Designation</label>
            <input type="text" name="designation" id="<?= $id ?>Role" class="form-control" maxlength="100" placeholder="e.g. Supervisor" value="<?= htmlspecialchars((string) $v['designation']) ?>">
        </div>
    </div>
    <div class="row g-2 mb-2">
        <div class="col-6 col-md-3">
            <label class="form-label small" for="<?= $id ?>Salary">Monthly salary (₹)</label>
            <input type="number" name="monthly_salary" id="<?= $id ?>Salary" class="form-control" min="0" step="0.01" required value="<?= htmlspecialchars((string) $v['monthly_salary']) ?>">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small" for="<?= $id ?>Joined">Joined on</label>
            <input type="date" name="joined_on" id="<?= $id ?>Joined" class="form-control" value="<?= htmlspecialchars((string) $v['joined_on']) ?>">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small" for="<?= $id ?>Left">Last working day</label>
            <input type="date" name="left_on" id="<?= $id ?>Left" class="form-control" value="<?= htmlspecialchars((string) $v['left_on']) ?>">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small" for="<?= $id ?>Status">Status</label>
            <select name="status" id="<?= $id ?>Status" class="form-select">
                <option value="active" <?= $v['status'] !== 'inactive' ? 'selected' : '' ?>>Working here</option>
                <option value="inactive" <?= $v['status'] === 'inactive' ? 'selected' : '' ?>>Left</option>
            </select>
        </div>
    </div>
    <div class="row g-2 mb-2">
        <div class="col-12 col-md-6">
            <label class="form-label small" for="<?= $id ?>User">App login <span class="text-muted">(optional)</span></label>
            <select name="user_id" id="<?= $id ?>User" class="form-select">
                <option value="">No app access</option>
                <?php foreach ($userChoices as $u): ?>
                    <option value="<?= (int) $u['id'] ?>" <?= (string) $v['user_id'] === (string) $u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['name']) ?> (<?= htmlspecialchars($u['role']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label small" for="<?= $id ?>Notes">Notes</label>
            <input type="text" name="notes" id="<?= $id ?>Notes" class="form-control" value="<?= htmlspecialchars((string) $v['notes']) ?>">
        </div>
    </div>
    <div class="form-text">Salary changes apply to payroll you calculate from now on; salary already paid isn't changed.</div>
    <?php
}

require_once __DIR__ . '/header.php';
$activeCount = count(array_filter($staffList, function ($s) { return $s['status'] === 'active'; }));
$monthlyTotal = array_sum(array_map(function ($s) { return $s['status'] === 'active' ? (float) $s['monthly_salary'] : 0; }, $staffList));
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-person-vcard me-2"></i>Staff</h4>
    <div class="d-flex gap-2">
        <a href="/hef/admin/staff-payroll.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-cash-stack me-1"></i>Payroll</a>
        <button type="button" class="btn btn-hef text-white btn-sm" data-bs-toggle="modal" data-bs-target="#addStaffModal"><i class="bi bi-plus-lg me-1"></i>Add staff</button>
    </div>
</div>

<?php if ($status): ?><div class="alert alert-success py-2"><?= htmlspecialchars($status) ?></div><?php endif; ?>
<?php if ($error && ! $openAdd): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card p-3 text-center"><div class="fs-3 fw-bold"><?= $activeCount ?></div><div class="text-muted small">Working here</div></div></div>
    <div class="col-6 col-lg-3"><div class="card p-3 text-center"><div class="fs-3 fw-bold"><?= hef_money($monthlyTotal) ?></div><div class="text-muted small">Monthly salary bill</div></div></div>
    <div class="col-6 col-lg-3"><div class="card p-3 text-center"><div class="fs-3 fw-bold"><?= (int) $settings['salary_day'] ?><sup class="fs-6"><?= date('S', mktime(0, 0, 0, 1, (int) $settings['salary_day'])) ?></sup></div><div class="text-muted small">Salary day (change in Settings)</div></div></div>
    <div class="col-6 col-lg-3"><div class="card p-3 text-center"><div class="fs-3 fw-bold"><?= hef_money(array_sum(array_column($staffList, 'advance_balance'))) ?></div><div class="text-muted small">Advances to recover</div></div></div>
</div>

<?php if ($unlinkedUsers): ?>
    <div class="alert alert-info py-2 small">
        <i class="bi bi-people me-1"></i>These app users aren't in your staff list yet:
        <?php foreach ($unlinkedUsers as $u): ?>
            <a href="?add_user=<?= (int) $u['id'] ?>" class="badge bg-light text-dark border text-decoration-none ms-1"><i class="bi bi-plus"></i> <?= htmlspecialchars($u['name']) ?></a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (empty($staffList)): ?>
    <div class="card p-4 text-muted">No staff yet. Add the people who work on your farm, with or without an app login, to track attendance and pay salaries.</div>
<?php else: ?>
<div class="card">
    <div class="table-responsive">
        <table class="table align-middle mb-0" style="font-size:0.92rem;">
            <thead class="table-light">
                <tr><th>Name</th><th>Phone</th><th class="text-end">Salary</th><th>Joined</th><th class="text-end">Advance owed</th><th>Status</th><th style="width:1%;"></th></tr>
            </thead>
            <tbody>
                <?php foreach ($staffList as $s): ?>
                    <tr class="<?= $s['status'] === 'inactive' ? 'text-muted' : '' ?>">
                        <td>
                            <a href="/hef/admin/staff-view.php?id=<?= (int) $s['id'] ?>" class="fw-semibold text-decoration-none"><?= htmlspecialchars($s['name']) ?></a>
                            <?php if ($s['user_id']): ?><span class="badge bg-info-subtle text-info ms-1" title="Has an app login"><i class="bi bi-phone"></i> App</span><?php endif; ?>
                            <?php if ($s['designation']): ?><div class="small text-muted"><?= htmlspecialchars($s['designation']) ?></div><?php endif; ?>
                        </td>
                        <td class="text-nowrap"><?= htmlspecialchars((string) $s['phone']) ?> <?= hef_contact_buttons($s['phone']) ?></td>
                        <td class="text-end"><?= hef_money($s['monthly_salary']) ?></td>
                        <td class="small"><?= $s['joined_on'] ? date('d M Y', strtotime($s['joined_on'])) : '—' ?></td>
                        <td class="text-end <?= (float) $s['advance_balance'] > 0 ? 'text-danger fw-semibold' : '' ?>"><?= (float) $s['advance_balance'] > 0 ? hef_money($s['advance_balance']) : '—' ?></td>
                        <td>
                            <?php if ($s['status'] === 'active'): ?><span class="badge bg-success-subtle text-success">Working</span>
                            <?php else: ?><span class="badge bg-secondary-subtle text-secondary">Left<?= $s['left_on'] ? ' ' . date('d M Y', strtotime($s['left_on'])) : '' ?></span><?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <?php if ($s['status'] === 'active'): ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" title="Give advance" data-bs-toggle="modal" data-bs-target="#advanceStaff<?= (int) $s['id'] ?>"><i class="bi bi-cash-coin"></i></button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary" title="Edit" data-bs-toggle="modal" data-bs-target="#editStaff<?= (int) $s['id'] ?>"><i class="bi bi-pencil"></i></button>
                                <form method="POST" onsubmit="return confirm(<?= htmlspecialchars(json_encode($s['status'] === 'active' ? 'Mark ' . $s['name'] . ' as no longer working here? They are left out of payroll after today.' : 'Make ' . $s['name'] . ' active again?'), ENT_QUOTES) ?>);">
                                    <input type="hidden" name="toggle_staff_id" value="<?= (int) $s['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-warning" title="<?= $s['status'] === 'active' ? 'Mark as left' : 'Make active' ?>"><i class="bi <?= $s['status'] === 'active' ? 'bi-box-arrow-right' : 'bi-arrow-counterclockwise' ?>"></i></button>
                                </form>
                                <form method="POST" onsubmit="return confirm(<?= htmlspecialchars(json_encode('Delete ' . $s['name'] . ' permanently, with their attendance and unpaid payroll? Use "Mark as left" to keep the history.'), ENT_QUOTES) ?>);">
                                    <input type="hidden" name="delete_staff_id" value="<?= (int) $s['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Add -->
<div class="modal fade" id="addStaffModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="save_staff" value="0">
                <div class="modal-header"><h5 class="modal-title">Add staff</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <?php if ($error && $openAdd): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                    <?php hef_staff_form_fields($addValues, 'addStaff', $unlinkedUsers, false); ?>
                </div>
                <div class="modal-footer"><button type="submit" class="btn btn-hef text-white w-100">Add staff</button></div>
            </form>
        </div>
    </div>
</div>

<?php foreach ($staffList as $s): ?>
    <?php
        // This person's own login plus any not yet linked to someone else.
        $choices = array_values(array_filter($allUsers, function ($u) use ($linkedUserIds, $s) {
            return (int) $u['id'] === (int) $s['user_id'] || ! isset($linkedUserIds[(int) $u['id']]);
        }));
    ?>
    <div class="modal fade" id="editStaff<?= (int) $s['id'] ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="save_staff" value="<?= (int) $s['id'] ?>">
                    <div class="modal-header"><h5 class="modal-title">Edit <?= htmlspecialchars($s['name']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body"><?php hef_staff_form_fields($s, 'editStaff' . (int) $s['id'], $choices, true); ?></div>
                    <div class="modal-footer"><button type="submit" class="btn btn-hef text-white w-100">Save changes</button></div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($s['status'] === 'active'): ?>
    <div class="modal fade" id="advanceStaff<?= (int) $s['id'] ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="give_advance" value="<?= (int) $s['id'] ?>">
                    <div class="modal-header"><h5 class="modal-title">Advance to <?= htmlspecialchars($s['name']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <div class="row g-2 mb-2">
                            <div class="col-6"><label class="form-label small">Amount (₹)</label><input type="number" name="amount" class="form-control" min="1" step="0.01" required></div>
                            <div class="col-6"><label class="form-label small">Given on</label><input type="date" name="given_on" class="form-control" required value="<?= htmlspecialchars($today) ?>"></div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label small">Recover per month (₹)</label>
                            <input type="number" name="monthly_recovery" class="form-control" min="0" step="0.01" value="0">
                            <div class="form-text">0 = take it all from the next salary. Otherwise this much is deducted each month until it's cleared.</div>
                        </div>
                        <div class="mb-2"><label class="form-label small">Note</label><input type="text" name="notes" class="form-control" maxlength="255" placeholder="e.g. medical, festival"></div>
                        <?php if ((float) $s['advance_balance'] > 0): ?><div class="form-text text-danger">Already owes <?= hef_money($s['advance_balance']) ?> from earlier advances.</div><?php endif; ?>
                    </div>
                    <div class="modal-footer"><button type="submit" class="btn btn-hef text-white w-100">Record advance</button></div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
<?php endforeach; ?>

<?php if ($openAdd): ?>
<script>
window.addEventListener('load', function () {
    var el = document.getElementById('addStaffModal');
    if (el) { new bootstrap.Modal(el).show(); }
});
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
