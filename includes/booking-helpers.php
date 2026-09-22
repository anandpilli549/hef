<?php
/**
 * Shared logic for finalizing a paid booking — called from BOTH
 * payment-callback.php (browser return) and webhook.php (server-to-
 * server from Razorpay). Idempotent: safe to call twice for the same
 * payment (e.g. webhook fires after the browser callback already
 * processed it) — it just no-ops on the second call rather than
 * double-crediting income or sending duplicate emails.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/global.php';
require_once __DIR__ . '/mail-templates.php';
require_once __DIR__ . '/alerts.php'; // for hef_notify_company

function hef_finalize_paid_booking(PDO $pdo, int $bookingId, string $razorpayPaymentId): bool
{
    $stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();

    if (! $booking) {
        return false;
    }

    // Already processed (by the other path) — nothing more to do.
    if ($booking['status'] !== 'pending_payment') {
        return true;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE bookings SET status = 'paid', razorpay_payment_id = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$razorpayPaymentId, $bookingId]);

        $stmt = $pdo->prepare(
            "SELECT bi.*, sl.type, sl.title FROM booking_items bi
             JOIN storefront_listings sl ON sl.id = bi.storefront_listing_id
             WHERE bi.booking_id = ?"
        );
        $stmt->execute([$bookingId]);
        $items = $stmt->fetchAll();

        foreach ($items as $item) {
            $source = match ($item['type']) {
                'animal' => 'animal_sale',
                'meat' => 'meat_sale',
                'egg' => 'egg_sale',
                default => 'booking',
            };
            $pdo->prepare(
                "INSERT INTO income (company_id, source, amount, currency_code, date, related_booking_id, notes, created_at, updated_at)
                 VALUES (?, ?, ?, ?, CURDATE(), ?, ?, NOW(), NOW())"
            )->execute([
                $booking['company_id'], $source, $item['line_total'], $booking['currency_code'],
                $bookingId, "Storefront booking #{$bookingId}: {$item['quantity']} x {$item['title']}",
            ]);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("hef_finalize_paid_booking failed for booking {$bookingId}: " . $e->getMessage());
        return false;
    }

    // Notifications after commit, same reasoning as before.
    $stmt = $pdo->prepare('SELECT name FROM companies WHERE id = ?');
    $stmt->execute([$booking['company_id']]);
    $companyName = $stmt->fetchColumn();

    $itemDetails = [];
    foreach ($items as $item) {
        $itemDetails["{$item['quantity']} × {$item['title']}"] = '₹' . number_format($item['line_total'], 0);
    }
    $itemDetails['Delivery fee'] = '₹' . number_format($booking['delivery_fee'], 0);
    $itemDetails['Total'] = '₹' . number_format($booking['total_amount'], 0);
    $itemDetails['Fulfillment'] = ucfirst($booking['fulfillment_type']);
    if ($booking['fulfillment_type'] === 'delivery' && $booking['delivery_address']) {
        $itemDetails['Delivery address'] = $booking['delivery_address'];
    }

    $customerInner = '<p style="margin:0 0 8px; color:#444; font-size:14px;">Thank you for your booking with ' . htmlspecialchars($companyName) . '!</p>'
        . hef_email_alert_badge("Booking #{$bookingId} — Confirmed", '#2f7d4f')
        . hef_email_details_table($itemDetails)
        . '<p style="color:#666; font-size:13px;">You\'ll be contacted when your order is ready. Keep this email as your booking reference.</p>';

    hef_send_mail($pdo, $booking['customer_email'], "Booking Confirmed — {$companyName}",
        hef_email_wrap('✅ Booking Confirmed', $customerInner, "Your booking #{$bookingId} is confirmed"), $booking['company_id']);

    $customerSmsText = "Your booking #{$bookingId} with {$companyName} is confirmed. Total: ₹" . number_format($booking['total_amount'], 0)
        . '. ' . ucfirst($booking['fulfillment_type']) . '.';
    if (! empty($booking['customer_phone'])) {
        hef_send_sms($pdo, $booking['customer_phone'], $customerSmsText, $booking['company_id']);

        $settings = hef_get_notification_settings($pdo, $booking['company_id']);
        if (! empty($settings['whatsapp_booking_template'])) {
            hef_send_whatsapp(
                $pdo, $booking['customer_phone'], $settings['whatsapp_booking_template'],
                [(string) $bookingId, $companyName, '₹' . number_format($booking['total_amount'], 0)],
                $booking['company_id']
            );
        }
    }

    $ownerDetails = ['Customer' => $booking['customer_name'], 'Phone' => $booking['customer_phone']] + $itemDetails;
    $ownerInner = '<p style="margin:0 0 8px; color:#444; font-size:14px;">You have a new paid booking.</p>'
        . hef_email_details_table($ownerDetails)
        . hef_email_button('https://cthkennels.com/hef/admin/bookings.php', 'View in Bookings');

    $ownerSmsText = "New HEFarm booking #{$bookingId} from {$booking['customer_name']} ({$booking['customer_phone']}). Total: ₹" . number_format($booking['total_amount'], 0) . '.';
    hef_notify_company(
        $pdo, $booking['company_id'], "New booking #{$bookingId}",
        hef_email_wrap('🛒 New Booking', $ownerInner, "New booking from {$booking['customer_name']}"),
        $ownerSmsText, [(string) $bookingId, $booking['customer_name']], 'booking'
    );

    return true;
}
