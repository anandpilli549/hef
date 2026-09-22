<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HEFarm — Farm Management Made Simple</title>
    <meta name="description" content="HEFarm helps farms manage livestock, health, feed, and finances — with a built-in online storefront so customers can book directly.">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f5f7f6; }
        .hero { background: linear-gradient(160deg, #2f7d4f, #1d4a30); color: #fff; }
        .btn-hef { background: #fff; color: #2f7d4f; font-weight: 600; }
        .btn-hef:hover { background: #eafaf0; color: #235f3c; }
        .feature-icon { font-size: 1.8rem; color: #2f7d4f; }
        .feature-card { border: none; border-radius: 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); height: 100%; }
    </style>
</head>
<body>

<nav class="navbar navbar-dark px-3" style="background:#1d4a30;">
    <span class="navbar-brand fw-bold">HEFarm</span>
    <a href="/hef/admin/login.php" class="btn btn-outline-light btn-sm">Log in</a>
</nav>

<div class="hero text-center py-5 px-3">
    <h1 class="display-5 fw-bold">Farm management, simplified</h1>
    <p class="lead mb-4">Track livestock, health, feed, and finances — and sell directly to customers online.</p>
    <a href="/hef/signup.php" class="btn btn-hef btn-lg px-4">Start your free trial</a>
</div>

<div class="container py-5">
    <h2 class="h4 text-center mb-4">Everything your farm needs, in one place</h2>
    <div class="row g-4">
        <div class="col-12 col-sm-6 col-lg-4">
            <div class="card feature-card p-4">
                <i class="bi bi-egg-fried feature-icon mb-2"></i>
                <h5>Batch & Livestock Tracking</h5>
                <p class="text-muted small mb-0">Track every batch by species, room, and gender — with live headcounts that update automatically as animals are sold or lost.</p>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-4">
            <div class="card feature-card p-4">
                <i class="bi bi-heart-pulse feature-icon mb-2"></i>
                <h5>Health & Vaccinations</h5>
                <p class="text-muted small mb-0">Species-specific vaccination schedules with automatic due-date alerts, even accounting for the age of animals when you acquired them.</p>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-4">
            <div class="card feature-card p-4">
                <i class="bi bi-basket feature-icon mb-2"></i>
                <h5>Feed Tracking</h5>
                <p class="text-muted small mb-0">Log purchases and daily consumption, with a live running stock balance so you always know what's left.</p>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-4">
            <div class="card feature-card p-4">
                <i class="bi bi-cash-coin feature-icon mb-2"></i>
                <h5>Financial Reports</h5>
                <p class="text-muted small mb-0">Income, expenses, and full profit & loss — broken down by category and by individual batch, so you know exactly what's working.</p>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-4">
            <div class="card feature-card p-4">
                <i class="bi bi-shop feature-icon mb-2"></i>
                <h5>Online Storefront</h5>
                <p class="text-muted small mb-0">List available animals, eggs, and meat for sale, with a branded public page customers can browse and book from.</p>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-4">
            <div class="card feature-card p-4">
                <i class="bi bi-bag-check feature-icon mb-2"></i>
                <h5>Booking & Payments</h5>
                <p class="text-muted small mb-0">Customers book online with secure payment, choosing pickup or delivery — bookings sync straight into your farm's inventory.</p>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-4">
            <div class="card feature-card p-4">
                <i class="bi bi-shield-lock feature-icon mb-2"></i>
                <h5>Secure, Passwordless Login</h5>
                <p class="text-muted small mb-0">Email + one-time code login, with per-device session tracking and remote logout — no passwords to lose or leak.</p>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-4">
            <div class="card feature-card p-4">
                <i class="bi bi-door-open feature-icon mb-2"></i>
                <h5>Rooms & Housing</h5>
                <p class="text-muted small mb-0">Assign batches to rooms or coops to keep track of what's housed where and avoid overcrowding.</p>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-lg-4">
            <div class="card feature-card p-4">
                <i class="bi bi-phone feature-icon mb-2"></i>
                <h5>Works Great on Mobile</h5>
                <p class="text-muted small mb-0">Built mobile-first, so you can log a mortality event or check today's numbers right from the coop.</p>
            </div>
        </div>
    </div>
</div>

<div class="text-center pb-5">
    <a href="/hef/signup.php" class="btn btn-hef btn-lg px-4" style="background:#2f7d4f; color:#fff;">Start your free trial</a>
</div>

</body>
</html>
