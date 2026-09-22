<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/permissions.php';

// Vet has no reason to see bookings at all. Worker can view but not act.
hef_require_role(['owner', 'worker'], $currentUser['role']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $currentUser['role'] !== 'owner') {
    header('Location: /hef/admin/bookings.php?error=access_denied');
    exit;
}

$companyId = $currentUser['company_id'];
$error = '';
$status = '';

$stmt = $pdo->prepare('SELECT razorpay_key_id, razorpay_key_secret FROM payment_settings WHERE company_id = ?');
$stmt->execute([$companyId]);
$paymentSettings = $stmt->fetch();

// --- Update booking status (confirm/fulfill/mark unavailable) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status_id'])) {
    $id = (int) $_POST['update_status_id'];
    $newStatus = $_POST['new_status'] ?? '';
    $allowed = ['confirmed', 'fulfilled', 'unavailable', 'cancelled'];

    if (in_array($newStatus, $allowed)) {
        $pdo->prepare('UPDATE bookings SET status = ?, updated_at = NOW() WHERE id = ? AND company_id = ?')
            ->execute([$newStatus, $id, $companyId]);
        $status = 'Booking updated.';
    }
}

// --- Process a refund via Razorpay ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refund_booking_id'])) {
    $id = (int) $_POST['refund_booking_id'];
    $refundPercent = (float) ($_POST['refund_percent'] ?? 100);

    $stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = ? AND company_id = ?');
    $stmt->execute([$id, $companyId]);
    $booking = $stmt->fetch();

    if (! $booking || ! $booking['razorpay_payment_id']) {
        $error = 'This booking has no payment to refund.';
    } elseif (! $paymentSettings || ! $paymentSettings['razorpay_key_secret']) {
        $error = 'Add your Razorpay credentials in Settings before processing refunds.';
    } else {
        $refundAmountPaise = (int) round($booking['total_amount'] * ($refundPercent / 100) * 100);

        $ch = curl_init("https://api.razorpay.com/v1/payments/{$booking['razorpay_payment_id']}/refund");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_USERPWD => $paymentSettings['razorpay_key_id'] . ':' . $paymentSettings['razorpay_key_secret'],
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['amount' => $refundAmountPaise]),
            CURLOPT_TIMEOUT => 20,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($response, true);

        if ($httpCode === 200 && ! empty($data['id'])) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE bookings SET status = 'refunded', updated_at = NOW() WHERE id = ?")->execute([$id]);
                $pdo->prepare(
                    "INSERT INTO refund_requests (booking_id, reason, requested_at, status, refunded_amount, razorpay_refund_id, resolved_by_user_id, resolved_at, created_at, updated_at)
                     VALUES (?, 'Refund processed by owner', NOW(), 'resolved', ?, ?, ?, NOW(), NOW(), NOW())"
                )->execute([$id, $refundAmountPaise / 100, $data['id'], $currentUser['user_id']]);
                $pdo->commit();
                $status = 'Refund processed successfully.';
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('refund record failed: ' . $e->getMessage());
                $error = 'Refund was processed on Razorpay but recording it failed — check Razorpay dashboard.';
            }
        } else {
            error_log('Razorpay refund failed: ' . $response);
            $error = 'Refund failed: ' . ($data['error']['description'] ?? 'unknown error');
        }
    }
}

require_once __DIR__ . '/header.php';

$stmt = $pdo->prepare('SELECT * FROM refund_policies WHERE company_id = ?');
$stmt->execute([$companyId]);
$refundPolicy = $stmt->fetch();
$defaultRefundPercent = $refundPolicy ? (int) $refundPolicy['refund_percentage'] : 100;

$perPage = 15;
$page = hef_current_page();
$statusFilter = $_GET['status'] ?? '';

$where = 'WHERE b.company_id = ?';
$params = [$companyId];
if ($statusFilter) {
    $where .= ' AND b.status = ?';
    $params[] = $statusFilter;
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings b {$where}");
$stmt->execute($params);
$totalBookings = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT b.* FROM bookings b {$where} ORDER BY b.created_at DESC LIMIT {$perPage} OFFSET " . hef_offset($page, $perPage)
);
$stmt->execute($params);
$bookings = $stmt->fetchAll();

// Fetch items for each booking shown
$itemsByBooking = [];
if ($bookings) {
    $ids = array_column($bookings, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT bi.booking_id, bi.quantity, sl.title FROM booking_items bi
         JOIN storefront_listings sl ON sl.id = bi.storefront_listing_id
         WHERE bi.booking_id IN ({$in})"
    );
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) {
        $itemsByBooking[$row['booking_id']][] = $row;
    }
}

