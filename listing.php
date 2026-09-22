<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/plan_limits.php';

hef_release_expired_reservations($pdo);

$slug = $_GET['slug'] ?? '';

$stmt = $pdo->prepare(
    "SELECT sl.*, c.name AS company_name, c.slug AS company_slug, c.logo_path, c.banner_path, c.address, c.timezone, c.currency_code,
            b.photo_path AS batch_photo_path
     FROM storefront_listings sl
     JOIN companies c ON c.id = sl.company_id
     LEFT JOIN batches b ON b.id = sl.batch_id
     WHERE sl.slug = ? AND sl.status = 'published' LIMIT 1"
);
$stmt->execute([$slug]);
$listing = $stmt->fetch();

// Not found, or the company's plan doesn't include the online storefront.
if (! $listing || ! hef_company_storefront_allowed($pdo, (int) $listing['company_id'])) {
    http_response_code(404);
    die('Listing not found or no longer available.');
}

$listing['display_photo'] = $listing['og_image_path'] ?: $listing['batch_photo_path'];

$pageTitle = $listing['seo_title'] ?: ($listing['title'] . ' | ' . $listing['company_name']);
$pageDesc = $listing['seo_description'] ?: ("Book {$listing['title']} from {$listing['company_name']}.");
$listingUrl = "https://cthkennels.com/hef/listing.php?slug=" . urlencode($listing['slug']);
$absoluteImageUrl = $listing['display_photo'] ? "https://cthkennels.com/hef/{$listing['display_photo']}" : null;

// Only show "available from" if it's genuinely a future date — a past
// date just means it's been available since then, which isn't worth
// displaying (and looks stale/wrong).
$showAvailableFrom = $listing['available_from'] && strtotime($listing['available_from']) > strtotime('today');

// Structured data for Google (Product with price, stock and seller). Built
// as an array so titles with quotes or symbols can never break the JSON.
$productSchema = array_filter([
    '@context' => 'https://schema.org',
    '@type' => 'Product',
    'name' => $listing['title'],
    'description' => $pageDesc,
    'image' => $absoluteImageUrl,
    'url' => $listingUrl,
    'sku' => $listing['slug'],
    'category' => ucfirst($listing['type']),
    'offers' => [
        '@type' => 'Offer',
        'url' => $listingUrl,
        'priceCurrency' => $listing['currency_code'],
        'price' => (string) $listing['price'],
        'availability' => $listing['quantity_available'] > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
        'seller' => ['@type' => 'Organization', 'name' => $listing['company_name']],
    ],
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <meta name="description" content="<?= htmlspecialchars($pageDesc) ?>">
    <link rel="canonical" href="<?= htmlspecialchars($listingUrl) ?>">

    <meta property="og:title" content="<?= htmlspecialchars($pageTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($pageDesc) ?>">
    <meta property="og:type" content="product">
    <meta property="og:url" content="<?= htmlspecialchars($listingUrl) ?>">
    <?php if ($absoluteImageUrl): ?><meta property="og:image" content="<?= htmlspecialchars($absoluteImageUrl) ?>"><?php endif; ?>

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($pageTitle) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($pageDesc) ?>">

    <script type="application/ld+json"><?= json_encode($productSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f5f7f6; }
        .card { border: none; border-radius: 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        .btn-hef { background: #2f7d4f; border-color: #2f7d4f; color: #fff; }
        .btn-hef:hover { background: #235f3c; border-color: #235f3c; color: #fff; }
    </style>
</head>
<body>

<?php if ($listing['banner_path']): ?>
    <div style="height:120px; background-image:url('/hef/<?= htmlspecialchars($listing['banner_path']) ?>'); background-size:cover; background-position:center;"></div>
<?php endif; ?>

<nav class="navbar navbar-dark px-3" style="background:#1d4a30;">
    <a href="/hef/store.php?company=<?= urlencode($listing['company_slug']) ?>" class="navbar-brand fw-bold text-white text-decoration-none d-flex align-items-center gap-2">
        <?php if ($listing['logo_path']): ?><img src="/hef/<?= htmlspecialchars($listing['logo_path']) ?>" alt="" style="height:28px;"><?php endif; ?>
        <?= htmlspecialchars($listing['company_name']) ?>
    </a>
</nav>

<div class="container py-4" style="max-width: 600px;">
    <div class="card p-4">
        <?php if ($listing['display_photo']): ?>
            <img src="/hef/<?= htmlspecialchars($listing['display_photo']) ?>" alt="<?= htmlspecialchars($listing['title']) ?>" class="rounded mb-3" style="width:100%; height:280px; object-fit:cover;">
        <?php endif; ?>

        <span class="badge bg-light text-dark text-capitalize mb-2 align-self-start"><?= htmlspecialchars($listing['type'] === 'animal' ? 'Live' : ucfirst($listing['type'])) ?></span>
        <h1 class="h2"><?= htmlspecialchars($listing['title']) ?></h1>
        <div class="fs-3 fw-bold text-success my-2">₹<?= number_format($listing['price'], 0) ?></div>
        <div class="text-muted mb-3">
            <?= (int) $listing['quantity_available'] ?> available
            <?php if ($showAvailableFrom): ?>
                · from <?= htmlspecialchars($listing['available_from']) ?>
            <?php else: ?>
                · available now
            <?php endif; ?>
        </div>

        <?php if ($listing['quantity_available'] > 0): ?>
            <a href="/hef/book.php?slug=<?= urlencode($listing['slug']) ?>" class="btn btn-hef btn-lg w-100">Book Now</a>
        <?php else: ?>
            <button class="btn btn-secondary btn-lg w-100" disabled>Sold Out</button>
        <?php endif; ?>

        <?php if ($listing['address']): ?>
            <div class="text-muted small mt-3"><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($listing['address']) ?></div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
