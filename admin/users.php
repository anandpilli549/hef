<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/mail-templates.php';
require_once __DIR__ . '/../includes/audit.php';

$companyId = $currentUser['company_id'];
$isOwner = $currentUser['role'] === 'owner';
$error = '';
$status = '';

if (! $isOwner && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Non-owners can't perform any of the actions on this page
    header('Location: /hef/admin/users.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['invite_user'])) {
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $role = $_POST['role'] ?? 'worker';

    if (! $name || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! in_array($role, ['worker', 'vet', 'owner'])) {
        $error = 'Please provide a valid name, email, and role.';
    } elseif ($limitMessage = hef_limit_reached($pdo, (int) $companyId, 'users')) {
        $error = $limitMessage; // plan's user limit reached
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'A user with this email already exists (in this or another company).';
        } else {
            try {
                $pdo->prepare(
                    "INSERT INTO users (company_id, name, email, role, is_super_admin, status, invited_by_user_id, created_at, updated_at)
                     VALUES (?, ?, ?, ?, 0, 'invited', ?, NOW(), NOW())"
                )->execute([$companyId, $name, $email, $role, $currentUser['user_id']]);

                $stmt = $pdo->prepare('SELECT name FROM companies WHERE id = ?');
                $stmt->execute([$companyId]);
                $companyName = $stmt->fetchColumn();

                $inner = '<p style="margin:0 0 8px; color:#444; font-size:14px;">' . htmlspecialchars($currentUser['name']) . ' has invited you to join <strong>' . htmlspecialchars($companyName) . '</strong> on HEFarm as a <strong>' . ucfirst($role) . '</strong>.</p>'
                    . '<p style="color:#666; font-size:13px;">No password needed — just log in with this email and we\'ll send you a one-time code.</p>'
                    . hef_email_button('https://cthkennels.com/hef/admin/login.php', 'Log In to HEFarm');

                hef_send_mail($pdo, $email, "You've been invited to join {$companyName} on HEFarm",
                    hef_email_wrap('👋 You\'re Invited', $inner, "Join {$companyName} on HEFarm"), $companyId);

                $status = "Invited {$name} ({$email}) as {$role}.";
            } catch (PDOException $e) {
                error_log('user invite failed: ' . $e->getMessage());
                $error = 'Could not send invite.';
            }
        }
    }
}

/**
 * The columns of a team member worth keeping in the audit log (never the
 * login token), so a Super Admin can review or restore a change or delete.
 */
function hef_user_snapshot(PDO $pdo, int $id, $companyId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, company_id, name, email, phone, photo_path, is_super_admin, role, invited_by_user_id, status,
                email_verified_at, alerts_immediate, digest_enabled, created_at, updated_at
         FROM users WHERE id = ? AND company_id = ? AND is_super_admin = 0'
    );
    $stmt->execute([$id, $companyId]);

    return $stmt->fetch() ?: null;
}

// Edit a team member's name, email, phone and role. (You edit your own
// details on My Profile, and can't change your own role here.)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user_id'])) {
    $id = (int) $_POST['edit_user_id'];
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = preg_replace('/[^0-9+\-\s()]/', '', trim($_POST['phone'] ?? ''));
    $newRole = $_POST['role'] ?? '';
    $old = $id === (int) $currentUser['user_id'] ? null : hef_user_snapshot($pdo, $id, $companyId);

    if ($id === (int) $currentUser['user_id']) {
        $error = 'Edit your own details on My Profile.';
    } elseif (! $old) {
        $error = 'User not found.';
    } elseif (! $name || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! in_array($newRole, ['worker', 'vet', 'owner'], true) || strlen($phone) > 20) {
        $error = 'Please provide a valid name, email, phone (up to 20 characters) and role.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
        $stmt->execute([$email, $id]);
        if ($stmt->fetch()) {
            $error = 'A user with this email already exists (in this or another company).';
        } else {
            try {
                // A new email address hasn't been verified yet; they confirm it with a one-time code when they next log in.
                $emailChanged = $email !== $old['email'];
                $pdo->prepare(
                    'UPDATE users SET name = ?, email = ?, phone = ?, role = ?,
                            email_verified_at = IF(?, NULL, email_verified_at), updated_at = NOW()
                     WHERE id = ? AND company_id = ? AND is_super_admin = 0'
                )->execute([$name, $email, $phone !== '' ? $phone : null, $newRole, $emailChanged ? 1 : 0, $id, $companyId]);

                hef_audit_log($pdo, (int) $companyId, (int) $currentUser['user_id'], 'users', $id, 'update', $old, hef_user_snapshot($pdo, $id, $companyId));
                $status = "Updated {$name}." . ($emailChanged ? ' They will log in with the new email.' : '');
            } catch (PDOException $e) {
                error_log('user update failed: ' . $e->getMessage());
                $error = 'Could not save changes.';
            }
        }
    }
}

