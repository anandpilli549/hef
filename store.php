<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/plan_limits.php';

hef_release_expired_reservations($pdo);

$companySlug = $_GET['company'] ?? null;

if ($companySlug) {
    $stmt = $pdo->prepare('SELECT * FROM companies WHERE slug = ? LIMIT 1');
    $stmt->execute([$companySlug]);
} else {
    // Single-company phase: fall back to the first active company
    $stmt = $pdo->query("SELECT * FROM companies WHERE status IN ('trial','active') ORDER BY id LIMIT 1");
}
$company = $stmt->fetch();

if (! $company) {
    http_response_code(404);
    die('Store not found.');
}

// The company's plan may not include the online storefront.
if (! hef_company_storefront_allowed($pdo, (int) $company['id'])) {
    http_response_code(404);
    die('This store is not available.');
}

$stmt = $pdo->prepare(
    "SELECT sl.*, b.photo_path AS batch_photo_path
     FROM storefront_listings sl
     LEFT JOIN batches b ON b.id = sl.batch_id
     WHERE sl.company_id = ? AND sl.status = 'published' AND sl.quantity_available > 0
     ORDER BY sl.type, sl.title"
);
$stmt->execute([$company['id']]);
$listings = $stmt->fetchAll();
foreach ($listings as &$l) {
    $l['display_photo'] = $l['og_image_path'] ?: $l['batch_photo_path'];
}
unset($l);

$pageTitle = htmlspecialchars($company['name']) . ' — Farm Store';
$pageDesc = "Fresh animals, eggs, and meat from {$company['name']}. Book online for pickup or delivery.";
$absoluteBannerUrl = $company['banner_path'] ? "https://cthkennels.com/hef/{$company['banner_path']}" : null;
$absoluteLogoUrl = $company['logo_path'] ? "https://cthkennels.com/hef/{$company['logo_path']}" : null;
$shareImageUrl = $absoluteBannerUrl ?: $absoluteLogoUrl;

// One canonical address per store, so /store.php (no ?company=) and any
// tracking-parameter variants all count as the same page for Google.
$storeUrl = 'https://cthkennels.com/hef/store.php?company=' . urlencode($company['slug']);

// Structured data: the farm as a local business, plus the list of items for
// sale (each links to its own listing page, which carries Product markup).
$listItems = [];
foreach ($listings as $i => $l) {
    $listItems[] = [
        '@type' => 'ListItem',
        'position' => $i + 1,
        'url' => 'https://cthkennels.com/hef/listing.php?slug=' . urlencode($l['slug']),
    ];
}
$storeSchema = [
    '@context' => 'https://schema.org',
    '@graph' => array_values(array_filter([
        array_filter([
            '@type' => 'LocalBusiness',
            '@id' => $storeUrl . '#business',
            'name' => $company['name'],
            'url' => $storeUrl,
            'image' => $shareImageUrl,
            'address' => $company['address'] ?: null,
        ]),
        $listItems ? ['@type' => 'ItemList', 'itemListElement' => $listItems] : null,
    ])),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $pageTitle ?></title>
    <meta name="description" content="<?= htmlspecialchars($pageDesc) ?>">
    <link rel="canonical" href="<?= htmlspecialchars($storeUrl) ?>">
    <?php if ($company['logo_path']): ?><link rel="icon" href="/hef/<?= htmlspecialchars($company['logo_path']) ?>"><?php endif; ?>

    <meta property="og:title" content="<?= $pageTitle ?>">
    <meta property="og:url" content="<?= htmlspecialchars($storeUrl) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($pageDesc) ?>">
    <meta property="og:type" content="website">
    <?php if ($shareImageUrl): ?><meta property="og:image" content="<?= htmlspecialchars($shareImageUrl) ?>"><?php endif; ?>

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= $pageTitle ?>">
    <?php if ($shareImageUrl): ?><meta name="twitter:image" content="<?= htmlspecialchars($shareImageUrl) ?>"><?php endif; ?>
    <meta name="twitter:description" content="<?= htmlspecialchars($pageDesc) ?>">

    <script type="application/ld+json"><?= json_encode($storeSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f5f7f6; }
        .hero { background: linear-gradient(160deg, #2f7d4f, #1d4a30); color: #fff; }
        .card { border: none; border-radius: 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        .btn-hef { background: #2f7d4f; border-color: #2f7d4f; color: #fff; }
        .btn-hef:hover { background: #235f3c; border-color: #235f3c; color: #fff; }
    </style>
</head>
<body>

<?php if ($company['banner_path']): ?>
    <div style="height:140px; background-image:url('/hef/<?= htmlspecialchars($company['banner_path']) ?>'); background-size:cover; background-position:center;"></div>
<?php endif; ?>

<div class="hero text-center py-5 px-3">
    <?php if ($company['logo_path']): ?><img src="/hef/<?= htmlspecialchars($company['logo_path']) ?>" alt="<?= htmlspecialchars($company['name']) ?> logo" style="height:60px;" class="mb-2"><?php endif; ?>
    <h1 class="h2 fw-bold"><?= htmlspecialchars($company['name']) ?></h1>
    <?php if ($company['address']): ?><p class="mb-0"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($company['address']) ?></p><?php endif; ?>
</div>

<div class="container py-4">
    <div class="row g-3">
        <?php if (empty($listings)): ?>
            <div class="col-12"><div class="card p-5 text-center text-muted">Nothing available right now — check back soon.</div></div>
        <?php endif; ?>
        <?php foreach ($listings as $l): ?>
            <div class="col-12 col-sm-6 col-lg-4">
                <a href="/hef/listing.php?slug=<?= urlencode($l['slug']) ?>" class="text-decoration-none text-dark">
                    <div class="card p-3 h-100">
                        <?php if ($l['display_photo']): ?>
                            <img src="/hef/<?= htmlspecialchars($l['display_photo']) ?>" alt="<?= htmlspecialchars($l['title']) ?>" class="rounded mb-2" style="width:100%; height:150px; object-fit:cover;">
                        <?php endif; ?>
                        <span class="badge bg-light text-dark text-capitalize mb-2 align-self-start"><?= htmlspecialchars($l['type'] === 'animal' ? 'Live' : ucfirst($l['type'])) ?></span>
                        <div class="fw-semibold"><?= htmlspecialchars($l['title']) ?></div>
                        <div class="fs-4 fw-bold text-success mt-1">₹<?= number_format($l['price'], 0) ?></div>
                        <div class="text-muted small"><?= (int) $l['quantity_available'] ?> available</div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</div>

</body>
</html>
