<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/contacts.php';

// Builds a follow-up event for a customer or supplier: redirects to Google
// Calendar, or streams a .ics file for Apple Calendar / Outlook / others.
// Called from the "Save to calendar" button (see includes/contacts.php).

hef_require_pro($pdo, $currentUser);

$type = (string) ($_GET['type'] ?? '');
if (! in_array($type, ['customer', 'supplier'], true)) {
    http_response_code(400);
    exit('Unknown type.');
}
$T = hef_contact_meta($type);
$companyId = (int) $currentUser['company_id'];

$stmt = $pdo->prepare('SELECT * FROM ' . $T['table'] . ' WHERE id = ? AND company_id = ?');
$stmt->execute([(int) ($_GET['id'] ?? 0), $companyId]);
$contact = $stmt->fetch();
if (! $contact) {
    http_response_code(404);
    exit('Not found.');
}

$tz = hef_company_tz($pdo, $companyId);
$start = DateTime::createFromFormat('Y-m-d\TH:i', (string) ($_GET['start'] ?? ''), new DateTimeZone($tz));
if (! $start) {
    http_response_code(400);
    exit('Invalid date/time.');
}
$mins = max(5, min(480, (int) ($_GET['mins'] ?? 30)));
$end = (clone $start)->modify('+' . $mins . ' minutes');
$title = trim((string) ($_GET['title'] ?? '')) ?: 'Call ' . $contact['name'];
$title = mb_substr($title, 0, 200);

$descLines = [];
foreach (hef_contact_numbers($contact, $T) as $n) {
    $descLines[] = 'Phone: ' . hef_format_phone($n);
}
if (! empty($contact['email'])) {
    $descLines[] = 'Email: ' . $contact['email'];
}
if (! empty($contact['address'])) {
    $descLines[] = 'Address: ' . $contact['address'];
}
$descLines[] = 'HEFarm ' . $T['label'] . ': https://cthkennels.com/hef/admin/' . $T['page'];
$description = implode("\n", $descLines);
$location = (string) ($contact['address'] ?? '');

$format = (string) ($_GET['format'] ?? 'google');

if ($format === 'ics') {
    $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//HEFarm//Contacts//EN\r\nCALSCALE:GREGORIAN\r\n"
        . "BEGIN:VEVENT\r\nUID:" . bin2hex(random_bytes(8)) . "-hef@cthkennels.com\r\n"
        . 'DTSTAMP:' . gmdate('Ymd\THis\Z') . "\r\n"
        . 'DTSTART:' . (clone $start)->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z') . "\r\n"
        . 'DTEND:' . (clone $end)->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z') . "\r\n"
        . 'SUMMARY:' . hef_ics_escape($title) . "\r\n"
        . 'DESCRIPTION:' . hef_ics_escape($description) . "\r\n"
        . ($location !== '' ? 'LOCATION:' . hef_ics_escape($location) . "\r\n" : '')
        . "END:VEVENT\r\nEND:VCALENDAR\r\n";

    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9]+/i', '-', $title) . '.ics"');
    echo $ics;
    exit;
}

// Google Calendar's quick-add link.
$googleUrl = 'https://calendar.google.com/calendar/render?' . http_build_query([
    'action' => 'TEMPLATE',
    'text' => $title,
    'dates' => (clone $start)->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z') . '/' . (clone $end)->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'),
    'details' => $description,
    'location' => $location,
]);
header('Location: ' . $googleUrl);
exit;

function hef_ics_escape(string $text): string
{
    return str_replace(["\\", ",", ";", "\n"], ["\\\\", "\\,", "\\;", "\\n"], $text);
}
