<?php
require_once __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['revoke_device_id'])) {
    $currentTokenHash = hash('sha256', $_COOKIE[DEVICE_COOKIE_NAME] ?? '');
    $stmt = $pdo->prepare('SELECT session_token FROM user_devices WHERE id = ? AND user_id = ?');
    $stmt->execute([$_POST['revoke_device_id'], $currentUser['user_id']]);
    $target = $stmt->fetch();

    if ($target && $target['session_token'] !== $currentTokenHash) {
        $pdo->prepare('UPDATE user_devices SET revoked_at = NOW() WHERE id = ? AND user_id = ?')
            ->execute([$_POST['revoke_device_id'], $currentUser['user_id']]);
    }

    header('Location: /hef/admin/devices.php');
    exit;
}

require_once __DIR__ . '/header.php';

$currentTokenHash = hash('sha256', $_COOKIE[DEVICE_COOKIE_NAME] ?? '');
$stmt = $pdo->prepare(
    'SELECT * FROM user_devices WHERE user_id = ? AND revoked_at IS NULL ORDER BY last_active_at DESC'
);
$stmt->execute([$currentUser['user_id']]);
$devices = $stmt->fetchAll();
?>
<h4 class="mb-3"><i class="bi bi-phone me-2"></i>Signed-in devices</h4>

<div class="card">
    <div class="list-group list-group-flush">
    <?php foreach ($devices as $device): ?>
        <div class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2 py-3">
            <div>
                <div class="fw-semibold"><?= htmlspecialchars($device['device_name']) ?></div>
                <div class="text-muted small">
                    <?= htmlspecialchars($device['ip_address']) ?> ·
                    last active <?= htmlspecialchars($device['last_active_at']) ?>
                </div>
            </div>
            <?php if ($device['session_token'] === $currentTokenHash): ?>
                <span class="badge bg-success-subtle text-success">This device</span>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="revoke_device_id" value="<?= $device['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger">Log out</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
