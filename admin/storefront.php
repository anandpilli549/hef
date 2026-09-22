<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/permissions.php';
hef_require_role(['owner'], $currentUser['role']);
require_once __DIR__ . '/../includes/uploads.php';

$companyId = $currentUser['company_id'];
$error = '';

// --- Get company slug (needed for public URLs) ---
$stmt = $pdo->prepare('SELECT slug, name FROM companies WHERE id = ?');
$stmt->execute([$companyId]);
$company = $stmt->fetch();

// The plan may not include the online storefront. Existing listings can still
// be deleted, but nothing new can be created or changed, and the public
// store is switched off (see store.php).
$storefrontAllowed = hef_company_storefront_allowed($pdo, (int) $companyId);

// Batches without an existing listing yet (candidates for a new "animal" listing)
$stmt = $pdo->prepare(
    "SELECT b.id, b.batch_code, b.current_count_male, b.current_count_female, b.current_count_unknown, s.name AS species_name
     FROM batches b
     JOIN species s ON s.id = b.species_id
     WHERE b.company_id = ? AND b.status = 'active'
       AND NOT EXISTS (SELECT 1 FROM storefront_listings sl WHERE sl.batch_id = b.id)
     ORDER BY b.batch_code"
);
$stmt->execute([$companyId]);
$candidateBatches = $stmt->fetchAll();

function hef_slugify(string $text): string
{
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
}

// --- Delete ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_listing_id'])) {
    $id = (int) $_POST['delete_listing_id'];
    $pdo->prepare('DELETE FROM storefront_listings WHERE id = ? AND company_id = ?')->execute([$id, $companyId]);
    header('Location: /hef/admin/storefront.php');
    exit;
}

// --- Create listing from a batch (auto-suggested) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $storefrontAllowed && isset($_POST['create_from_batch'])) {
    $batchId = (int) $_POST['batch_id'];
    $price = (float) ($_POST['price'] ?? 0);

    $stmt = $pdo->prepare(
        "SELECT b.*, s.name AS species_name FROM batches b JOIN species s ON s.id = b.species_id WHERE b.id = ? AND b.company_id = ?"
    );
    $stmt->execute([$batchId, $companyId]);
    $batch = $stmt->fetch();

    if (! $batch || $price <= 0) {
        $error = 'Invalid batch or price.';
    } else {
        $qty = $batch['current_count_male'] + $batch['current_count_female'] + $batch['current_count_unknown'];
        $title = $batch['species_name'] . ' — ' . $batch['batch_code'];
        $slug = hef_slugify($title) . '-' . $batch['id'];

        try {
            $pdo->prepare(
                "INSERT INTO storefront_listings
                    (company_id, batch_id, title, slug, type, price, currency_code, quantity_available, available_from,
                     seo_title, seo_description, og_image_path, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 'animal', ?, 'INR', ?, CURDATE(), ?, ?, ?, 'draft', NOW(), NOW())"
            )->execute([
                $companyId, $batchId, $title, $slug, $price, $qty,
                $title . ' — Available Now | ' . $company['name'],
                "Fresh {$batch['species_name']} available from {$company['name']}. Book online for pickup or delivery.",
                $batch['photo_path'],
            ]);
            header('Location: /hef/admin/storefront.php');
            exit;
        } catch (PDOException $e) {
            error_log('storefront_listings insert failed: ' . $e->getMessage());
            $error = 'Could not create listing.';
        }
    }
}

// --- Create a manual listing (eggs/meat, not tied to live batch count) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $storefrontAllowed && isset($_POST['create_manual'])) {
    $title = trim($_POST['title'] ?? '');
    $type = in_array($_POST['type'] ?? '', ['animal', 'egg', 'meat'], true) ? $_POST['type'] : 'egg';
    $price = (float) ($_POST['price'] ?? 0);
    $qty = (int) ($_POST['quantity_available'] ?? 0);

    if (! $title || $price <= 0) {
        $error = 'Title and a positive price are required.';
    } else {
        $slug = hef_slugify($title) . '-' . substr(md5(microtime()), 0, 6);
        try {
            $photoPath = hef_handle_image_upload('photo', 'listings');

            $pdo->prepare(
                "INSERT INTO storefront_listings
                    (company_id, title, slug, type, price, currency_code, quantity_available, available_from,
                     seo_title, seo_description, og_image_path, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 'INR', ?, CURDATE(), ?, ?, ?, 'draft', NOW(), NOW())"
            )->execute([
                $companyId, $title, $slug, $type, $price, $qty,
                $title . ' — Available Now | ' . $company['name'],
                "{$title} available from {$company['name']}. Book online for pickup or delivery.",
                $photoPath,
            ]);
            header('Location: /hef/admin/storefront.php');
            exit;
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            error_log('manual listing insert failed: ' . $e->getMessage());
            $error = 'Could not create listing.';
        }
    }
}