$statusOptions = ['pending_payment', 'paid', 'confirmed', 'fulfilled', 'unavailable', 'refund_requested', 'refunded', 'cancelled'];
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-bag-check me-2"></i>Bookings</h4>
    <div class="d-flex gap-2 align-items-center">
        <a href="/hef/admin/export.php?type=bookings<?= $statusFilter !== '' ? '&amp;status=' . urlencode($statusFilter) : '' ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-download me-1"></i>Export CSV</a>
        <select class="form-select form-select-sm" style="width:auto;" onchange="window.location.href = this.value ? '?status='+this.value : '?'">
            <option value="">All statuses</option>
            <?php foreach ($statusOptions as $so): ?>
                <option value="<?= $so ?>" <?= $so === $statusFilter ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $so)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<?php if ($status): ?><div class="alert alert-success py-2"><?= htmlspecialchars($status) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<?php if (! $paymentSettings || ! $paymentSettings['razorpay_key_id']): ?>
    <div class="alert alert-warning">Razorpay isn't set up yet — bookings can be created but payments/refunds won't work until you add credentials in <a href="/hef/admin/settings.php">Settings</a>.</div>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table class="table mb-0">
            <thead>
                <tr><th>#</th><th>Customer</th><th>Items</th><th>Fulfillment</th><th class="text-end">Total</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php if (empty($bookings)): ?>
                    <tr><td colspan="7" class="text-muted text-center py-4">No bookings yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($bookings as $b): ?>
                    <tr>
                        <td>#<?= $b['id'] ?></td>
                        <td>
                            <?= htmlspecialchars($b['customer_name']) ?><br>
                            <span class="text-muted small"><?= htmlspecialchars($b['customer_phone']) ?></span>
                        </td>
                        <td class="small">
                            <?php foreach ($itemsByBooking[$b['id']] ?? [] as $item): ?>
                                <?= (int) $item['quantity'] ?> × <?= htmlspecialchars($item['title']) ?><br>
                            <?php endforeach; ?>
                        </td>
                        <td class="text-capitalize"><?= htmlspecialchars($b['fulfillment_type']) ?></td>
                        <td class="text-end">₹<?= number_format($b['total_amount'], 0) ?></td>
                        <td><span class="badge bg-secondary-subtle text-dark text-capitalize"><?= str_replace('_', ' ', $b['status']) ?></span></td>
                        <td>
                            <div class="d-flex gap-1 flex-wrap">
                                <?php if (in_array($b['status'], ['paid', 'confirmed'])): ?>
                                    <form method="POST">
                                        <input type="hidden" name="update_status_id" value="<?= $b['id'] ?>">
                                        <input type="hidden" name="new_status" value="<?= $b['status'] === 'paid' ? 'confirmed' : 'fulfilled' ?>">
                                        <button class="btn btn-sm btn-hef text-white"><?= $b['status'] === 'paid' ? 'Confirm' : 'Mark Fulfilled' ?></button>
                                    </form>
                                <?php endif; ?>
                                <?php if (in_array($b['status'], ['paid', 'confirmed', 'fulfilled']) && $b['razorpay_payment_id']): ?>
                                    <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#refund<?= $b['id'] ?>">Refund</button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>

                    <div class="modal fade" id="refund<?= $b['id'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <form method="POST" onsubmit="return confirm('Process this refund via Razorpay? This cannot be undone.');">
                                    <input type="hidden" name="refund_booking_id" value="<?= $b['id'] ?>">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Refund booking #<?= $b['id'] ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <p>Total paid: ₹<?= number_format($b['total_amount'], 0) ?></p>
                                        <?php if ($refundPolicy): ?>
                                            <div class="alert alert-info small py-2">
                                                Your policy: <?= (int) $refundPolicy['refund_percentage'] ?>% refund by default
                                                <?= $refundPolicy['replacement_allowed'] ? ', replacement offered if preferred' : '' ?>
                                                · respond within <?= (int) $refundPolicy['response_sla_hours'] ?>h
                                                <?php if ($refundPolicy['notes']): ?><br><?= htmlspecialchars($refundPolicy['notes']) ?><?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <label class="form-label small">Refund percentage</label>
                                        <select name="refund_percent" class="form-select">
                                            <option value="100" <?= $defaultRefundPercent === 100 ? 'selected' : '' ?>>100% (Full refund)</option>
                                            <option value="50" <?= $defaultRefundPercent === 50 ? 'selected' : '' ?>>50%</option>
                                            <option value="0" <?= $defaultRefundPercent === 0 ? 'selected' : '' ?>>0% (Reject, no refund)</option>
                                        </select>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="submit" class="btn btn-outline-danger w-100">Process refund</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?= hef_pagination_links($page, $totalBookings, $perPage) ?>

<?php require_once __DIR__ . '/footer.php'; ?>