// Delete a team member for good. Their personal notes and reminders go with
// them; records they created (vaccinations given, health entries) stay, just
// without their name. Use Disable to keep everything and only stop access.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user_id'])) {
    $id = (int) $_POST['delete_user_id'];
    $old = $id === (int) $currentUser['user_id'] ? null : hef_user_snapshot($pdo, $id, $companyId);

    if ($id === (int) $currentUser['user_id']) {
        $error = "You can't delete your own account.";
    } elseif (! $old) {
        $error = 'User not found.';
    } else {
        try {
            $pdo->prepare('DELETE FROM users WHERE id = ? AND company_id = ? AND is_super_admin = 0')->execute([$id, $companyId]);
            hef_audit_log($pdo, (int) $companyId, (int) $currentUser['user_id'], 'users', $id, 'delete', $old, null);
            $status = "Deleted {$old['name']}.";
        } catch (PDOException $e) {
            error_log('user delete failed: ' . $e->getMessage());
            $error = 'Could not delete this user. Disable them instead.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status_id'])) {
    $id = (int) $_POST['toggle_status_id'];
    if ($id != $currentUser['user_id']) {
        $stmt = $pdo->prepare('SELECT status FROM users WHERE id = ? AND company_id = ?');
        $stmt->execute([$id, $companyId]);
        $targetStatus = $stmt->fetchColumn();
        $newStatus = $targetStatus === 'active' ? 'disabled' : 'active';
        // Turning a disabled user back on takes up a seat again.
        $limitMessage = $targetStatus === 'disabled' ? hef_limit_reached($pdo, (int) $companyId, 'users') : null;
        if ($limitMessage) {
            $error = $limitMessage;
        } else {
            $pdo->prepare('UPDATE users SET status = ?, updated_at = NOW() WHERE id = ? AND company_id = ?')
                ->execute([$newStatus, $id, $companyId]);
            // Revoke all their device sessions if disabling
            if ($newStatus === 'disabled') {
                $pdo->prepare('UPDATE user_devices SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL')->execute([$id]);
            }
            $status = 'User status updated.';
        }
    } else {
        $error = "You can't disable your own account.";
    }
}

require_once __DIR__ . '/header.php';

$stmt = $pdo->prepare('SELECT * FROM users WHERE company_id = ? ORDER BY FIELD(role, "owner", "worker", "vet"), created_at');
$stmt->execute([$companyId]);
$users = $stmt->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-people me-2"></i>Team</h4>
    <?php if ($isOwner): ?>
        <button class="btn btn-hef text-white btn-sm" data-bs-toggle="modal" data-bs-target="#inviteModal">
            <i class="bi bi-person-plus me-1"></i>Invite user
        </button>
    <?php endif; ?>
</div>

<?php if ($status): ?><div class="alert alert-success py-2"><?= htmlspecialchars($status) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if (! $isOwner): ?>
    <div class="alert alert-info">Only the Owner can invite or manage team members. You're viewing this as a <?= htmlspecialchars(ucfirst($currentUser['role'])) ?>.</div>
<?php endif; ?>

