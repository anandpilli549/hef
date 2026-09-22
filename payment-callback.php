<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/booking-helpers.php';

$bookingId = (int) ($_POST['booking_id'] ?? 0);
$razorpayPaymentId = $_POST['razorpay_payment_id'] ?? '';
$razorpayOrderId = $_POST['razorpay_order_id'] ?? '';
$razorpaySignature = $_POST['razorpay_signature'] ?? '';

$stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
$stmt->execute([$bookingId]);
$booking = $stmt->fetch();

if (! $booking || $booking['razorpay_order_id'] !== $razorpayOrderId) {
    http_response_code(400);
    die('Invalid booking reference.');
}

$stmt = $pdo->prepare('SELECT razorpay_key_secret FROM payment_settings WHERE company_id = ?');
$stmt->execute([$booking['company_id']]);
$keySecret = $stmt->fetchColumn();

// Verify the payment signature — this is what proves the payment is genuine,
// not just a form submission claiming success.
$expectedSignature = hash_hmac('sha256', $razorpayOrderId . '|' . $razorpayPaymentId, $keySecret ?: '');

if (! hash_equals($expectedSignature, $razorpaySignature)) {
    error_log("Razorpay signature mismatch for booking {$bookingId}");
    header('Location: /hef/booking-status.php?booking_id=' . $bookingId . '&error=verification_failed');
    exit;
}

// This may already have been done by the webhook if it arrived first —
// hef_finalize_paid_booking() is idempotent, so that's fine either way.
$success = hef_finalize_paid_booking($pdo, $bookingId, $razorpayPaymentId);

if (! $success) {
    header('Location: /hef/booking-status.php?booking_id=' . $bookingId . '&error=processing_failed');
    exit;
}

header('Location: /hef/booking-status.php?booking_id=' . $bookingId);
exit;
