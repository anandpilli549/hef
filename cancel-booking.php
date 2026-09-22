<?php
require_once __DIR__ . '/includes/db.php';

$bookingId = (int) ($_GET['booking_id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
$stmt->execute([$bookingId]);
$booking = $stmt->fetch();

if (! $booking) {
    header('Location: /hef/store.php');
    exit;
}

// Find the listing slug to send them back to, before we release anything
$stmt = $pdo->prepare(
    "SELECT sl.slug FROM booking_items bi JOIN storefront_listings sl ON sl.id = bi.storefront_listing_id
     WHERE bi.booking_id = ? LIMIT 1"
);
$stmt->execute([$bookingId]);
$slug = $stmt->fetchColumn();

// Only release if it's still genuinely pending — never touch an already-paid booking
if ($booking['status'] === 'pending_payment') {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT storefront_listing_id, quantity FROM booking_items WHERE booking_id = ?');
        $stmt->execute([$bookingId]);
        foreach ($stmt->fetchAll() as $item) {
            $pdo->prepare('UPDATE storefront_listings SET quantity_available = quantity_available + ? WHERE id = ?')
                ->execute([$item['quantity'], $item['storefront_listing_id']]);
        }
        $pdo->prepare("UPDATE bookings SET status = 'cancelled', updated_at = NOW() WHERE id = ?")->execute([$bookingId]);
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('cancel-booking release failed: ' . $e->getMessage());
    }
}

header('Location: /hef/listing.php?slug=' . urlencode($slug ?: ''));
exit;
