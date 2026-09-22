<?php
/**
 * XML sitemap for the public storefront: each active farm's store page and
 * every published, in-stock listing. Helps Google find all of them.
 *
 * Submit this address in Google Search Console (Sitemaps):
 *   https://cthkennels.com/hef/sitemap.php
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/plan_limits.php';

$base = 'https://cthkennels.com/hef';

$stmt = $pdo->query(
    "SELECT c.id AS company_id, c.slug AS company_slug, sl.slug AS listing_slug, sl.updated_at
     FROM storefront_listings sl
     JOIN companies c ON c.id = sl.company_id
     WHERE c.status IN ('trial', 'active')
       AND sl.status = 'published'
       AND sl.quantity_available > 0
     ORDER BY c.slug, sl.title"
);

$storeLastmod = [];   // company slug => newest listing update
$listingUrls = [];    // [url, lastmod]
foreach ($stmt->fetchAll() as $row) {
    // Stores switched off by their plan aren't listed.
    if (! hef_company_storefront_allowed($pdo, (int) $row['company_id'])) {
        continue;
    }
    $lastmod = $row['updated_at'] ? date('Y-m-d', strtotime($row['updated_at'])) : null;

    $slug = $row['company_slug'];
    if (! isset($storeLastmod[$slug]) || ($lastmod && $lastmod > $storeLastmod[$slug])) {
        $storeLastmod[$slug] = $lastmod;
    }
    $listingUrls[] = [$base . '/listing.php?slug=' . urlencode($row['listing_slug']), $lastmod];
}

function hef_sitemap_url(string $loc, ?string $lastmod): string
{
    $xml = '  <url><loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>';
    if ($lastmod) {
        $xml .= '<lastmod>' . $lastmod . '</lastmod>';
    }

    return $xml . "</url>\n";
}

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($storeLastmod as $slug => $lastmod) {
    echo hef_sitemap_url($base . '/store.php?company=' . urlencode($slug), $lastmod);
}
foreach ($listingUrls as [$url, $lastmod]) {
    echo hef_sitemap_url($url, $lastmod);
}
echo "</urlset>\n";
