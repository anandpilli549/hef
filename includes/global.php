<?php
/**
 * Global notification helpers — mail, SMS, WhatsApp.
 * Include this after db.php and config.php:
 *   require_once __DIR__ . '/config.php';
 *   require_once __DIR__ . '/global.php';
 *
 * Every function here:
 *  - takes $pdo so it can log the attempt to notification_logs
 *  - fails gracefully (returns false, never throws / crashes the page)
 *  - is safe to call even with blank credentials in config.php —
 *    it just logs a failed attempt instead of erroring
 */

// =================================================================
// CONTACT BUTTONS (UI helper)
// =================================================================

/** Is the visitor on a phone or tablet? (Decides between SIM-card SMS / WhatsApp app and the computer options.) */
function hef_is_mobile_device(): bool
{
    return (bool) preg_match('/Android|iPhone|iPad|iPod|Mobile|Opera Mini|IEMobile|BlackBerry/i', $_SERVER['HTTP_USER_AGENT'] ?? '');
}

function hef_is_ios_device(): bool
{
    return (bool) preg_match('/iPhone|iPad|iPod/i', $_SERVER['HTTP_USER_AGENT'] ?? '');
}

/**
 * A stored phone number as full international digits (no "+"), or null.
 * Numbers are usually stored as plain 10-digit Indian mobiles, so a bare
 * 10-digit number gets 91 in front, and an 11-digit one starting with a
 * trunk 0 has it swapped for 91. Anything longer is assumed to carry its
 * country code already.
 */
function hef_intl_phone(?string $phone): ?string
{
    $digits = preg_replace('/\D+/', '', trim((string) $phone));
    if ($digits === '' || $digits === null) {
        return null;
    }
    if (strlen($digits) === 10) {
        $digits = '91' . $digits;
    } elseif (strlen($digits) === 11 && $digits[0] === '0') {
        $digits = '91' . substr($digits, 1);
    }

    return (strlen($digits) >= 6 && strlen($digits) <= 15) ? $digits : null;
}

/** A WhatsApp link that suits the device: the app on a phone, WhatsApp Web on a computer. */
function hef_whatsapp_link(string $intl, string $message = ''): string
{
    if (hef_is_mobile_device()) {
        return 'https://wa.me/' . $intl . ($message !== '' ? '?text=' . rawurlencode($message) : '');
    }

    return 'https://web.whatsapp.com/send?phone=' . $intl . ($message !== '' ? '&text=' . rawurlencode($message) : '');
}

/** An sms: link that opens the phone's own messaging app (sent from its SIM). iPhones want "&body=". */
function hef_sms_link(string $intl, string $message = ''): string
{
    return 'sms:+' . $intl . ($message !== '' ? (hef_is_ios_device() ? '&' : '?') . 'body=' . rawurlencode($message) : '');
}

/**
 * What this company can send through provider APIs, for the contact buttons.
 * Set once per request by admin/bootstrap.php, then read lazily:
 *   wa_api   the company turned WhatsApp API sending on and saved its credentials
 *   sms_api  the company saved an SMS provider key
 */
function hef_messaging_context(?PDO $pdo = null, ?int $companyId = null): array
{
    static $ctx = null;
    static $pdoRef = null;
    static $cid = null;

    if ($pdo !== null) {
        $pdoRef = $pdo;
        $cid = $companyId;
        $ctx = null;
    }
    if ($ctx === null) {
        $ctx = ['wa_api' => false, 'sms_api' => false];
        if ($pdoRef && $cid) {
            try {
                $s = hef_get_notification_settings($pdoRef, $cid) ?: [];
                $ctx['wa_api'] = ! empty($s['whatsapp_enabled']) && ! empty($s['whatsapp_phone_number_id']) && ! empty($s['whatsapp_access_token']);
                $ctx['sms_api'] = ! empty($s['msg91_auth_key']);
            } catch (Throwable $e) {
                // settings table not ready: treat everything as off
            }
        }
    }

    return $ctx;
}

/**
 * Renders Call, SMS and WhatsApp icon buttons for a phone number (customer /
 * supplier / requirements lists). Returns '' if there is no usable number.
 *
 *   Call       always a tel: link.
 *   SMS        on a phone: opens the phone's SMS app so it is sent from the
 *              SIM card. On a computer: sends through the company's SMS API
 *              (Settings > SMS), or explains how to set that up.
 *   WhatsApp   API sending on (Settings > WhatsApp): sends through the
 *              company's WhatsApp Business API. Off: a link that opens the
 *              WhatsApp app on a phone, or WhatsApp Web on a computer.
 *
 * $message pre-fills the SMS / WhatsApp text.
 */