// --- Edit listing (price, quantity, status) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $storefrontAllowed && isset($_POST['edit_listing_id'])) {
    $id = (int) $_POST['edit_listing_id'];
    $price = (float) ($_POST['price'] ?? 0);
    $qty = (int) ($_POST['quantity_available'] ?? 0);
    $status = $_POST['status'] ?? 'draft';
    $seoTitle = trim($_POST['seo_title'] ?? '');
    $seoDesc = trim($_POST['seo_description'] ?? '');

    if ($price <= 0) {
        $error = 'Price must be greater than zero.';
    } else {
        try {
            $newPhoto = hef_handle_image_upload('photo', 'listings');

            if ($newPhoto) {
                $stmt = $pdo->prepare('SELECT og_image_path FROM storefront_listings WHERE id = ?');
                $stmt->execute([$id]);
                $oldPhoto = $stmt->fetchColumn();

                $pdo->prepare(
                    'UPDATE storefront_listings SET price=?, quantity_available=?, status=?, seo_title=?, seo_description=?, og_image_path=?, updated_at=NOW()
                     WHERE id=? AND company_id=?'
                )->execute([$price, $qty, $status, $seoTitle ?: null, $seoDesc ?: null, $newPhoto, $id, $companyId]);
                hef_delete_uploaded_image($oldPhoto);
            } else {
                $pdo->prepare(
                    'UPDATE storefront_listings SET price=?, quantity_available=?, status=?, seo_title=?, seo_description=?, updated_at=NOW()
                     WHERE id=? AND company_id=?'
                )->execute([$price, $qty, $status, $seoTitle ?: null, $seoDesc ?: null, $id, $companyId]);
            }
            header('Location: /hef/admin/storefront.php');
            exit;
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
}

if (! $storefrontAllowed && $_SERVER['REQUEST_METHOD'] === 'POST' && ! isset($_POST['delete_listing_id'])) {
    $error = 'Your current plan doesn\'t include the online storefront. Upgrade your plan on the Billing page to use it.';
}

require_once __DIR__ . '/header.php';

$perPage = 12;
$page = hef_current_page();
$stmt = $pdo->prepare('SELECT COUNT(*) FROM storefront_listings WHERE company_id = ?');
$stmt->execute([$companyId]);
$totalListings = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT sl.*, b.batch_code, b.photo_path AS batch_photo_path FROM storefront_listings sl
     LEFT JOIN batches b ON b.id = sl.batch_id
     WHERE sl.company_id = ? ORDER BY sl.created_at DESC
     LIMIT {$perPage} OFFSET " . hef_offset($page, $perPage)
);
$stmt->execute([$companyId]);
$listings = $stmt->fetchAll();
foreach ($listings as &$l) {
    $l['display_photo'] = $l['og_image_path'] ?: $l['batch_photo_path'];
}
unset($l);

$storeUrl = "https://cthkennels.com/hef/store.php?company=" . urlencode($company['slug']);
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi bi-shop me-2"></i>Storefront</h4>
    <?php if ($storefrontAllowed): ?>
    <button class="btn btn-hef text-white btn-sm" data-bs-toggle="modal" data-bs-target="#manualListingModal">
        <i class="bi bi-plus-lg me-1"></i>Add egg/meat listing
    </button>
    <?php endif; ?>
</div>

<?php if ($storefrontAllowed): ?>
<div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span>Your public store: <a href="<?= htmlspecialchars($storeUrl) ?>" target="_blank"><?= htmlspecialchars($storeUrl) ?></a></span>
</div>
<?php else: ?>
<div class="alert alert-warning">
    <i class="bi bi-lock me-1"></i>Your current plan doesn't include the online storefront, so your public store and online booking are switched off.
    <a href="/hef/admin/billing.php" class="alert-link">Upgrade your plan</a> to turn them back on. You can still delete existing listings.
</div>
<?php endif; ?>

<?php if ($storefrontAllowed && ! empty($candidateBatches)): ?>
<h6 class="mb-2">Create a listing from an active batch</h6>
<div class="row g-3 mb-4">
    <?php foreach ($candidateBatches as $cb): ?>
        <?php $qty = $cb['current_count_male'] + $cb['current_count_female'] + $cb['current_count_unknown']; ?>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="card p-3">
                <div class="fw-semibold"><?= htmlspecialchars($cb['species_name']) ?></div>
                <div class="text-muted small"><?= htmlspecialchars($cb['batch_code']) ?> · <?= $qty ?> available</div>
                <form method="POST" class="mt-2 d-flex gap-2">
                    <input type="hidden" name="create_from_batch" value="1">
                    <input type="hidden" name="batch_id" value="<?= $cb['id'] ?>">
                    <input type="number" step="0.01" name="price" class="form-control form-control-sm" placeholder="Price ₹" required min="0.01">
                    <button type="submit" class="btn btn-sm btn-hef text-white text-nowrap">List it</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<h6 class="mb-2">Your listings</h6>
