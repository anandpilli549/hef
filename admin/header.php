<?php
$navHtml = hef_nav_items(
    $currentPage,
    (bool) ($currentUser['is_super_admin'] ?? false),
    $currentUser['role'] ?? 'worker',
    hef_user_can_access_pro_pages($pdo, $currentUser),
    hef_company_has_pro($pdo, (int) $currentUser['company_id'])
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <title>HEFarm — Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        :root { --hef-green: #2f7d4f; --hef-green-dark: #235f3c; }
        body { background: #f5f7f6; margin: 0; }

        .hef-sidebar {
            background: var(--hef-green-dark);
            width: 250px;
            flex-shrink: 0;
        }
        .hef-sidebar .nav-link {
            color: rgba(255,255,255,0.85);
            border-radius: 8px;
            margin-bottom: 2px;
            font-size: 0.95rem;
        }
        .hef-sidebar .nav-link:hover,
        .hef-sidebar .nav-link.active {
            background: rgba(255,255,255,0.12);
            color: #fff;
        }
        /* Collapsible menu groups (native <details>). */
        .hef-sidebar details.hef-nav-group > summary {
            display: flex;
            align-items: center;
            list-style: none;
            cursor: pointer;
            user-select: none;
        }
        .hef-sidebar details.hef-nav-group > summary::-webkit-details-marker { display: none; }
        .hef-sidebar .hef-chevron { margin-left: auto; font-size: 0.7rem; transition: transform 0.15s; }
        .hef-sidebar details.hef-nav-group[open] > summary .hef-chevron { transform: rotate(90deg); }
        .hef-sidebar summary.hef-group-active { color: #fff; font-weight: 600; }
        .hef-sidebar .hef-subnav {
            margin: 2px 0 6px 1rem;
            padding-left: 0.5rem;
            border-left: 1px solid rgba(255,255,255,0.18);
        }
        .hef-sidebar .hef-subnav .nav-link { font-size: 0.9rem; padding: 0.35rem 0.75rem; }
        .hef-brand { color: #fff; font-weight: 700; letter-spacing: 0.5px; }
        .hef-topbar { background: var(--hef-green); }
        .hef-content { padding: 1.25rem; }

        .hef-sidebar-desktop { display: none; }
        .hef-topbar-mobile { display: flex; }

        @media (min-width: 992px) {
            .hef-content { padding: 2rem; }
            .hef-sidebar-desktop { display: flex; min-height: 100vh; }
            .hef-topbar-mobile { display: none; }
        }

        .card { border: none; border-radius: 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        .btn-hef { background: var(--hef-green); border-color: var(--hef-green); color: #fff; }
        .btn-hef:hover { background: var(--hef-green-dark); border-color: var(--hef-green-dark); color: #fff; }
    </style>
</head>
<body>

<nav class="navbar hef-topbar navbar-dark hef-topbar-mobile px-3">
    <button class="btn btn-outline-light btn-sm" type="button" data-bs-toggle="offcanvas" data-bs-target="#hefSidebarMobile">
        <i class="bi bi-list fs-4"></i>
    </button>
    <span class="navbar-brand mb-0 h1">HEFarm</span>
    <div style="width:38px;"></div>
</nav>

<div class="offcanvas offcanvas-start hef-sidebar text-white" tabindex="-1" id="hefSidebarMobile">
    <div class="offcanvas-header">
        <span class="hef-brand fs-4">HEFarm</span>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body d-flex flex-column p-3 pt-0">
        <div class="mb-3 small text-white-50">
            <div class="d-flex align-items-center gap-2">
                <?php if (!empty($currentUser['photo_path'])): ?>
                    <img src="/hef/<?= htmlspecialchars($currentUser['photo_path']) ?>" class="rounded-circle" style="width:32px; height:32px; object-fit:cover;">
                <?php else: ?>
                    <i class="bi bi-person-circle fs-4"></i>
                <?php endif; ?>
                <div>Logged in as<br><span class="text-white fw-semibold"><?= htmlspecialchars($currentUser['name']) ?></span></div>
            </div>
        </div>
        <ul class="nav nav-pills flex-column mb-auto gap-1"><?= $navHtml ?></ul>
        <hr class="text-white-50">
        <form method="POST" action="/hef/admin/logout.php">
            <button type="submit" class="btn btn-outline-light btn-sm w-100"><i class="bi bi-box-arrow-right me-1"></i>Log out</button>
        </form>
    </div>
</div>

<div class="d-flex">

    <div class="hef-sidebar hef-sidebar-desktop flex-column p-3 text-white">
        <div class="hef-brand fs-3 mb-4">HEFarm</div>
        <div class="mb-3 small text-white-50">
            <div class="d-flex align-items-center gap-2">
                <?php if (!empty($currentUser['photo_path'])): ?>
                    <img src="/hef/<?= htmlspecialchars($currentUser['photo_path']) ?>" class="rounded-circle" style="width:32px; height:32px; object-fit:cover;">
                <?php else: ?>
                    <i class="bi bi-person-circle fs-4"></i>
                <?php endif; ?>
                <div>Logged in as<br><span class="text-white fw-semibold"><?= htmlspecialchars($currentUser['name']) ?></span></div>
            </div>
        </div>
        <ul class="nav nav-pills flex-column mb-auto gap-1"><?= $navHtml ?></ul>
        <hr class="text-white-50">
        <form method="POST" action="/hef/admin/logout.php">
            <button type="submit" class="btn btn-outline-light btn-sm w-100"><i class="bi bi-box-arrow-right me-1"></i>Log out</button>
        </form>
    </div>

    <div class="flex-fill">
        <div class="hef-content">
        <?php
        // Soft billing reminder — never blocks access, just nudges.
        // The platform owner's own company never needs one.
        if (basename($_SERVER['SCRIPT_NAME']) !== 'billing.php' && $currentUser['company_id'] != HEF_OWNER_COMPANY_ID) {
            $stmt = $pdo->prepare('SELECT status, trial_ends_at FROM companies WHERE id = ?');
            $stmt->execute([$currentUser['company_id']]);
            $companyBillingInfo = $stmt->fetch();

            if ($companyBillingInfo['status'] === 'trial' && $companyBillingInfo['trial_ends_at']) {
                $daysLeft = (int) ((strtotime($companyBillingInfo['trial_ends_at']) - time()) / 86400);
                if ($daysLeft <= 3) {
                    $msg = $daysLeft > 0 ? "Your free trial ends in {$daysLeft} day(s)." : 'Your free trial has ended.';
                    echo '<div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">'
                        . '<span><i class="bi bi-clock-history me-2"></i>' . htmlspecialchars($msg) . '</span>'
                        . '<a href="/hef/admin/billing.php" class="btn btn-sm btn-dark">Choose a Plan</a></div>';
                }
            } elseif ($companyBillingInfo['status'] === 'expired') {
                echo '<div class="alert alert-danger d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">'
                    . '<span><i class="bi bi-exclamation-triangle me-2"></i>Your subscription has expired.</span>'
                    . '<a href="/hef/admin/billing.php" class="btn btn-sm btn-dark">Renew Now</a></div>';
            }
        }
        ?>