function hef_contact_buttons(?string $phone, string $message = ''): string
{
    $intl = hef_intl_phone($phone);
    if ($intl === null) {
        return '';
    }
    $ctx = hef_messaging_context();
    $stop = ' onclick="event.stopPropagation();"';
    $msgAttr = htmlspecialchars($message, ENT_QUOTES);

    $html = '<a href="' . htmlspecialchars('tel:+' . $intl) . '" class="btn btn-sm btn-outline-secondary" title="Call"' . $stop . '><i class="bi bi-telephone-fill"></i></a> ';

    if (hef_is_mobile_device()) {
        $html .= '<a href="' . htmlspecialchars(hef_sms_link($intl, $message)) . '" class="btn btn-sm btn-outline-primary" title="SMS from your phone (uses your SIM)"' . $stop . '><i class="bi bi-chat-text-fill"></i></a> ';
    } elseif ($ctx['sms_api']) {
        $html .= '<button type="button" class="btn btn-sm btn-outline-primary" title="Send SMS" data-channel="sms" data-phone="' . $intl . '" data-message="' . $msgAttr . '"'
            . ' onclick="event.stopPropagation(); hefOpenComposer(this);"><i class="bi bi-chat-text-fill"></i></button> ';
    } else {
        $html .= '<button type="button" class="btn btn-sm btn-outline-primary" title="SMS: needs setting up"'
            . ' onclick="event.stopPropagation(); alert(\'To send SMS from a computer, add your SMS provider details in Settings, SMS tab. On a phone, this button opens your own SMS app.\');"><i class="bi bi-chat-text-fill"></i></button> ';
    }

    if ($ctx['wa_api']) {
        $html .= '<button type="button" class="btn btn-sm btn-outline-success" title="Send WhatsApp (business account)" data-channel="whatsapp" data-phone="' . $intl . '" data-message="' . $msgAttr . '"'
            . ' onclick="event.stopPropagation(); hefOpenComposer(this);"><i class="bi bi-whatsapp"></i></button>';
    } else {
        $html .= '<a href="' . htmlspecialchars(hef_whatsapp_link($intl, $message)) . '" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success" title="WhatsApp"' . $stop . '><i class="bi bi-whatsapp"></i></a>';
    }

    return $html;
}

