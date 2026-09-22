<?php
/**
 * Keeps storefront inventory honest against real animal counts, and
 * flags bookings that can no longer be fulfilled when mortality reduces
 * a batch below what's already been sold.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/global.php';
require_once __DIR__ . '/mail-templates.php';
require_once __DIR__ . '/alerts.php'; // for hef_notify_company

/**
 * Call this after ANY change to a batch's live count (mortality
 * recorded/edited/deleted, manual count correction). For every
 * batch-linked storefront listing:
 *   1. Caps the listing's sellable stock at the batch's actual live count
 *      (can never offer more than physically exists).
 *   2. If already-PAID bookings for that listing now exceed what's
 *      actually alive, flags the most recently placed booking(s) as
 *      'unavailable' and opens a refund_requests entry for each —
 *      earlier customers keep priority over later ones.
 */
function hef_reconcile_batch_inventory(PDO $pdo, int $batchId): void
{
    $stmt = $pdo->prepare('SELECT * FROM batches WHERE id = ?');
    $stmt->execute([$batchId]);
    $batch = $stmt->fetch();
    if (! $batch) {
        return;
    }
    $aliveCount = $batch['current_count_male'] + $batch['current_count_female'] + $batch['current_count_unknown'];

    $stmt = $pdo->prepare("SELECT * FROM storefront_listings WHERE batch_id = ? AND type = 'animal'");
    $stmt->execute([$batchId]);
    $listings = $stmt->fetchAll();

    foreach ($listings as $listing) {
        // Cap sellable stock at what's actually alive — never oversell going forward.
        if ($listing['quantity_available'] > $aliveCount) {
            $pdo->prepare('UPDATE storefront_listings SET quantity_available = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$aliveCount, $listing['id']]);
        }

        // How much has already been PAID/CONFIRMED for this listing?
        $stmt = $pdo->prepare(
            "SELECT bi.id AS item_id, bi.booking_id, bi.quantity, b.status, b.created_at,
                    b.customer_name, b.customer_email, b.company_id
             FROM booking_items bi
             JOIN bookings b ON b.id = bi.booking_id
             WHERE bi.storefront_listing_id = ? AND b.status IN ('paid', 'confirmed')
             ORDER BY b.created_at DESC"
        );
        $stmt->execute([$listing['id']]);
        $committedBookings = $stmt->fetchAll();

        $totalCommitted = array_sum(array_column($committedBookings, 'quantity'));
        $shortfall = $totalCommitted - $aliveCount;

        if ($shortfall <= 0) {
            continue; // enough animals for everyone who's already paid
        }

        // Flag the MOST RECENT bookings first (first-come customers keep
        // priority) until the shortfall is covered.
        $remainingShortfall = $shortfall;
        foreach ($committedBookings as $booking) {
            if ($remainingShortfall <= 0) {
                break;
            }
            hef_flag_booking_unavailable($pdo, (int) $booking['booking_id'], $listing, $batch);
            $remainingShortfall -= $booking['quantity'];
        }
    }
}

function hef_flag_booking_unavailable(PDO $pdo, int $bookingId, array $listing, array $batch): void
{
    $stmt = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();
    if (! $booking || $booking['status'] === 'unavailable') {
        return; // already flagged, or gone
    }

    $stmt = $pdo->prepare('SELECT * FROM refund_policies WHERE company_id = ?');
    $stmt->execute([$booking['company_id']]);
    $policy = $stmt->fetch();
    $refundPercent = $policy['refund_percentage'] ?? 100;
    $slaHours = $policy['response_sla_hours'] ?? 48;

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE bookings SET status = 'unavailable', updated_at = NOW() WHERE id = ?")
            ->execute([$bookingId]);

        $pdo->prepare(
            "INSERT INTO refund_requests (booking_id, reason, requested_at, status, created_at, updated_at)
             VALUES (?, ?, NOW(), 'pending', NOW(), NOW())"
        )->execute([
            $bookingId,
            "Batch {$batch['batch_code']} mortality reduced live stock below what was already booked.",
        ]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("hef_flag_booking_unavailable failed for booking {$bookingId}: " . $e->getMessage());
        return;
    }

    // Notify the customer
    $customerInner = '<p style="margin:0 0 8px; color:#444; font-size:14px;">We\'re sorry — due to unexpected losses in our stock, we\'re unable to fulfill part of your order.</p>'
        . hef_email_alert_badge("Booking #{$bookingId} — Unavailable", '#c0392b')
        . hef_email_details_table([
            'Item' => $listing['title'],
            'Refund' => "{$refundPercent}% of your payment",
            'We will respond within' => "{$slaHours} hours",
        ])
        . '<p style="color:#666; font-size:13px;">Our team will be in touch shortly to process your refund or offer a replacement.</p>';

    hef_send_mail(
        $pdo, $booking['customer_email'], 'Update on your booking — item unavailable',
        hef_email_wrap('⚠️ Booking Update', $customerInner, 'Part of your order is no longer available'),
        $booking['company_id']
    );

    // Notify the owner
    $ownerInner = '<p style="margin:0 0 8px; color:#444; font-size:14px;">A batch mortality event has made a paid booking unfulfillable.</p>'
        . hef_email_details_table([
            'Booking' => "#{$bookingId}",
            'Customer' => $booking['customer_name'],
            'Batch' => $batch['batch_code'],
            'Listing' => $listing['title'],
        ])
        . hef_email_button('https://cthkennels.com/hef/admin/bookings.php', 'Review & Process Refund');

    hef_notify_company(
        $pdo, $booking['company_id'], "Action needed: booking #{$bookingId} can't be fulfilled",
        hef_email_wrap('⚠️ Refund Needed', $ownerInner, "Booking #{$bookingId} needs attention")
    );
}
