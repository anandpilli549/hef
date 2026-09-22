<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/permissions.php';
hef_require_role(['owner'], $currentUser['role']);

$companyId = $currentUser['company_id'];

if ($companyId == HEF_OWNER_COMPANY_ID) {
    header('Location: /hef/admin/billing.php');
    exit;
}

$planId = (int) ($_GET['plan_id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM subscription_plans WHERE id = ?');
$stmt->execute([$planId]);
$plan = $stmt->fetch();

if (! $plan) {
    header('Location: /hef/admin/billing.php');
    exit;
}

$platformCredentials = hef_get_platform_razorpay_credentials($pdo);
if (! $platformCredentials) {
    die('Billing is not yet configured on this HEFarm instance.');
}

// Create a pending company_subscriptions row we'll activate on payment success
$stmt = $pdo->prepare(
    "INSERT INTO company_subscriptions (company_id, subscription_plan_id, status, starts_at, created_at, updated_at)
     VALUES (?, ?, 'trial', NOW(), NOW(), NOW())"
);
$stmt->execute([$companyId, $planId]);
$subscriptionId = (int) $pdo->lastInsertId();

$amountPaise = (int) round($plan['price'] * 100);

$ch = curl_init('https://api.razorpay.com/v1/orders');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_USERPWD => $platformCredentials['razorpay_key_id'] . ':' . $platformCredentials['razorpay_key_secret'],
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode([
        'amount' => $amountPaise,
        'currency' => $plan['currency_code'],
        'receipt' => 'subscription_' . $subscriptionId,
    ]),
    CURLOPT_TIMEOUT => 20,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$data = json_decode($response, true);

if ($httpCode !== 200 || empty($data['id'])) {
    error_log('Platform Razorpay order creation failed: ' . $response);
    die('Could not initiate payment right now. Please try again shortly.');
}

$razorpayOrderId = $data['id'];
$pdo->prepare('UPDATE company_subscriptions SET razorpay_subscription_id = ? WHERE id = ?')
    ->execute([$razorpayOrderId, $subscriptionId]);

$stmt = $pdo->prepare('SELECT name, email FROM users WHERE id = ?');
$stmt->execute([$currentUser['user_id']]);
$ownerInfo = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Subscribe — HEFarm</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="d-flex align-items-center justify-content-center" style="min-height:100vh; background:#f5f7f6;">

<div class="card p-4 text-center" style="max-width:400px; border:none; border-radius:14px; box-shadow:0 1px 3px rgba(0,0,0,0.06);">
    <h5><?= htmlspecialchars($plan['name']) ?> — ₹<?= number_format($plan['price'], 0) ?></h5>
    <p class="text-muted small">Redirecting to secure payment…</p>
    <div class="spinner-border text-success mx-auto" role="status"></div>
</div>

<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
    var options = {
        key: "<?= htmlspecialchars($platformCredentials['razorpay_key_id']) ?>",
        amount: "<?= $amountPaise ?>",
        currency: "<?= htmlspecialchars($plan['currency_code']) ?>",
        order_id: "<?= htmlspecialchars($razorpayOrderId) ?>",
        name: "HEFarm",
        description: "<?= htmlspecialchars($plan['name']) ?> subscription",
        prefill: {
            name: "<?= htmlspecialchars($ownerInfo['name']) ?>",
            email: "<?= htmlspecialchars($ownerInfo['email']) ?>"
        },
        handler: function (response) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = '/hef/admin/billing-callback.php';
            var fields = {
                razorpay_payment_id: response.razorpay_payment_id,
                razorpay_order_id: response.razorpay_order_id,
                razorpay_signature: response.razorpay_signature,
                subscription_id: "<?= $subscriptionId ?>"
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
                window.location.href = '/hef/admin/billing.php';
            }
        }
    };
    var rzp = new Razorpay(options);
    rzp.open();
</script>

</body>
</html>