<div class="row g-3">
    <?php if (empty($listings)): ?>
        <div class="col-12"><div class="card p-4 text-muted">No listings yet.</div></div>
    <?php endif; ?>
    <?php foreach ($listings as $l): ?>
        <div class="col-12 col-md-6 col-lg-4">
            <div class="card p-3 h-100">
                <?php if ($l['display_photo']): ?>
                    <img src="/hef/<?= htmlspecialchars($l['display_photo']) ?>" alt="" class="rounded mb-2" style="width:100%; height:120px; object-fit:cover;">
                <?php endif; ?>
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="fw-semibold"><?= htmlspecialchars($l['title']) ?></div>
                        <div class="text-muted small text-capitalize"><?= htmlspecialchars($l['type']) ?><?= $l['batch_code'] ? ' · ' . htmlspecialchars($l['batch_code']) : '' ?></div>
                    </div>
                    <span class="badge <?= $l['status'] === 'published' ? 'bg-success-subtle text-success' : ($l['status'] === 'sold_out' ? 'bg-danger-subtle text-danger' : 'bg-secondary-subtle text-secondary') ?>">
                        <?= ucfirst($l['status']) ?>
                    </span>
                </div>
                <div class="mt-2">
                    <span class="fs-5 fw-bold">₹<?= number_format($l['price'], 0) ?></span>
                    <span class="text-muted small"> · <?= (int) $l['quantity_available'] ?> available</span>
                </div>
                <div class="mt-2 d-flex gap-2">
                    <a href="/hef/listing.php?slug=<?= urlencode($l['slug']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary flex-fill">View</a>
                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editListing<?= $l['id'] ?>"><i class="bi bi-pencil"></i></button>
                    <form method="POST" onsubmit="return confirm('Delete this listing?');">
                        <input type="hidden" name="delete_listing_id" value="<?= $l['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="editListing<?= $l['id'] ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="edit_listing_id" value="<?= $l['id'] ?>">
                        <div class="modal-header">
                            <h5 class="modal-title">Edit listing</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                            <div class="mb-2">
                                <label class="form-label small">Photo <?= $l['og_image_path'] ? '(leave blank to keep current)' : '' ?></label>
                                <input type="file" name="photo" class="form-control" accept="image/*">
                            </div>
                            <div class="mb-2">
                                <label class="form-label small">Price (₹)</label>
                                <input type="number" step="0.01" name="price" class="form-control" required value="<?= htmlspecialchars((string) $l['price']) ?>">
                            </div>
                            <div class="mb-2">
                                <label class="form-label small">Quantity available</label>
                                <input type="number" name="quantity_available" class="form-control" value="<?= (int) $l['quantity_available'] ?>" min="0">
                            </div>
                            <div class="mb-2">
                                <label class="form-label small">Status</label>
                                <select name="status" class="form-select">
                                    <?php foreach (['draft', 'published', 'sold_out'] as $st): ?>
                                        <option value="<?= $st ?>" <?= $st === $l['status'] ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-label small">SEO title</label>
                                <input type="text" name="seo_title" class="form-control" value="<?= htmlspecialchars($l['seo_title'] ?? '') ?>">
                            </div>
                            <div class="mb-2">
                                <label class="form-label small">SEO description</label>
                                <textarea name="seo_description" class="form-control" rows="2"><?= htmlspecialchars($l['seo_description'] ?? '') ?></textarea>
                            </div>
                            <?php if ($l['batch_id']): ?>
                                <div class="alert alert-info small py-2 mb-0">Quantity is independent of the batch's live count — update it here if you sell some outside the storefront too.</div>
                            <?php endif; ?>
                        </div>
                        <div class="modal-footer">
                            <button type="submit" class="btn btn-hef text-white w-100">Save changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?= hef_pagination_links($page, $totalListings, $perPage) ?>

<!-- Manual listing modal (egg/meat) -->
<div class="modal fade" id="manualListingModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="create_manual" value="1">
                <div class="modal-header">
                    <h5 class="modal-title">Add egg/meat listing</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <label class="form-label small">Photo</label>
                        <input type="file" name="photo" class="form-control" accept="image/*">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Title</label>
                        <input type="text" name="title" class="form-control" required placeholder="e.g. Fresh Country Eggs (dozen)">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Type</label>
                        <select name="type" class="form-select">
                            <option value="animal">Live animal</option>
                            <option value="egg">Egg</option>
                            <option value="meat">Meat</option>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Price (₹)</label>
                        <input type="number" step="0.01" name="price" class="form-control" required min="0.01">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">Quantity available</label>
                        <input type="number" name="quantity_available" class="form-control" min="0" value="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-hef text-white w-100">Create listing</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