/** Only https addresses that resolve to a public internet host (so a company can't point an API setting at our own network). */
function hef_is_safe_public_https_url(string $url): bool
{
    $parts = parse_url($url);
    if (! $parts || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
        return false;
    }
    $host = $parts['host'];
    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
    if (! filter_var($ip, FILTER_VALIDATE_IP)) {
        return false; // did not resolve
    }

    return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

// =================================================================
// MAIL
// =================================================================

/**
 * Send an email. Uses PHP's built-in mail() or raw SMTP depending on
 * MAIL_DRIVER in config.php. No external library required either way.
 */
function hef_send_mail(PDO $pdo, string $to, string $subject, string $bodyHtml, ?int $companyId = null, ?int $userId = null): bool
{
    $success = false;
    $errorMessage = null;

    try {
        if (MAIL_DRIVER === 'smtp') {
            $success = hef_send_mail_smtp($to, $subject, $bodyHtml);
        } else {
            $headers = "MIME-Version: 1.0\r\n";
            $headers .= "Content-type: text/html; charset=UTF-8\r\n";
            $headers .= 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM_ADDRESS . ">\r\n";
            $success = mail($to, $subject, $bodyHtml, $headers);
        }
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
        error_log('hef_send_mail failed: ' . $errorMessage);
    }

    hef_log_notification($pdo, $companyId, $userId, 'mail', $to, $subject, $bodyHtml, $success, $errorMessage);

    return $success;
}

/**
 * Minimal raw SMTP client — no external library. Supports STARTTLS +
 * AUTH LOGIN, which covers Brevo, Resend's SMTP relay, Amazon SES SMTP,
 * and most standard transactional providers.
 */
function hef_send_mail_smtp(string $to, string $subject, string $bodyHtml): bool
{
    if (! SMTP_HOST || ! SMTP_USERNAME || ! SMTP_PASSWORD) {
        error_log('hef_send_mail_smtp: SMTP not configured (check includes/config.php)');
        return false;
    }

    $socket = fsockopen(SMTP_HOST, (int) SMTP_PORT, $errno, $errstr, 15);
    if (! $socket) {
        error_log("SMTP connect failed: {$errstr} ({$errno})");
        return false;
    }

    $read = fn () => fgets($socket, 512);
    $send = function (string $cmd) use ($socket) {
        fwrite($socket, $cmd . "\r\n");
    };

    $read(); // greeting
    $send('EHLO hef-app');
    $read();

    if (SMTP_ENCRYPTION === 'tls') {
        $send('STARTTLS');
        $read();
        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $send('EHLO hef-app');
        $read();
    }

    $send('AUTH LOGIN');
    $read();
    $send(base64_encode(SMTP_USERNAME));
    $read();
    $send(base64_encode(SMTP_PASSWORD));
    $authResponse = $read();

    if (! str_starts_with(trim($authResponse), '235')) {
        fclose($socket);
        error_log('SMTP auth failed: ' . $authResponse);
        return false;
    }

    $send('MAIL FROM:<' . MAIL_FROM_ADDRESS . '>');
    $read();
    $send('RCPT TO:<' . $to . '>');
    $read();
    $send('DATA');
    $read();

    $message = "Subject: {$subject}\r\n";
    $message .= 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM_ADDRESS . ">\r\n";
    $message .= "To: {$to}\r\n";
    $message .= "MIME-Version: 1.0\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $message .= $bodyHtml . "\r\n.";

    $send($message);
    $finalResponse = $read();

    $send('QUIT');
    fclose($socket);

    return str_starts_with(trim($finalResponse), '250');
}

// =================================================================
// SMS (MSG91)
// =================================================================

/**
 * Looks up a company's notification_settings row, if any. Used by both
 * SMS and WhatsApp senders to prefer per-company credentials over the
 * blank global defaults in config.php.
 */
function hef_get_notification_settings(PDO $pdo, ?int $companyId): ?array
{
    if (! $companyId) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM notification_settings WHERE company_id = ?');
    $stmt->execute([$companyId]);
    return $stmt->fetch() ?: null;
}

/**
 * Sends an SMS through the company's provider (MSG91 by default). The API
 * address can be changed in Settings > SMS; it defaults to MSG91's.
 *
 * @return array{ok: bool, error: ?string}
 */
function hef_send_sms_detailed(PDO $pdo, string $toPhone, string $message, ?int $companyId = null, ?int $userId = null): array
{
    $success = false;
    $errorMessage = null;

    $settings = hef_get_notification_settings($pdo, $companyId);
    $authKey = $settings['msg91_auth_key'] ?? (MSG91_AUTH_KEY ?: null);
    $senderId = $settings['msg91_sender_id'] ?? (MSG91_SENDER_ID ?: 'HEFAPP');
    $apiUrl = trim((string) ($settings['sms_api_url'] ?? '')) ?: 'https://api.msg91.com/api/v5/flow/';

    if (! $authKey) {
        $errorMessage = 'SMS is not set up for this company (Settings, SMS tab).';
        error_log('hef_send_sms: ' . $errorMessage);
    } elseif (! hef_is_safe_public_https_url($apiUrl)) {
        $errorMessage = 'The SMS API address in Settings is not a valid public https address.';
    } else {
        try {
            $ch = curl_init($apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'authkey: ' . $authKey,
                    'content-type: application/json',
                ],
                CURLOPT_POSTFIELDS => json_encode([
                    'sender' => $senderId,
                    'route' => MSG91_ROUTE,
                    'mobiles' => $toPhone,
                    'message' => $message,
                ]),
                CURLOPT_TIMEOUT => 15,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $success = $httpCode >= 200 && $httpCode < 300;
            if (! $success) {
                $errorMessage = "SMS provider HTTP {$httpCode}: " . substr((string) $response, 0, 300);
            }
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();
        }
    }

    hef_log_notification($pdo, $companyId, $userId, 'sms', $toPhone, null, $message, $success, $errorMessage);

    return ['ok' => $success, 'error' => $errorMessage];
}

function hef_send_sms(PDO $pdo, string $toPhone, string $message, ?int $companyId = null, ?int $userId = null): bool
{
    return hef_send_sms_detailed($pdo, $toPhone, $message, $companyId, $userId)['ok'];
}

// =================================================================
// WHATSAPP (Meta WhatsApp Business Cloud API)
// =================================================================

/**
 * Sends a template-based WhatsApp message (required for anything
 * outside a 24-hour customer-initiated conversation window). The
 * template must already be approved in your Meta Business account.
 *
 * @param array $params Ordered list of values to fill the template's placeholders
 */
function hef_send_whatsapp(PDO $pdo, string $toPhone, string $templateName, array $params = [], ?int $companyId = null, ?int $userId = null): bool
{
    $success = false;
    $errorMessage = null;

    $settings = hef_get_notification_settings($pdo, $companyId);
    $phoneNumberId = $settings['whatsapp_phone_number_id'] ?? (WHATSAPP_PHONE_NUMBER_ID ?: null);
    $accessToken = $settings['whatsapp_access_token'] ?? (WHATSAPP_ACCESS_TOKEN ?: null);

    if (! $phoneNumberId || ! $accessToken) {
        $errorMessage = 'WhatsApp API not configured for this company';
        error_log('hef_send_whatsapp: ' . $errorMessage);
    } else {
        try {
            $url = 'https://graph.facebook.com/' . WHATSAPP_API_VERSION . '/' . $phoneNumberId . '/messages';

            $components = [];
            if (! empty($params)) {
                $components[] = [
                    'type' => 'body',
                    'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => (string) $p], $params),
                ];
            }

            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $toPhone,
                'type' => 'template',
                'template' => [
                    'name' => $templateName,
                    'language' => ['code' => 'en'],
                    'components' => $components,
                ],
            ];

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_TIMEOUT => 15,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $success = $httpCode >= 200 && $httpCode < 300;
            if (! $success) {
                $errorMessage = "WhatsApp API HTTP {$httpCode}: {$response}";
            }
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();
        }
    }

    $messageSummary = "Template: {$templateName} | Params: " . implode(', ', $params);
    hef_log_notification($pdo, $companyId, $userId, 'whatsapp', $toPhone, null, $messageSummary, $success, $errorMessage);

    return $success;
}

