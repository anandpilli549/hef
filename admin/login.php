<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global.php';
require_once __DIR__ . '/../includes/mail-templates.php';
require_once __DIR__ . '/../includes/auth.php';

if (hef_check_logged_in($pdo)) {
    header('Location: /hef/admin/dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $code = hef_request_otp($pdo, $email);

    if ($code === false) {
        $error = 'No account found for this email, or please wait a minute before retrying.';
    } else {
        $inner = '<p style="margin:0 0 16px; color:#444; font-size:14px;">Use this code to log in. It expires in 10 minutes.</p>'
            . '<div style="text-align:center; margin:20px 0;"><span style="display:inline-block; padding:14px 28px; background:#f0f7f2; border:2px dashed #2f7d4f; border-radius:10px; font-size:32px; font-weight:bold; letter-spacing:8px; color:#1d4a30;">' . htmlspecialchars($code) . '</span></div>'
            . '<p style="color:#999; font-size:12px;">If you didn\'t request this, you can safely ignore this email.</p>';
        $body = hef_email_wrap('Your Login Code', $inner, "Your HEFarm login code is {$code}");
        hef_send_mail($pdo, $email, 'Your HEFarm login code', $body);

        $_SESSION['otp_email'] = $email;
        header('Location: /hef/admin/verify-otp.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Log in — HEFarm</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(160deg, #2f7d4f, #1d4a30); min-height: 100vh; }
        .auth-card { border-radius: 16px; max-width: 400px; }
        .btn-hef { background: #2f7d4f; border-color: #2f7d4f; }
        .btn-hef:hover { background: #235f3c; border-color: #235f3c; }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center p-3">
    <div class="card auth-card w-100 p-4 p-sm-5 shadow">
        <h2 class="text-center mb-1">HEFarm</h2>
        <p class="text-center text-muted mb-4">Log in to your farm dashboard</p>

        <?php if (isset($_GET['welcome']) && ! $error): ?>
            <div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i>Your account is ready! Enter your email below and we'll send you a one-time code to log in.</div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <label class="form-label">Email address</label>
            <input type="email" name="email" class="form-control form-control-lg mb-3" required autofocus>
            <button type="submit" class="btn btn-hef btn-lg w-100 text-white">Send login code</button>
        </form>
    </div>
</body>
</html>
