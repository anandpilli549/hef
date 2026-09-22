<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/audit.php';

if (! $currentUser['is_super_admin']) {
    header('Location: /hef/admin/dashboard.php');
    exit;
}

$status = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_id'])) {
    $result = hef_restore_audit_entry($pdo, (int) $_POST['restore_id']);
    if ($result['success']) {
        $status = $result['message'];
    } else {
        $error = $result['message'];
    }
}

require_once __DIR__ . '/header.php';

$perPage = 20;
$page = hef_current_page();

$stmt = $pdo->query('SELECT COUNT(*) FROM audit_logs');
$totalEntries = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT al.*, c.name AS company_name, u.name AS user_name
     FROM audit_logs al
     LEFT JOIN companies c ON c.id = al.company_id
     LEFT JOIN users u ON u.id = al.user_id
     ORDER BY al.created_at DESC
     LIMIT {$perPage} OFFSET " . hef_offset($page, $perPage)
);
$stmt->execute();
$entries = $stmt->fetchAll();
?>
<h4 class="mb-3"><i class="bi bi-clock-history me-2"></i>Audit Log</h4>

<?php if ($status): ?><div class="alert alert-success py-2"><?= htmlspecialchars($status) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table class="table mb-0">
            <thead>
                <tr><th>When</th><th>Company</th><th>User</th><th>Table</th><th>Record</th><th>Action</th><th>Changes</th><th>Restore</th></tr>
            </thead>
            <tbody>
                <?php if (empty($entries)): ?>
                    <tr><td colspan="8" class="text-muted text-center py-4">No audit entries yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($entries as $e): ?>
                    <tr>
                        <td class="small text-nowrap"><?= date('d M Y H:i', strtotime($e['created_at'])) ?></td>
                        <td class="small"><?= htmlspecialchars($e['company_name'] ?? '—') ?></td>
                        <td class="small"><?= htmlspecialchars($e['user_name'] ?? '—') ?></td>
                        <td class="small"><code><?= htmlspecialchars($e['table_name']) ?></code></td>
                        <td class="small">#<?= (int) $e['record_id'] ?></td>
                        <td><span class="badge <?= $e['action'] === 'delete' ? 'bg-danger-subtle text-danger' : 'bg-warning-subtle text-warning' ?> text-capitalize"><?= htmlspecialchars($e['action']) ?></span></td>
                        <td>
                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#diff<?= $e['id'] ?>">View</button>
                        </td>
                        <td>
                            <?php if ($e['restored_at']): ?>
                                <span class="badge bg-secondary-subtle text-secondary">Restored</span>
                            <?php else: ?>
                                <form method="POST" onsubmit="return confirm('Restore this record to its previous state? This writes directly to the database.');">
                                    <input type="hidden" name="restore_id" value="<?= $e['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-hef text-white">Restore</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <div class="modal fade" id="diff<?= $e['id'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title"><?= htmlspecialchars($e['table_name']) ?> #<?= (int) $e['record_id'] ?> — <?= htmlspecialchars(ucfirst($e['action'])) ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="row">
                                        <div class="col-6">
                                            <h6 class="small text-muted">Old data</h6>
                                            <pre class="small bg-light p-2 rounded" style="max-height:300px; overflow:auto;"><?= htmlspecialchars(json_encode(json_decode($e['old_data'] ?? '{}'), JSON_PRETTY_PRINT)) ?></pre>
                                        </div>
                                        <div class="col-6">
                                            <h6 class="small text-muted">New data</h6>
                                            <pre class="small bg-light p-2 rounded" style="max-height:300px; overflow:auto;"><?= htmlspecialchars(json_encode(json_decode($e['new_data'] ?? '{}'), JSON_PRETTY_PRINT)) ?></pre>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?= hef_pagination_links($page, $totalEntries, $perPage) ?>

<div class="alert alert-info small mt-3">
    Coverage note: this currently logs edits/deletes for Species, Rooms, and Batches. Other modules (Feed, Finances, Health records, Storefront, etc.) aren't wired into audit logging yet — let me know if you want those extended too.
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
