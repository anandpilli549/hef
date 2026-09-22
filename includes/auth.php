<?php
/**
 * OTP + persistent device-session auth.
 * Include this AFTER db.php (needs $pdo).
 */

require_once __DIR__ . '/db.php';

define('DEVICE_COOKIE_NAME', 'hef_device_token');
define('DEVICE_COOKIE_LIFETIME_DAYS', 365 * 5); // ~5 years, "forever" until explicit logout

/**
 * Generate + store a 6-digit OTP for the given email, throttled to
 * 1 request per 60 seconds. Returns the code on success, or false if
 * throttled / email not found.
 */
function hef_request_otp(PDO $pdo, string $email): string|false
{
    $email = strtolower(trim($email));

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if (! $stmt->fetch()) {
        return false; // no account with this email
    }

    $stmt = $pdo->prepare(
        'SELECT id FROM otp_codes WHERE email = ? AND created_at >= (NOW() - INTERVAL 60 SECOND) LIMIT 1'
    );
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        return false; // throttled
    }

    $code = (string) random_int(100000, 999999);

    $stmt = $pdo->prepare(
        'INSERT INTO otp_codes (email, code, expires_at, created_at, updated_at)
         VALUES (?, ?, NOW() + INTERVAL 10 MINUTE, NOW(), NOW())'
    );
    $stmt->execute([$email, $code]);

    return $code;
}

/**
 * Verify a submitted OTP. On success, creates a user_devices row and
 * sets the long-lived device cookie, returning the user row.
 * Returns false on invalid/expired code.
 */
function hef_verify_otp(PDO $pdo, string $email, string $code): array|false
{
    $email = strtolower(trim($email));

    $stmt = $pdo->prepare(
        'SELECT * FROM otp_codes
         WHERE email = ? AND code = ? AND used_at IS NULL AND expires_at > NOW()
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$email, $code]);
    $otp = $stmt->fetch();

    if (! $otp) {
        return false;
    }

    $pdo->prepare('UPDATE otp_codes SET used_at = NOW() WHERE id = ?')->execute([$otp['id']]);

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (! $user) {
        return false;
    }

    if ($user['status'] === 'invited') {
        $pdo->prepare("UPDATE users SET status = 'active', email_verified_at = NOW(), updated_at = NOW() WHERE id = ?")
            ->execute([$user['id']]);
        $user['status'] = 'active';
    }

    $token = bin2hex(random_bytes(40));
    $tokenHash = hash('sha256', $token);
    $deviceName = hef_guess_device_name($_SERVER['HTTP_USER_AGENT'] ?? '');

    $stmt = $pdo->prepare(
        'INSERT INTO user_devices (user_id, device_name, ip_address, user_agent, session_token, last_active_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, NOW(), NOW(), NOW())'
    );
    $stmt->execute([
        $user['id'], $deviceName, $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null, $tokenHash,
    ]);

    setcookie(DEVICE_COOKIE_NAME, $token, [
        'expires'  => time() + (DEVICE_COOKIE_LIFETIME_DAYS * 86400),
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    $_SESSION['user_id'] = $user['id'];

    return $user;
}

/**
 * Call at the top of every protected admin page.
 * Validates the device cookie against user_devices, loads the user
 * into $_SESSION, and redirects to login if invalid/revoked/missing.
 * Also throttles last_active_at updates to once per 5 minutes.
 */
/**
 * Checks the device cookie WITHOUT redirecting. Returns the user row if
 * valid and not revoked, or false otherwise. Use on login/verify pages
 * to detect an already-logged-in visitor.
 */
function hef_check_logged_in(PDO $pdo): array|false
{
    $token = $_COOKIE[DEVICE_COOKIE_NAME] ?? null;
    if (! $token) {
        return false;
    }

    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare(
        'SELECT d.*, u.id as user_id, u.name, u.email, u.company_id, u.is_super_admin, u.status, u.photo_path, u.role
         FROM user_devices d JOIN users u ON u.id = d.user_id
         WHERE d.session_token = ? AND d.revoked_at IS NULL LIMIT 1'
    );
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();

    return ($row && $row['status'] === 'active') ? $row : false;
}

function hef_require_login(PDO $pdo): array
{
    $token = $_COOKIE[DEVICE_COOKIE_NAME] ?? null;

    if (! $token) {
        hef_redirect_to_login();
    }

    $tokenHash = hash('sha256', $token);

    $stmt = $pdo->prepare(
        'SELECT d.*, u.id as user_id, u.name, u.email, u.company_id, u.is_super_admin, u.status, u.photo_path, u.role
         FROM user_devices d
         JOIN users u ON u.id = d.user_id
         WHERE d.session_token = ? AND d.revoked_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();

    if (! $row || $row['status'] !== 'active') {
        hef_redirect_to_login();
    }

    // Throttle last_active_at writes
    if (empty($row['last_active_at']) || strtotime($row['last_active_at']) < strtotime('-5 minutes')) {
        $pdo->prepare('UPDATE user_devices SET last_active_at = NOW() WHERE id = ?')->execute([$row['id']]);
    }

    return $row;
}

function hef_redirect_to_login(): never
{
    setcookie(DEVICE_COOKIE_NAME, '', time() - 3600, '/');
    header('Location: /hef/admin/login.php');
    exit;
}

/**
 * Logs out the CURRENT device only (revokes its row + clears its cookie).
 * Other devices for this user remain logged in.
 */
function hef_logout(PDO $pdo): void
{
    $token = $_COOKIE[DEVICE_COOKIE_NAME] ?? null;
    if ($token) {
        $tokenHash = hash('sha256', $token);
        $pdo->prepare('UPDATE user_devices SET revoked_at = NOW() WHERE session_token = ?')->execute([$tokenHash]);
    }
    setcookie(DEVICE_COOKIE_NAME, '', time() - 3600, '/');
    session_destroy();
}

function hef_guess_device_name(string $userAgent): string
{
    return match (true) {
        str_contains($userAgent, 'iPhone') => 'iPhone',
        str_contains($userAgent, 'iPad') => 'iPad',
        str_contains($userAgent, 'Android') => 'Android device',
        str_contains($userAgent, 'Macintosh') => 'Mac',
        str_contains($userAgent, 'Windows') => 'Windows PC',
        default => 'Unknown device',
    };
}
