<?php
/**
 * Razorpay webhook endpoint — server-to-server payment confirmation.
 * This is the reliable path: unlike the browser callback (payment-
 * callback.php), it doesn't depend on the customer's browser making it
 * back to our site after paying.
 *
 * Set this URL in your Razorpay Dashboard → Settings → Webhooks:
 *   https://cthkennels.com/hef/webhook.php
 * Subscribe to at least: payment.captured
 * Razorpay will give you a "webhook secret" when you create it — paste
 * that into Settings → Razorpay Webhook Secret in the admin panel.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/booking-helpers.php';

$rawBody = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

$payload = json_decode($rawBody, true);

if (! $payload || ! $signature) {
    http_response_code(400);
    exit('Bad request');
}

// We need to know which company this event belongs to before we can
// verify it (each company has its own webhook secret) — pull the order
// ID out of the payload first, look up the booking/company from that,
// THEN verify the signature before trusting anything else in it.
$orderId = $payload['payload']['payment']['entity']['order_id']
    ?? $payload['payload']['order']['entity']['id']
    ?? null;

if (! $orderId) {
    http_response_code(200); // acknowledge so Razorpay doesn't retry — just not an event we care about
    exit('Ignored: no order id');
}

$stmt = $pdo->prepare('SELECT * FROM bookings WHERE razorpay_order_id = ?');
$stmt->execute([$orderId]);
$booking = $stmt->fetch();

if (! $booking) {
    http_response_code(200);
    exit('Ignored: unknown order');
}

$stmt = $pdo->prepare('SELECT razorpay_webhook_secret FROM payment_settings WHERE company_id = ?');
$stmt->execute([$booking['company_id']]);
$webhookSecret = $stmt->fetchColumn();

if (! $webhookSecret) {
    error_log("Webhook received for company {$booking['company_id']} but no webhook secret configured.");
    http_response_code(200); // acknowledge — misconfiguration on our side, not something to retry
    exit('Webhook secret not configured');
}

$expectedSignature = hash_hmac('sha256', $rawBody, $webhookSecret);

if (! hash_equals($expectedSignature, $signature)) {
    error_log("Webhook signature mismatch for booking {$booking['id']} (order {$orderId})");
    http_response_code(400);
    exit('Invalid signature');
}

// Signature verified — now we can trust the payload.
$event = $payload['event'] ?? '';

if ($event === 'payment.captured') {
    $paymentId = $payload['payload']['payment']['entity']['id'] ?? '';
    hef_finalize_paid_booking($pdo, (int) $booking['id'], $paymentId);
}

http_response_code(200);
echo 'OK';
