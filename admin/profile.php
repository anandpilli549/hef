<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/uploads.php';

$error = '';
$status = '';
$notifStatus = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_notifications'])) {
    $pdo->prepare('UPDATE users SET alerts_immediate = ?, digest_enabled = ?, updated_at = NOW() WHERE id = ?')
        ->execute([
            isset($_POST['alerts_immediate']) ? 1 : 0,
            isset($_POST['digest_enabled']) ? 1 : 0,
            $currentUser['user_id'],
        ]);
    $notifStatus = 'Notification settings saved.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');

    if (! $name) {
        $error = 'Name is required.';
    } else {
        try {
            $newPhoto = hef_handle_image_upload('photo', 'users');

            if ($newPhoto) {
                $stmt = $pdo->prepare('SELECT photo_path FROM users WHERE id = ?');
                $stmt->execute([$currentUser['user_id']]);
                $oldPhoto = $stmt->fetchColumn();

                $pdo->prepare('UPDATE users SET name = ?, photo_path = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$name, $newPhoto, $currentUser['user_id']]);
                hef_delete_uploaded_image($oldPhoto);
            } else {
                $pdo->prepare('UPDATE users SET name = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$name, $currentUser['user_id']]);
            }
            $status = 'Profile updated.';
            $currentUser['name'] = $name; // reflect immediately in the sidebar for this render
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
}

require_once __DIR__ . '/header.php';

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$currentUser['user_id']]);
$user = $stmt->fetch();
?>
<h4 class="mb-3"><i class="bi bi-person-circle me-2"></i>My Profile</h4>

<div class="card p-4" style="max-width: 450px;">
    <?php if ($status): ?><div class="alert alert-success py-2"><?= htmlspecialchars($status) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="text-center mb-3">
        <?php if ($user['photo_path']): ?>
            <img src="/hef/<?= htmlspecialchars($user['photo_path']) ?>" class="rounded-circle" style="width:100px; height:100px; object-fit:cover;">
        <?php else: ?>
            <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center" style="width:100px; height:100px;">
                <i class="bi bi-person fs-1 text-muted"></i>
            </div>
        <?php endif; ?>
    </div>

    <form method="POST" enctype="multipart/form-data">
        <div class="mb-2">
            <label class="form-label small">Profile picture</label>
            <input type="file" name="photo" class="form-control" accept="image/*">
        </div>
        <div class="mb-2">
            <label class="form-label small">Name</label>
            <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($user['name']) ?>">
        </div>
        <div class="mb-3">
            <label class="form-label small">Email</label>
            <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" disabled>
            <div class="form-text">Email can't be changed here since it's tied to your login.</div>
        </div>
        <button type="submit" class="btn btn-hef text-white w-100">Save profile</button>
    </form>
</div>

<div class="card p-4 mt-3" style="max-width: 450px;">
    <h6 class="mb-3"><i class="bi bi-envelope me-2"></i>Email notifications</h6>
    <?php if ($notifStatus): ?><div class="alert alert-success py-2"><?= htmlspecialchars($notifStatus) ?></div><?php endif; ?>
    <form method="POST">
        <input type="hidden" name="save_notifications" value="1">
        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" name="alerts_immediate" id="alertsImmediate" <?= (int) $user['alerts_immediate'] === 1 ? 'checked' : '' ?>>
            <label class="form-check-label" for="alertsImmediate">Email me each alert as it happens</label>
            <div class="form-text">Vaccination due, feed running out, subscription expiring. Booking emails aren't affected.</div>
        </div>
        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" name="digest_enabled" id="digestEnabled" <?= (int) $user['digest_enabled'] === 1 ? 'checked' : '' ?>>
            <label class="form-check-label" for="digestEnabled">Send me a daily summary</label>
            <div class="form-text">One morning email listing what still needs attention: vaccinations due, feed out, and your overdue and upcoming reminders. Nothing is sent on days with nothing to report.</div>
        </div>
        <button type="submit" class="btn btn-hef text-white">Save</button>
    </form>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
