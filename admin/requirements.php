<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/line_items.php';
require_once __DIR__ . '/../includes/contacts.php';

// Read-only page — no POST handling. Pro-only: company Owner on a Pro
// membership. Workers and vets go to the dashboard; Owners without Pro
// are sent to Billing.
hef_require_pro($pdo, $currentUser);

$companyId = $currentUser['company_id'];

require_once __DIR__ . '/header.php';

// Open demand, one bucket per species + breed + age, with the total quantity
// requested across all customers. A bucket is only matched by supplier
// lines with the same species, breed and age (no breed matches no breed).
$stmt = $pdo->prepare(
    "SELECT s.id AS species_id, s.name AS species_name,
            cs.breed_id, b.name AS breed_name, cs.age_days,
            SUM(cs.quantity) AS total_needed
     FROM customer_species cs
     JOIN customers c ON c.id = cs.customer_id AND c.company_id = ?
     JOIN species s ON s.id = cs.species_id AND s.company_id = ?
     LEFT JOIN breeds b ON b.id = cs.breed_id
     WHERE cs.quantity > 0
     GROUP BY s.id, s.name, cs.breed_id, b.name, cs.age_days
     ORDER BY s.name, b.name, cs.age_days"
);
$stmt->execute([$companyId, $companyId]);
$buckets = $stmt->fetchAll();

// Group the buckets under their species, so each species is one card.
$bucketsBySpecies = [];
foreach ($buckets as $bucket) {
    $bucketsBySpecies[$bucket['species_id']]['name'] = $bucket['species_name'];
    $bucketsBySpecies[$bucket['species_id']]['buckets'][] = $bucket;
}

// Which customers want each bucket, and which suppliers offer it (cheapest
// first) — grouped in PHP to avoid one query per bucket.
$customersByBucket = [];
$supplierLines = [];
if ($buckets) {
    $stmt = $pdo->prepare(
        "SELECT cs.species_id, cs.breed_id, cs.age_days, c.id AS customer_id, c.name, c.phone, cs.quantity
         FROM customer_species cs
         JOIN customers c ON c.id = cs.customer_id
         WHERE c.company_id = ? AND cs.quantity > 0
         ORDER BY cs.quantity DESC"
    );
    $stmt->execute([$companyId]);
    foreach ($stmt->fetchAll() as $row) {
        $customersByBucket[hef_line_key($row['species_id'], $row['breed_id'], $row['age_days'] !== null ? (int) $row['age_days'] : null)][] = $row;
    }

    $stmt = $pdo->prepare(
        "SELECT ss.species_id, ss.breed_id, ss.age_days, sup.id AS supplier_id, sup.name,
                sup.phone, sup.phone2, sup.phone3, sup.phone4, ss.rate, ss.unit, ss.moq
         FROM supplier_species ss
         JOIN suppliers sup ON sup.id = ss.supplier_id
         WHERE sup.company_id = ?
         ORDER BY ss.rate ASC"
    );
    $stmt->execute([$companyId]);
    $supplierLines = $stmt->fetchAll();
}
?>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
        <h4 class="mb-1"><i class="bi bi-clipboard-check me-2"></i>Requirements</h4>
        <p class="text-muted small mb-0">What customers are asking for, by species, breed and age, and which suppliers can fill it — cheapest rate first.</p>
    </div>
    <a href="/hef/admin/export.php?type=requirements" class="btn btn-outline-secondary btn-sm"><i class="bi bi-download me-1"></i>Export CSV</a>
</div>

<?php if (empty($bucketsBySpecies)): ?>
    <div class="card p-4 text-muted">No open customer requirements yet. Add species lines from the Customers page.</div>
<?php endif; ?>

