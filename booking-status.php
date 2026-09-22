<?php
require_once __DIR__ . '/includes/db.php';

$bookingId = (int) ($_GET['booking_id'] ?? 0);
$error = $_GET['error'] ?? '';

$stmt = $pdo->prepare(
    "SELECT b.*, c.name AS company_name FROM bookings b JOIN companies c ON c.id = b.company_id WHERE b.id = ?"
);
$stmt->execute([$bookingId]);
$booking = $stmt->fetch();

if (! $booking) {
    http_response_code(404);
    die('Booking not found.');
}

$stmt = $pdo->prepare(
    "SELECT bi.*, sl.title FROM booking_items bi JOIN storefront_listings sl ON sl.id = bi.storefront_listing_id WHERE bi.booking_id = ?"
);
$stmt->execute([$bookingId]);
$items = $stmt->fetchAll();

$statusLabels = [
    'pending_payment' => ['Payment Pending', 'secondary'],
    'paid' => ['Payment Confirmed', 'success'],
    'confirmed' => ['Confirmed', 'success'],
    'fulfilled' => ['Fulfilled', 'success'],
    'unavailable' => ['Unavailable', 'danger'],
    'refund_requested' => ['Refund Requested', 'warning'],
    'refunded' => ['Refunded', 'secondary'],
    'replacement_offered' => ['Replacement Offered', 'info'],
    'resolved' => ['Resolved', 'secondary'],
    'cancelled' => ['Cancelled', 'secondary'],
];
[$statusLabel, $statusColor] = $statusLabels[$booking['status']] ?? [$booking['status'], 'secondary'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Booking Status</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f5f7f6; }
        .card { border: none; border-radius: 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh;">
<div class="card p-4" style="max-width:450px; width:100%;">
    <?php if ($error === 'verification_failed'): ?>
        <div class="alert alert-danger">We couldn't verify your payment. If money was deducted, please contact <?= htmlspecialchars($booking['company_name']) ?> directly with your booking reference below.</div>
    <?php endif; ?>

    <h5>Booking #<?= $bookingId ?></h5>
    <span class="badge bg-<?= $statusColor ?> mb-3"><?= htmlspecialchars($statusLabel) ?></span>

    <?php foreach ($items as $item): ?>
        <div class="d-flex justify-content-between border-bottom py-2">
            <span><?= (int) $item['quantity'] ?> × <?= htmlspecialchars($item['title']) ?></span>
            <span>₹<?= number_format($item['line_total'], 0) ?></span>
        </div>
    <?php endforeach; ?>

    <div class="d-flex justify-content-between pt-2">
        <span class="text-muted">Delivery</span>
        <span>₹<?= number_format($booking['delivery_fee'], 0) ?></span>
    </div>
    <div class="d-flex justify-content-between fw-bold pt-1">
        <span>Total</span>
        <span>₹<?= number_format($booking['total_amount'], 0) ?></span>
    </div>

    <p class="text-muted small mt-3 mb-0">A confirmation has been sent to <?= htmlspecialchars($booking['customer_email']) ?>. Keep this booking number for reference.</p>
</div>
</body>
</html>