/**
 * Sends a typed message from the contact buttons through the company's
 * WhatsApp Business API. If the company saved a "message template" name in
 * Settings > WhatsApp (an approved template whose body is just {{1}}), the
 * text goes in that template, which WhatsApp allows at any time. Otherwise it
 * is sent as plain text, which WhatsApp only allows within 24 hours of the
 * customer last messaging you.
 *
 * @return array{ok: bool, error: ?string}
 */
function hef_send_whatsapp_message(PDO $pdo, string $toIntlPhone, string $message, ?int $companyId = null, ?int $userId = null): array
{
    $success = false;
    $errorMessage = null;

    $settings = hef_get_notification_settings($pdo, $companyId) ?: [];
    $phoneNumberId = $settings['whatsapp_phone_number_id'] ?? (WHATSAPP_PHONE_NUMBER_ID ?: null);
    $accessToken = $settings['whatsapp_access_token'] ?? (WHATSAPP_ACCESS_TOKEN ?: null);
    $enabled = array_key_exists('whatsapp_enabled', $settings) ? (bool) $settings['whatsapp_enabled'] : true;
    $template = trim((string) ($settings['whatsapp_message_template'] ?? ''));

    if (! $enabled || ! $phoneNumberId || ! $accessToken) {
        $errorMessage = 'WhatsApp API sending is not turned on for this company (Settings, WhatsApp tab).';
    } else {
        try {
            $url = 'https://graph.facebook.com/' . WHATSAPP_API_VERSION . '/' . $phoneNumberId . '/messages';
            if ($template !== '') {
                // Template parameters can't hold new lines or runs of spaces.
                $flat = mb_substr(trim(preg_replace('/\s+/', ' ', $message)), 0, 1000);
                $payload = [
                    'messaging_product' => 'whatsapp', 'to' => $toIntlPhone, 'type' => 'template',
                    'template' => [
                        'name' => $template, 'language' => ['code' => 'en'],
                        'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $flat]]]],
                    ],
                ];
            } else {
                $payload = [
                    'messaging_product' => 'whatsapp', 'to' => $toIntlPhone, 'type' => 'text',
                    'text' => ['preview_url' => true, 'body' => mb_substr($message, 0, 4000)],
                ];
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_TIMEOUT => 15,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $success = $httpCode >= 200 && $httpCode < 300;
            if (! $success) {
                $decoded = json_decode((string) $response, true);
                $code = (int) ($decoded['error']['code'] ?? 0);
                $detail = $decoded['error']['message'] ?? substr((string) $response, 0, 300);
                $errorMessage = $code === 131047 || $code === 131026
                    ? 'WhatsApp only allows plain messages within 24 hours of the customer messaging you. Save an approved message template in Settings, WhatsApp tab, or use the link below. (' . $detail . ')'
                    : 'WhatsApp said: ' . $detail;
            }
        } catch (Throwable $e) {
            $errorMessage = $e->getMessage();
        }
    }

    hef_log_notification($pdo, $companyId, $userId, 'whatsapp', $toIntlPhone, null, mb_substr($message, 0, 500), $success, $errorMessage);

    return ['ok' => $success, 'error' => $errorMessage];
}

// =================================================================
// Shared logging
// =================================================================

function hef_log_notification(
    PDO $pdo,
    ?int $companyId,
    ?int $userId,
    string $channel,
    string $recipient,
    ?string $subject,
    string $message,
    bool $success,
    ?string $errorMessage
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO notification_logs (company_id, user_id, channel, recipient, subject, message, status, error_message, sent_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())'
    );
    $stmt->execute([
        $companyId, $userId, $channel, $recipient, $subject, $message,
        $success ? 'sent' : 'failed', $errorMessage,
    ]);
}
