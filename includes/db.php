<?php
/**
 * Shared PDO database connection.
 * This file is executed by PHP (not served as text) when requested
 * directly, so credentials aren't exposed even though it sits under
 * the web root — same pattern used by WordPress's wp-config.php etc.
 * Still, never link to it publicly or reference its path in HTML/JS.
 */

$db_host = 'db5021374724.hosting-data.io';
$db_name = 'dbs16102030';
$db_user = 'dbu4199381';
$db_pass = 'afPd93nyjTAVgMr';
$db_charset = 'utf8mb4';

$dsn = "mysql:host={$db_host};dbname={$db_name};charset={$db_charset}";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $db_user, $db_pass, $options);
} catch (PDOException $e) {
    error_log('HEFarm DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    die('A database error occurred. Please try again shortly.');
}

/**
 * HEFarm's own Razorpay credentials, for platform billing (charging
 * companies to use HEFarm) — reuses the super-admin user's own company's
 * payment_settings row rather than a separate, duplicate config. Returns
 * null if no super-admin has Razorpay set up yet.
 */
function hef_get_platform_razorpay_credentials(PDO $pdo): ?array
{
    $stmt = $pdo->prepare(
        "SELECT ps.razorpay_key_id, ps.razorpay_key_secret, ps.razorpay_webhook_secret
         FROM payment_settings ps
         JOIN users u ON u.company_id = ps.company_id
         WHERE u.is_super_admin = 1
         LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->fetch();

    if (! $row || ! $row['razorpay_key_id'] || ! $row['razorpay_key_secret']) {
        return null;
    }

    return $row;
}

/**
 * Releases stock from bookings that reserved it but never completed
 * payment within the reservation window. Call this on public
 * store/listing pages before showing availability.
 */
function hef_release_expired_reservations(PDO $pdo): void
{
    $stmt = $pdo->query(
        "SELECT b.id, bi.storefront_listing_id, bi.quantity
         FROM bookings b
         JOIN booking_items bi ON bi.booking_id = b.id
         WHERE b.status = 'pending_payment' AND b.reserved_until < NOW()"
    );
    $expired = $stmt->fetchAll();

    foreach ($expired as $row) {
        $pdo->prepare("UPDATE storefront_listings SET quantity_available = quantity_available + ? WHERE id = ?")
            ->execute([$row['quantity'], $row['storefront_listing_id']]);
        $pdo->prepare("UPDATE bookings SET status = 'cancelled', updated_at = NOW() WHERE id = ?")
            ->execute([$row['id']]);
    }
}
