<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/config.php';

$bookingId = (int) ($_GET['booking_id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
$stmt->execute([$bookingId]);
$booking = $stmt->fetch();

if (! $booking) {
    http_response_code(404);
    die('Booking not found.');
}

if ($booking['status'] !== 'pending_payment') {
    // Already paid or resolved some other way — send them to a status page instead
    header('Location: /hef/booking-status.php?booking_id=' . $bookingId);
    exit;
}

if (strtotime($booking['reserved_until']) < time()) {
    die('This booking reservation has expired. Please go back and book again.');
}

$stmt = $pdo->prepare('SELECT razorpay_key_id, razorpay_key_secret FROM payment_settings WHERE company_id = ?');
$stmt->execute([$booking['company_id']]);
$paymentSettings = $stmt->fetch();

if (! $paymentSettings || ! $paymentSettings['razorpay_key_id'] || ! $paymentSettings['razorpay_key_secret']) {
    die('This farm has not yet set up online payments. Please contact them directly to complete your order.');
}

// Create the Razorpay order if we haven't already
if (! $booking['razorpay_order_id']) {
    $amountPaise = (int) round($booking['total_amount'] * 100);

    $ch = curl_init('https://api.razorpay.com/v1/orders');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_USERPWD => $paymentSettings['razorpay_key_id'] . ':' . $paymentSettings['razorpay_key_secret'],
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'amount' => $amountPaise,
            'currency' => $booking['currency_code'],
            'receipt' => 'booking_' . $bookingId,
        ]),
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);

    if ($httpCode !== 200 || empty($data['id'])) {
        error_log("Razorpay order creation failed. HTTP: {$httpCode} | cURL error: {$curlError} | Response: {$response}");
        // TEMPORARY debug output — remove once the real cause is found
        die("Could not initiate payment. DEBUG INFO — HTTP: {$httpCode} | cURL error: " . htmlspecialchars($curlError) . " | Response: " . htmlspecialchars($response ?: '(empty)'));
    }

    $pdo->prepare('UPDATE bookings SET razorpay_order_id = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$data['id'], $bookingId]);
    $booking['razorpay_order_id'] = $data['id'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Complete Payment</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh; background:#f5f7f6;">

<div class="card p-4 text-center" style="max-width:400px; border:none; border-radius:14px; box-shadow:0 1px 3px rgba(0,0,0,0.06);">
    <h5>Total: ₹<?= number_format($booking['total_amount'], 0) ?></h5>
    <p class="text-muted small">Redirecting to secure payment…</p>
    <div class="spinner-border text-success mx-auto" role="status"></div>
</div>

<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
    var options = {
        key: "<?= htmlspecialchars($paymentSettings['razorpay_key_id']) ?>",
        amount: "<?= (int) round($booking['total_amount'] * 100) ?>",
        currency: "<?= htmlspecialchars($booking['currency_code']) ?>",
        order_id: "<?= htmlspecialchars($booking['razorpay_order_id']) ?>",
        name: "HEFarm",
        description: "Booking #<?= $bookingId ?>",
        prefill: {
            name: "<?= htmlspecialchars($booking['customer_name']) ?>",
            email: "<?= htmlspecialchars($booking['customer_email']) ?>",
            contact: "<?= htmlspecialchars($booking['customer_phone']) ?>"
        },
        handler: function (response) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = '/hef/payment-callback.php';
            var fields = {
                razorpay_payment_id: response.razorpay_payment_id,
                razorpay_order_id: response.razorpay_order_id,
                razorpay_signature: response.razorpay_signature,
                booking_id: "<?= $bookingId ?>"
            };
            for (var key in fields) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = fields[key];
                form.appendChild(input);
            }
            document.body.appendChild(form);
            form.submit();
        },
        modal: {
            ondismiss: function () {
                window.location.href = '/hef/cancel-booking.php?booking_id=<?= $bookingId ?>';
            }
        }
    };
    var rzp = new Razorpay(options);
    rzp.open();
</script>

</body>
</html>
