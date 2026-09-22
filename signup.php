<?php
session_start();
require_once __DIR__ . '/includes/db.php';

$error = '';
$values = ['company_name' => '', 'owner_name' => '', 'email' => ''];

/**
 * A store address that no other company uses: "green-valley-farm", then
 * "green-valley-farm-2" and so on. Names with no Latin letters or digits
 * (for example written in Telugu) fall back to "farm".
 */
function hef_unique_company_slug(PDO $pdo, string $name): string
{
    $base = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));
    $base = substr($base !== '' ? $base : 'farm', 0, 60);
    $slug = $base;
    $n = 2;
    $stmt = $pdo->prepare('SELECT 1 FROM companies WHERE slug = ?');
    while (true) {
        $stmt->execute([$slug]);
        if (! $stmt->fetchColumn()) {
            return $slug;
        }
        $slug = $base . '-' . $n++;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['company_name'] = trim($_POST['company_name'] ?? '');
    $values['owner_name'] = trim($_POST['owner_name'] ?? '');
    $values['email'] = strtolower(trim($_POST['email'] ?? ''));

    if (trim($_POST['website'] ?? '') !== '') {
        // Hidden field that people never see: only bots fill it in.
        http_response_code(400);
        exit;
    }

    if (! $values['company_name'] || ! $values['owner_name'] || ! filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Please fill in all fields with a valid email.';
    } elseif (strlen($values['company_name']) > 255 || strlen($values['owner_name']) > 255 || strlen($values['email']) > 255) {
        $error = 'One of the fields is too long. Please shorten it and try again.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$values['email']]);
        if ($stmt->fetch()) {
            $error = 'An account with this email already exists. Try logging in instead.';
        } else {
            $pdo->beginTransaction();
            try {
                $slug = hef_unique_company_slug($pdo, $values['company_name']);
                $stmt = $pdo->prepare(
                    "INSERT INTO companies (name, slug, timezone, currency_code, status, trial_ends_at, created_at, updated_at)
                     VALUES (?, ?, 'Asia/Kolkata', 'INR', 'trial', NOW() + INTERVAL 14 DAY, NOW(), NOW())"
                );
                $stmt->execute([$values['company_name'], $slug]);
                $companyId = $pdo->lastInsertId();

                $stmt = $pdo->prepare(
                    "INSERT INTO users (company_id, name, email, role, is_super_admin, status, email_verified_at, created_at, updated_at)
                     VALUES (?, ?, ?, 'owner', 0, 'active', NOW(), NOW(), NOW())"
                );
                $stmt->execute([$companyId, $values['owner_name'], $values['email']]);

                $pdo->commit();

                header('Location: /hef/admin/login.php?welcome=1');
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log('Signup failed: ' . $e->getMessage());
                $error = 'Something went wrong creating your account. Please try again.';
            }
        }
    }
}