<div class="card">
    <div class="list-group list-group-flush">
        <?php foreach ($users as $u): ?>
            <div class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <?php if ($u['photo_path']): ?>
                        <img src="/hef/<?= htmlspecialchars($u['photo_path']) ?>" class="rounded-circle" style="width:36px; height:36px; object-fit:cover;">
                    <?php else: ?>
                        <i class="bi bi-person-circle fs-3 text-muted"></i>
                    <?php endif; ?>
                    <div>
                        <div class="fw-semibold"><?= htmlspecialchars($u['name']) ?> <?= $u['id'] == $currentUser['user_id'] ? '<span class="text-muted small">(you)</span>' : '' ?></div>
                        <div class="text-muted small"><?= htmlspecialchars($u['email']) ?></div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge <?= $u['status'] === 'active' ? 'bg-success-subtle text-success' : ($u['status'] === 'invited' ? 'bg-warning-subtle text-warning' : 'bg-secondary-subtle text-secondary') ?> text-capitalize"><?= htmlspecialchars($u['status']) ?></span>

                    <?php if ($isOwner && $u['id'] != $currentUser['user_id']): ?>
                        <span class="badge bg-light text-dark text-capitalize"><?= htmlspecialchars($u['role']) ?></span>
                        <?php if (! $u['is_super_admin']): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary" title="Edit" data-bs-toggle="modal" data-bs-target="#editUser<?= (int) $u['id'] ?>">
                                <i class="bi bi-pencil"></i>
                            </button>
                        <?php endif; ?>
                        <form method="POST" onsubmit="return confirm('<?= $u['status'] === 'active' ? 'Disable' : 'Re-enable' ?> this user? <?= $u['status'] === 'active' ? 'They will be logged out of all devices immediately.' : '' ?>');">
                            <input type="hidden" name="toggle_status_id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-<?= $u['status'] === 'active' ? 'danger' : 'success' ?>">
                                <?= $u['status'] === 'active' ? 'Disable' : 'Enable' ?>
                            </button>
                        </form>
                        <?php if (! $u['is_super_admin']): ?>
                            <form method="POST" onsubmit="return confirm(<?= htmlspecialchars(json_encode('Delete ' . $u['name'] . ' permanently? Their own notes and reminders (including any shared with the team) are deleted with them, and their name is removed from records they created. To keep everything and only stop their access, use Disable instead.'), ENT_QUOTES) ?>);">
                                <input type="hidden" name="delete_user_id" value="<?= (int) $u['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge bg-light text-dark text-capitalize"><?= htmlspecialchars($u['role']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Edit dialogs (Owner only; not for yourself or a Super Admin) -->
<?php if ($isOwner): ?>
    <?php foreach ($users as $u): ?>
        <?php if ($u['id'] == $currentUser['user_id'] || $u['is_super_admin']) { continue; } ?>
        <div class="modal fade" id="editUser<?= (int) $u['id'] ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST">
                        <input type="hidden" name="edit_user_id" value="<?= (int) $u['id'] ?>">
                        <div class="modal-header">
                            <h5 class="modal-title">Edit team member</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-2">
                                <label class="form-label small">Name</label>
                                <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($u['name']) ?>">
                            </div>
                            <div class="row g-2 mb-2">
                                <div class="col-12 col-sm-7">
                                    <label class="form-label small">Email</label>
                                    <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($u['email']) ?>">
                                </div>
                                <div class="col-12 col-sm-5">
                                    <label class="form-label small">Phone</label>
                                    <input type="text" name="phone" class="form-control" maxlength="20" value="<?= htmlspecialchars((string) $u['phone']) ?>">
                                </div>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small">Role</label>
                                <select name="role" class="form-select">
                                    <option value="worker" <?= $u['role'] === 'worker' ? 'selected' : '' ?>>Worker — day-to-day data entry</option>
                                    <option value="vet" <?= $u['role'] === 'vet' ? 'selected' : '' ?>>Vet — health &amp; vaccination access</option>
                                    <option value="owner" <?= $u['role'] === 'owner' ? 'selected' : '' ?>>Owner — full access</option>
                                </select>
                            </div>
                            <div class="form-text">A new role applies straight away. If you change the email, they log in with the new address (a one-time code is sent there); no invite is re-sent.</div>
                        </div>
                        <div class="modal-footer">
                            <button type="submit" class="btn btn-hef text-white w-100">Save changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Invite Modal -->
<div class="modal fade" id="inviteModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="invite_user" value="1">
                <div class="modal-header">
                    <h5 class="modal-title">Invite team member</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label small">Name</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Email</label>
                        <input type="email" name="email" class="form-control" required>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Role</label>
                        <select name="role" class="form-select">
                            <option value="worker">Worker — day-to-day data entry</option>
                            <option value="vet">Vet — health & vaccination access</option>
                            <option value="owner">Owner — full access</option>
                        </select>
                    </div>
                    <div class="form-text">They'll get an email and can log in immediately with email + one-time code — no password to set up.</div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-hef text-white w-100">Send Invite</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
