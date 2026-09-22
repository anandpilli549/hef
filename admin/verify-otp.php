<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (hef_check_logged_in($pdo)) {
    header('Location: /hef/admin/dashboard.php');
    exit;
}

if (empty($_SESSION['otp_email'])) {
    header('Location: /hef/admin/login.php');
    exit;
}

$email = $_SESSION['otp_email'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');
    $user = hef_verify_otp($pdo, $email, $code);

    if ($user === false) {
        $error = 'Invalid or expired code.';
    } else {
        unset($_SESSION['otp_email']);
        header('Location: /hef/admin/dashboard.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Enter code — HEFarm</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(160deg, #2f7d4f, #1d4a30); min-height: 100vh; }
        .auth-card { border-radius: 16px; max-width: 400px; }
        .btn-hef { background: #2f7d4f; border-color: #2f7d4f; }
        .btn-hef:hover { background: #235f3c; border-color: #235f3c; }
        input[name="code"] { letter-spacing: 8px; font-size: 1.5rem; text-align: center; }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center p-3">
    <div class="card auth-card w-100 p-4 p-sm-5 shadow">
        <h2 class="text-center mb-1">Enter your code</h2>
        <p class="text-center text-muted mb-4">Sent to <?= htmlspecialchars($email) ?></p>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="text" name="code" class="form-control form-control-lg mb-3" inputmode="numeric" maxlength="6" required autofocus>
            <button type="submit" class="btn btn-hef btn-lg w-100 text-white">Verify & log in</button>
        </form>
    </div>
</body>
</html>