$features = [
    ['bi-egg-fried', 'Track every batch', 'Counts, mortality and sell-by dates at a glance.'],
    ['bi-heart-pulse', 'Never miss a vaccination', 'Automatic reminders before anything is due.'],
    ['bi-cash-coin', 'Know your costs and profit', 'Feed, medicine and sales worked out per batch.'],
    ['bi-shop', 'Sell online', 'Your own store page, ready to share and find on Google.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Start your free 14-day trial of HEFarm: batches, health, feed, sales and more for your farm.">
    <title>Start your free trial — HEFarm</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root { --hef-green: #2f7d4f; --hef-green-dark: #1d4a30; }
        body { background: #f4f7f5; min-height: 100vh; }
        .signup { min-height: 100vh; }

        /* Left: brand panel */
        .hero {
            background:
                radial-gradient(circle at 15% 10%, rgba(255,255,255,.14), transparent 45%),
                radial-gradient(circle at 90% 90%, rgba(255,255,255,.10), transparent 40%),
                linear-gradient(160deg, var(--hef-green), var(--hef-green-dark));
            color: #fff;
        }
        .hero-inner { max-width: 470px; }
        .brand-mark { width: 46px; height: 46px; border-radius: 12px; background: rgba(255,255,255,.18); display: inline-flex; align-items: center; justify-content: center; font-size: 1.5rem; }
        .feature-icon { flex: 0 0 42px; height: 42px; border-radius: 12px; background: rgba(255,255,255,.16); display: flex; align-items: center; justify-content: center; font-size: 1.15rem; }
        .trial-pill { background: rgba(255,255,255,.16); border: 1px solid rgba(255,255,255,.3); border-radius: 999px; padding: .35rem .9rem; font-size: .85rem; }

        /* Right: form */
        .form-wrap { max-width: 440px; width: 100%; }
        .form-card { border: 0; border-radius: 18px; box-shadow: 0 10px 40px rgba(29, 74, 48, .12); }
        .input-group-text { background: #f0f5f2; border-right: 0; color: var(--hef-green); }
        .input-group .form-control { border-left: 0; }
        .input-group:focus-within .input-group-text,
        .input-group:focus-within .form-control { border-color: var(--hef-green); }
        .form-control:focus { box-shadow: 0 0 0 .2rem rgba(47, 125, 79, .15); border-color: var(--hef-green); }
        .btn-hef { background: var(--hef-green); border-color: var(--hef-green); color: #fff; font-weight: 600; }
        .btn-hef:hover, .btn-hef:focus { background: #235f3c; border-color: #235f3c; color: #fff; }
        .mobile-brand { background: linear-gradient(160deg, var(--hef-green), var(--hef-green-dark)); color: #fff; }
        .hp { position: absolute; left: -9999px; height: 0; width: 0; overflow: hidden; }
    </style>
</head>
<body>
<div class="signup d-flex flex-column flex-lg-row">

    <!-- Small screens: compact brand header -->
    <div class="mobile-brand d-lg-none text-center px-3 py-4">
        <span class="brand-mark mb-2"><i class="bi bi-flower1"></i></span>
        <h1 class="h4 mb-1 fw-bold">HEFarm</h1>
        <div class="small opacity-75">Run your farm with confidence</div>
    </div>

    <!-- Large screens: brand panel -->
    <aside class="hero d-none d-lg-flex col-lg-6 align-items-center justify-content-center p-5">
        <div class="hero-inner">
            <div class="d-flex align-items-center gap-3 mb-5">
                <span class="brand-mark"><i class="bi bi-flower1"></i></span>
                <span class="fs-3 fw-bold">HEFarm</span>
            </div>
            <h2 class="display-6 fw-bold mb-3">Run your farm with confidence.</h2>
            <p class="lead opacity-75 mb-4">Batches, health, feed, sales and staff — all in one simple app that works on your phone.</p>

            <ul class="list-unstyled mb-5">
                <?php foreach ($features as [$icon, $title, $text]): ?>
                    <li class="d-flex gap-3 mb-3">
                        <span class="feature-icon"><i class="bi <?= $icon ?>"></i></span>
                        <span><span class="d-block fw-semibold"><?= htmlspecialchars($title) ?></span><span class="opacity-75 small"><?= htmlspecialchars($text) ?></span></span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <span class="trial-pill"><i class="bi bi-gift me-1"></i>14 days free · No credit card needed</span>
        </div>
    </aside>

    <!-- The form -->
    <main class="col-lg-6 d-flex align-items-center justify-content-center p-3 p-sm-4 p-lg-5 flex-grow-1">
        <div class="form-wrap">
            <div class="card form-card">
                <div class="card-body p-4 p-sm-5">
                    <h2 class="h3 fw-bold mb-1">Create your account</h2>
                    <p class="text-muted mb-4">Start your free 14-day trial. It takes under a minute.</p>

                    <?php if ($error): ?>
                        <div class="alert alert-danger d-flex align-items-start gap-2 py-2" role="alert">
                            <i class="bi bi-exclamation-circle mt-1"></i><div><?= htmlspecialchars($error) ?><?php if (strpos($error, 'already exists') !== false): ?> <a href="/hef/admin/login.php" class="alert-link">Log in</a><?php endif; ?></div>
                        </div>
                    <?php endif; ?>

                    <form method="POST" autocomplete="on">
                        <div class="hp" aria-hidden="true">
                            <label>Leave this empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="company_name">Farm or company name</label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text"><i class="bi bi-house-heart"></i></span>
                                <input type="text" id="company_name" name="company_name" class="form-control" maxlength="255" required autofocus
                                    autocomplete="organization" placeholder="e.g. Green Valley Farm" value="<?= htmlspecialchars($values['company_name']) ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="owner_name">Your name</label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="text" id="owner_name" name="owner_name" class="form-control" maxlength="255" required
                                    autocomplete="name" placeholder="Your full name" value="<?= htmlspecialchars($values['owner_name']) ?>">
                            </div>
                        </div>

                        <div class="mb-2">
                            <label class="form-label fw-semibold" for="email">Email address</label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                                <input type="email" id="email" name="email" class="form-control" maxlength="255" required
                                    autocomplete="email" placeholder="you@example.com" value="<?= htmlspecialchars($values['email']) ?>">
                            </div>
                            <div class="form-text"><i class="bi bi-shield-check me-1 text-success"></i>No password to remember — we email you a one-time code each time you log in.</div>
                        </div>

                        <button type="submit" class="btn btn-hef btn-lg w-100 mt-4">Start my free trial <i class="bi bi-arrow-right ms-1"></i></button>
                    </form>

                    <div class="text-center text-muted mt-4">Already have an account? <a href="/hef/admin/login.php" class="fw-semibold" style="color:var(--hef-green);">Log in</a></div>
                </div>
            </div>
            <div class="text-center text-muted small mt-3 d-lg-none"><i class="bi bi-gift me-1"></i>14 days free · No credit card needed</div>
        </div>
    </main>
</div>
</body>
</html>
