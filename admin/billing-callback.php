<?php
require_once __DIR__ . '/bootstrap.php';

$subscriptionId = (int) ($_POST['subscription_id'] ?? 0);
$razorpayPaymentId = $_POST['razorpay_payment_id'] ?? '';
$razorpayOrderId = $_POST['razorpay_order_id'] ?? '';
$razorpaySignature = $_POST['razorpay_signature'] ?? '';

$stmt = $pdo->prepare('SELECT * FROM company_subscriptions WHERE id = ? AND company_id = ?');
$stmt->execute([$subscriptionId, $currentUser['company_id']]);
$sub = $stmt->fetch();

if (! $sub || $sub['razorpay_subscription_id'] !== $razorpayOrderId) {
    http_response_code(400);
    die('Invalid subscription reference.');
}

$platformCredentials = hef_get_platform_razorpay_credentials($pdo);
if (! $platformCredentials) {
    http_response_code(400);
    die('Billing is not configured.');
}

$expectedSignature = hash_hmac('sha256', $razorpayOrderId . '|' . $razorpayPaymentId, $platformCredentials['razorpay_key_secret']);

if (! hash_equals($expectedSignature, $razorpaySignature)) {
    error_log("Platform billing signature mismatch for subscription {$subscriptionId}");
    header('Location: /hef/admin/billing.php?error=verification_failed');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM subscription_plans WHERE id = ?');
$stmt->execute([$sub['subscription_plan_id']]);
$plan = $stmt->fetch();

$interval = $plan['billing_cycle'] === 'monthly' ? '+1 MONTH' : '+1 YEAR';
$endsAt = date('Y-m-d H:i:s', strtotime($interval));

$pdo->beginTransaction();
try {
    // Any previous active subscription for this company is now superseded
    $pdo->prepare("UPDATE company_subscriptions SET status = 'cancelled', updated_at = NOW() WHERE company_id = ? AND status = 'active' AND id != ?")
        ->execute([$currentUser['company_id'], $subscriptionId]);

    $pdo->prepare("UPDATE company_subscriptions SET status = 'active', ends_at = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$endsAt, $subscriptionId]);

    $pdo->prepare("UPDATE companies SET status = 'active', updated_at = NOW() WHERE id = ?")
        ->execute([$currentUser['company_id']]);

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    error_log('billing activation failed: ' . $e->getMessage());
    header('Location: /hef/admin/billing.php?error=processing_failed');
    exit;
}

header('Location: /hef/admin/billing.php?success=1');
exit;
