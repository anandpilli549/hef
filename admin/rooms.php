<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/audit.php';

// View is open to everyone; only Owner can add/edit/delete (every write
// on this page goes through POST).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $currentUser['role'] !== 'owner') {
    header('Location: /hef/admin/rooms.php?error=access_denied');
    exit;
}

$companyId = $currentUser['company_id'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_room_id'])) {
    $id = (int) $_POST['delete_room_id'];
    $stmt = $pdo->prepare('SELECT * FROM rooms WHERE id = ? AND company_id = ?');
    $stmt->execute([$id, $companyId]);
    $oldRow = $stmt->fetch();

    $pdo->prepare('DELETE FROM rooms WHERE id = ? AND company_id = ?')->execute([$id, $companyId]);
    if ($oldRow) {
        hef_audit_log($pdo, $companyId, $currentUser['user_id'], 'rooms', $id, 'delete', $oldRow, null);
    }
    header('Location: /hef/admin/rooms.php');
    exit;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_room_id'])) {
    $id = (int) $_POST['edit_room_id'];
    $name = trim($_POST['name'] ?? '');
    $capacity = $_POST['capacity'] !== '' ? (int) $_POST['capacity'] : null;

    if (! $name) {
        $error = 'Room name is required.';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT * FROM rooms WHERE id = ? AND company_id = ?');
            $stmt->execute([$id, $companyId]);
            $oldRow = $stmt->fetch();

            $pdo->prepare('UPDATE rooms SET name = ?, capacity = ?, updated_at = NOW() WHERE id = ? AND company_id = ?')
                ->execute([$name, $capacity, $id, $companyId]);
            if ($oldRow) {
                hef_audit_log($pdo, $companyId, $currentUser['user_id'], 'rooms', $id, 'update', $oldRow, ['name' => $name, 'capacity' => $capacity]);
            }
            header('Location: /hef/admin/rooms.php');
            exit;
        } catch (PDOException $e) {
            error_log('rooms update failed: ' . $e->getMessage());
            $error = 'Could not update room. Please try again.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $capacity = $_POST['capacity'] !== '' ? (int) $_POST['capacity'] : null;

    if (! $name) {
        $error = 'Room name is required.';
    } else {
        try {
            $pdo->prepare('INSERT INTO rooms (company_id, name, capacity, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())')
                ->execute([$companyId, $name, $capacity]);
            header('Location: /hef/admin/rooms.php');
            exit;
        } catch (PDOException $e) {
            error_log('rooms insert failed: ' . $e->getMessage());
            $error = 'Could not save room. Please check the values and try again.';
        }
    }
}

require_once __DIR__ . '/header.php';

$perPage = 10;
$page = hef_current_page();
$stmt = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE company_id = ?');
$stmt->execute([$companyId]);
$totalRooms = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT * FROM rooms WHERE company_id = ? ORDER BY name LIMIT ' . $perPage . ' OFFSET ' . hef_offset($page, $perPage));
$stmt->execute([$companyId]);
$rooms = $stmt->fetchAll();
?>
<h4 class="mb-3"><i class="bi bi-door-open me-2"></i>Rooms</h4>

<div class="row g-3">
    <?php if ($currentUser['role'] === 'owner'): ?>
    <div class="col-12 col-lg-5">
        <div class="card p-3">
            <h6 class="mb-3">Add room</h6>
            <?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="POST">
                <div class="mb-2">
                    <label class="form-label small">Name</label>
                    <input type="text" name="name" class="form-control" required placeholder="e.g. Coop 1">
                </div>
                <div class="mb-3">
                    <label class="form-label small">Capacity</label>
                    <input type="number" name="capacity" class="form-control" placeholder="e.g. 50">
                </div>
                <button type="submit" class="btn btn-hef text-white w-100">Add room</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="list-group list-group-flush">
                <?php if (empty($rooms)): ?>
                    <div class="list-group-item text-muted">No rooms added yet.</div>
                <?php endif; ?>
                <?php foreach ($rooms as $r): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-semibold"><?= htmlspecialchars($r['name']) ?></div>
                            <div class="text-muted small">Capacity: <?= $r['capacity'] ? (int) $r['capacity'] : '—' ?></div>
                        </div>
                        <?php if ($currentUser['role'] === 'owner'): ?>
                        <div class="d-flex gap-1">
                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editRoom<?= $r['id'] ?>">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" onsubmit="return confirm('Delete this room? Any batches in it will become unassigned.');" class="d-inline">
                                <input type="hidden" name="delete_room_id" value="<?= $r['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($currentUser['role'] === 'owner'): ?>
                    <div class="modal fade" id="editRoom<?= $r['id'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <form method="POST">
                                    <input type="hidden" name="edit_room_id" value="<?= $r['id'] ?>">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Edit room</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="mb-2">
                                            <label class="form-label small">Name</label>
                                            <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($r['name']) ?>">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small">Capacity</label>
                                            <input type="number" name="capacity" class="form-control" value="<?= htmlspecialchars((string) $r['capacity']) ?>">
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="submit" class="btn btn-hef text-white w-100">Save changes</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?= hef_pagination_links($page, $totalRooms, $perPage) ?>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