<div class="row g-3">
    <?php $cardIndex = 0; ?>
    <?php foreach ($bucketsBySpecies as $group): ?>
        <?php
            // Each species card gets its own light tint: [background, border].
            $palette = [
                ['#e8f5e9', '#c8e6c9'], // green
                ['#e3f2fd', '#bbdefb'], // blue
                ['#fff8e1', '#ffecb3'], // yellow
                ['#fce4ec', '#f8bbd0'], // pink
                ['#f3e5f5', '#e1bee7'], // purple
                ['#e0f7fa', '#b2ebf2'], // cyan
                ['#fff3e0', '#ffe0b2'], // orange
                ['#f1f8e9', '#dcedc8'], // lime
            ];
            [$cardBg, $cardBorder] = $palette[$cardIndex % count($palette)];
            $cardIndex++;
        ?>
        <div class="col-12 col-lg-6">
            <div class="card p-3 h-100" style="background: <?= $cardBg ?>; border: 1px solid <?= $cardBorder ?>; --bs-border-color: rgba(0,0,0,.12);">
                <h6 class="mb-2"><?= htmlspecialchars($group['name']) ?></h6>

                <?php foreach ($group['buckets'] as $bucket): ?>
                    <?php
                        $age = $bucket['age_days'] !== null ? (int) $bucket['age_days'] : null;
                        $key = hef_line_key($bucket['species_id'], $bucket['breed_id'], $age);
                        $customerRows = $customersByBucket[$key] ?? [];
                        $match = hef_match_suppliers($supplierLines, $bucket['species_id'], $bucket['breed_id'], $age);
                        $supplierRows = $match['rows'];
                        // Pre-filled WhatsApp message sent to each supplier.
                        $needText = (int) $bucket['total_needed'] . ' '
                            . ($bucket['breed_name'] ? $bucket['breed_name'] . ' - ' : '')
                            . $bucket['species_name']
                            . ' (' . ($age !== null ? hef_format_age($age) . ' old' : 'any age') . ')';
                        $supplierMessage = "A Customer is looking for {$needText}.\n"
                            . "Will you be able to supply?\n"
                            . "If yes, what is the rate, delivery cost and delivery time?";
                        $matchMode = $match['mode'];
                        $bucketTitle = ($bucket['breed_name'] ? $bucket['breed_name'] . ' · ' : '') . hef_format_age($age);
                    ?>
                    <div class="border-top pt-2 mt-2">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-semibold"><?= htmlspecialchars($bucketTitle) ?></span>
                            <span class="badge text-bg-hef" style="background:var(--hef-green);">
                                Needed: <?= (int) $bucket['total_needed'] ?>
                            </span>
                        </div>

                        <div class="mb-3">
                            <div class="text-uppercase text-muted small mb-1" style="font-size:11px;">Customers asking</div>
                            <?php if (empty($customerRows)): ?>
                                <div class="text-muted small">None.</div>
                            <?php else: ?>
                                <ul class="list-unstyled small mb-0">
                                    <?php foreach ($customerRows as $cr): ?>
                                        <li class="d-flex justify-content-between align-items-center py-1 border-bottom">
                                            <span><?= htmlspecialchars($cr['name']) ?> — <strong><?= (int) $cr['quantity'] ?></strong></span>
                                            <span><?= hef_contact_buttons($cr['phone']) ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>

                        <div>
                            <div class="text-uppercase text-muted small mb-1" style="font-size:11px;">Suppliers who can fill it</div>
                            <?php if ($matchMode === 'none'): ?>
                                <div class="text-danger small"><i class="bi bi-exclamation-triangle me-1"></i>No supplier on file for this species and breed.</div>
                            <?php else: ?>
                                <?php if ($matchMode === 'any'): ?>
                                    <div class="text-muted small mb-1">Any age — showing this species from all suppliers, at every age:</div>
                                <?php elseif ($matchMode === 'other'): ?>
                                    <div class="text-warning small mb-1"><i class="bi bi-exclamation-triangle me-1"></i>No supplier at exactly <?= htmlspecialchars(hef_format_age($age)) ?>. Suppliers with other ages:</div>
                                <?php endif; ?>
                                <div class="row row-cols-1 row-cols-md-2 g-2">
                                    <?php foreach ($supplierRows as $i => $sr): ?>
                                        <?php
                                            $srAge = $sr['age_days'] !== null ? (int) $sr['age_days'] : null;
                                            $srNumbers = array_values(array_filter([$sr['phone'], $sr['phone2'] ?? null, $sr['phone3'] ?? null, $sr['phone4'] ?? null]));
                                        ?>
                                        <div class="col">
                                            <div class="border rounded p-2 h-100 bg-white small">
                                                <div class="fw-semibold" style="overflow-wrap:anywhere;">
                                                    <?php if ($matchMode === 'exact' && $i === 0): ?><i class="bi bi-star-fill text-warning me-1" title="Cheapest"></i><?php endif; ?>
                                                    <?= htmlspecialchars($sr['name']) ?>
                                                </div>
                                                <div class="text-muted mb-1">
                                                    ₹<?= htmlspecialchars($sr['rate']) ?><?= $sr['unit'] ? '/' . htmlspecialchars($sr['unit']) : '' ?>
                                                    (MOQ <?= (int) $sr['moq'] ?>)
                                                    <?php if ($matchMode !== 'exact' || $srAge === null): ?>
                                                        <span class="badge bg-light text-dark border ms-1"><?= htmlspecialchars(hef_format_age($srAge)) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if (! $srNumbers): ?>
                                                    <div class="text-muted">No phone number</div>
                                                <?php else: ?>
                                                    <?php foreach ($srNumbers as $srNum): ?>
                                                        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap py-1 border-top">
                                                            <span class="text-nowrap"><i class="bi bi-telephone me-1 text-muted"></i><?= htmlspecialchars(hef_format_phone($srNum)) ?></span>
                                                            <span class="text-nowrap"><?= hef_contact_buttons($srNum, $supplierMessage) ?></span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>