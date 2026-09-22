<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/plan_limits.php';

hef_release_expired_reservations($pdo);

$slug = $_GET['slug'] ?? $_POST['slug'] ?? '';

$stmt = $pdo->prepare(
    "SELECT sl.*, c.name AS company_name, c.slug AS company_slug
     FROM storefront_listings sl JOIN companies c ON c.id = sl.company_id
     WHERE sl.slug = ? AND sl.status = 'published' LIMIT 1"
);
$stmt->execute([$slug]);
$listing = $stmt->fetch();

// Not found, or the company's plan doesn't include the online storefront.
if (! $listing || ! hef_company_storefront_allowed($pdo, (int) $listing['company_id'])) {
    http_response_code(404);
    die('Listing not found or no longer available.');
}

if (! defined('DELIVERY_FLAT_FEE')) {
    define('DELIVERY_FLAT_FEE', 50.00);
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $qty = (int) ($_POST['quantity'] ?? 0);
    $customerName = trim($_POST['customer_name'] ?? '');
    $customerEmail = trim($_POST['customer_email'] ?? '');
    $customerPhone = trim($_POST['customer_phone'] ?? '');
    $fulfillment = $_POST['fulfillment_type'] ?? 'pickup';
    $deliveryAddress = trim($_POST['delivery_address'] ?? '');
    $deliveryPincode = trim($_POST['delivery_pincode'] ?? '');

    if ($qty < 1 || $qty > $listing['quantity_available']) {
        $error = 'Please choose a valid quantity.';
    } elseif (! $customerName || ! filter_var($customerEmail, FILTER_VALIDATE_EMAIL) || ! $customerPhone) {
        $error = 'Please fill in your name, a valid email, and phone number.';
    } elseif ($fulfillment === 'delivery' && (! $deliveryAddress || ! $deliveryPincode)) {
        $error = 'Please provide a delivery address and pincode.';
    } else {
        $subtotal = $listing['price'] * $qty;
        $deliveryFee = $fulfillment === 'delivery' ? DELIVERY_FLAT_FEE : 0;
        $total = $subtotal + $deliveryFee;

        $pdo->beginTransaction();
        try {
            // Re-check availability inside the transaction to avoid overselling under concurrent bookings
            $stmt = $pdo->prepare('SELECT quantity_available FROM storefront_listings WHERE id = ? FOR UPDATE');
            $stmt->execute([$listing['id']]);
            $current = $stmt->fetch();

            if (! $current || $qty > $current['quantity_available']) {
                throw new RuntimeException('Sorry, that quantity is no longer available.');
            }

            $pdo->prepare('UPDATE storefront_listings SET quantity_available = quantity_available - ? WHERE id = ?')
                ->execute([$qty, $listing['id']]);

            $stmt = $pdo->prepare(
                "INSERT INTO bookings
                    (company_id, customer_name, customer_email, customer_phone, fulfillment_type,
                     delivery_address, delivery_pincode, delivery_fee, subtotal, total_amount, currency_code,
                     status, reserved_until, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'INR', 'pending_payment', NOW() + INTERVAL 15 MINUTE, NOW(), NOW())"
            );
            $stmt->execute([
                $listing['company_id'], $customerName, $customerEmail, $customerPhone, $fulfillment,
                $fulfillment === 'delivery' ? $deliveryAddress : null,
                $fulfillment === 'delivery' ? $deliveryPincode : null,
                $deliveryFee, $subtotal, $total,
            ]);
            $bookingId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO booking_items (booking_id, storefront_listing_id, quantity, unit_price, line_total, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
            )->execute([$bookingId, $listing['id'], $qty, $listing['price'], $subtotal]);

            $pdo->commit();
            header('Location: /hef/pay.php?booking_id=' . $bookingId);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('booking creation failed: ' . $e->getMessage());
            $error = $e->getMessage() ?: 'Could not create your booking. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Book — <?= htmlspecialchars($listing['title']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f5f7f6; }
        .card { border: none; border-radius: 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        .btn-hef { background: #2f7d4f; border-color: #2f7d4f; color: #fff; }
        .btn-hef:hover { background: #235f3c; border-color: #235f3c; color: #fff; }
    </style>
</head>
<body>
<nav class="navbar navbar-dark px-3" style="background:#1d4a30;">
    <span class="navbar-brand fw-bold"><?= htmlspecialchars($listing['company_name']) ?></span>
</nav>

<div class="container py-4" style="max-width: 500px;">
    <div class="card p-4">
        <h4>Book: <?= htmlspecialchars($listing['title']) ?></h4>
        <div class="text-muted mb-3">₹<?= number_format($listing['price'], 0) ?> each · <?= (int) $listing['quantity_available'] ?> available</div>

        <?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <form method="POST">
            <input type="hidden" name="slug" value="<?= htmlspecialchars($slug) ?>">

            <div class="mb-2">
                <label class="form-label small">Quantity</label>
                <input type="number" name="quantity" class="form-control" min="1" max="<?= (int) $listing['quantity_available'] ?>" value="1" required>
            </div>
            <div class="mb-2">
                <label class="form-label small">Your name</label>
                <input type="text" name="customer_name" class="form-control" required>
            </div>
            <div class="mb-2">
                <label class="form-label small">Email</label>
                <input type="email" name="customer_email" class="form-control" required>
            </div>
            <div class="mb-2">
                <label class="form-label small">Phone</label>
                <input type="tel" name="customer_phone" class="form-control" required>
            </div>

            <div class="mb-2">
                <label class="form-label small d-block">Fulfillment</label>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="fulfillment_type" value="pickup" id="pickup" checked onclick="document.getElementById('deliveryFields').style.display='none'">
                    <label class="form-check-label" for="pickup">Pickup</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="fulfillment_type" value="delivery" id="delivery" onclick="document.getElementById('deliveryFields').style.display='block'">
                    <label class="form-check-label" for="delivery">Delivery (+₹<?= number_format(DELIVERY_FLAT_FEE, 0) ?>)</label>
                </div>
            </div>

            <div id="deliveryFields" style="display:none;">
                <div class="mb-2">
                    <label class="form-label small">Delivery address</label>
                    <textarea name="delivery_address" class="form-control" rows="2"></textarea>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Pincode</label>
                    <input type="text" name="delivery_pincode" class="form-control">
                </div>
            </div>

            <button type="submit" class="btn btn-hef btn-lg w-100 mt-2">Continue to Payment</button>
        </form>
    </div>
</div>
</body>
</html>
