<?php
require_once __DIR__ . '/bootstrap.php';

// Sends one SMS or WhatsApp message through the company's own provider account,
// for the SMS / WhatsApp buttons next to phone numbers (see hef_contact_buttons()).
// Answers in JSON. On a phone the SMS button uses the phone's own SIM instead
// and never comes here.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$reply = function (array $data, int $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $reply(['ok' => false, 'error' => 'POST only.'], 405);
}
if ($currentUser['role'] === 'vet') {
    $reply(['ok' => false, 'error' => 'You don\'t have access to send messages.'], 403);
}

// A simple brake so a mistake (or a stolen session) can't run up the provider bill: 30 messages per 10 minutes.
$now = time();
$recent = array_filter($_SESSION['contact_sends'] ?? [], function ($t) use ($now) { return $t > $now - 600; });
if (count($recent) >= 30) {
    $reply(['ok' => false, 'error' => 'That\'s a lot of messages in a short time. Please wait a few minutes.'], 429);
}

$channel = (string) ($_POST['channel'] ?? '');
$intl = hef_intl_phone((string) ($_POST['phone'] ?? ''));
$message = trim((string) ($_POST['message'] ?? ''));

if (! in_array($channel, ['sms', 'whatsapp'], true) || $intl === null) {
    $reply(['ok' => false, 'error' => 'That phone number or channel isn\'t valid.'], 422);
}
if ($message === '' || mb_strlen($message) > 1000) {
    $reply(['ok' => false, 'error' => 'Type a message (up to 1000 characters).'], 422);
}

$companyId = (int) $currentUser['company_id'];
$userId = (int) $currentUser['user_id'];
$ctx = hef_messaging_context();

if ($channel === 'sms') {
    $result = $ctx['sms_api']
        ? hef_send_sms_detailed($pdo, $intl, $message, $companyId, $userId)
        : ['ok' => false, 'error' => 'SMS is not set up (Settings, SMS tab).'];
} else {
    $result = $ctx['wa_api']
        ? hef_send_whatsapp_message($pdo, $intl, $message, $companyId, $userId)
        : ['ok' => false, 'error' => 'WhatsApp API sending is not turned on (Settings, WhatsApp tab).'];
}

if ($result['ok']) {
    $recent[] = $now;
    $_SESSION['contact_sends'] = array_values($recent);
    $reply(['ok' => true, 'message' => ($channel === 'sms' ? 'SMS' : 'WhatsApp message') . ' sent.']);
}

$reply([
    'ok' => false,
    'error' => $result['error'] ?: 'The message could not be sent.',
    // If the API can't send, WhatsApp can still be opened by hand.
    'fallback_url' => $channel === 'whatsapp' ? hef_whatsapp_link($intl, $message) : null,
]);
