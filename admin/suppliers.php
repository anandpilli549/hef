<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/permissions.php';

// Suppliers and the animals and rates they offer. A Pro feature (Owner only).
hef_require_pro($pdo, $currentUser);

$contactType = 'supplier';
require __DIR__ . '/../includes/contacts_page.php';
