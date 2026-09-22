<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/permissions.php';

// Customers and their requirements. A Pro feature (Owner only).
hef_require_pro($pdo, $currentUser);

$contactType = 'customer';
require __DIR__ . '/../includes/contacts_page.php';
