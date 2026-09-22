<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

hef_logout($pdo);
header('Location: /hef/admin/login.php');
exit;
