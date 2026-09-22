<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/audit.php';

// View is open to everyone; only Owner can add/edit/delete (every write
// on this page goes through POST).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $currentUser['role'] !== 'owner') {
    header('Location: /hef/admin/species.php?error=access_denied');
    exit;
}

$companyId = $currentUser['company_id'];
$error = '';

// Breeds only matter to the Pro pages (Customers / Suppliers / Requirements),
// so managing them is limited to Owners on a Pro membership.
$canManageBreeds = hef_user_can_access_pro_pages($pdo, $currentUser);
$breedsModalSpecies = 0; // species whose breeds modal should reopen (and show $error)

/** Back to the species list, reopening the breeds modal we were just in. */
function hef_breeds_redirect(int $speciesId): void
{
    header('Location: /hef/admin/species.php?page=' . hef_current_page() . '&open_breeds=' . $speciesId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_breed_species_id']) || isset($_POST['edit_breed_id']) || isset($_POST['delete_breed_id']))) {
    if (! $canManageBreeds) {
        header('Location: /hef/admin/species.php?error=access_denied');
        exit;
    }

    if (isset($_POST['add_breed_species_id'])) {
        $speciesId = (int) $_POST['add_breed_species_id'];
        $breedName = trim($_POST['breed_name'] ?? '');
        $breedsModalSpecies = $speciesId;

        $stmt = $pdo->prepare('SELECT id FROM species WHERE id = ? AND company_id = ?');
        $stmt->execute([$speciesId, $companyId]);

        if (! $stmt->fetchColumn()) {
            $breedsModalSpecies = 0;
            $error = 'Species not found.';
        } elseif ($breedName === '' || (function_exists('mb_strlen') ? mb_strlen($breedName) : strlen($breedName)) > 100) {
            $error = 'Enter a breed name (up to 100 characters).';
        } else {
            try {
                $pdo->prepare(
                    'INSERT INTO breeds (company_id, species_id, name, created_at, updated_at)
                     VALUES (?, ?, ?, NOW(), NOW())'
                )->execute([$companyId, $speciesId, $breedName]);
                hef_breeds_redirect($speciesId);
            } catch (PDOException $e) {
                error_log('breed insert failed: ' . $e->getMessage());
                $error = $e->getCode() === '23000'
                    ? 'That breed already exists for this species.'
                    : 'Could not save breed. Please try again.';
            }
        }
    } elseif (isset($_POST['edit_breed_id'])) {
        // Rename only. Customer / supplier lines and batches point at the breed
        // by id, so they pick up the new name automatically.
        $breedId = (int) $_POST['edit_breed_id'];
        $breedName = trim($_POST['breed_name'] ?? '');

        $stmt = $pdo->prepare('SELECT id, species_id FROM breeds WHERE id = ? AND company_id = ?');
        $stmt->execute([$breedId, $companyId]);
        $breed = $stmt->fetch();

        if (! $breed) {
            $error = 'Breed not found.';
        } else {
            $breedsModalSpecies = (int) $breed['species_id'];
            if ($breedName === '' || (function_exists('mb_strlen') ? mb_strlen($breedName) : strlen($breedName)) > 100) {
                $error = 'Enter a breed name (up to 100 characters).';
            } else {
                try {
                    $pdo->prepare('UPDATE breeds SET name = ?, updated_at = NOW() WHERE id = ? AND company_id = ?')
                        ->execute([$breedName, $breedId, $companyId]);
                    hef_breeds_redirect((int) $breed['species_id']);
                } catch (PDOException $e) {
                    error_log('breed update failed: ' . $e->getMessage());
                    $error = $e->getCode() === '23000'
                        ? 'That breed already exists for this species.'
                        : 'Could not rename breed. Please try again.';
                }
            }
        }
    } else {
        $breedId = (int) $_POST['delete_breed_id'];
        $stmt = $pdo->prepare('SELECT id, species_id FROM breeds WHERE id = ? AND company_id = ?');
        $stmt->execute([$breedId, $companyId]);
        $breed = $stmt->fetch();

        if (! $breed) {
            $error = 'Breed not found.';
        } else {
            $breedsModalSpecies = (int) $breed['species_id'];
            // Deleting a breed would silently delete the customer / supplier
            // lines that use it and blank it on batches, so refuse while any
            // still do.
            $stmt = $pdo->prepare(
                'SELECT (SELECT COUNT(*) FROM customer_species WHERE breed_id = ?)
                      + (SELECT COUNT(*) FROM supplier_species WHERE breed_id = ?)
                      + (SELECT COUNT(*) FROM batches WHERE breed_id = ?)'
            );
            $stmt->execute([$breedId, $breedId, $breedId]);
            if ((int) $stmt->fetchColumn() > 0) {
                $error = 'Cannot delete — this breed is still used by customer, supplier or batch records. Remove or change those first.';
            } else {
                try {
                    $pdo->prepare('DELETE FROM breeds WHERE id = ? AND company_id = ?')->execute([$breedId, $companyId]);
                    hef_breeds_redirect((int) $breed['species_id']);
                } catch (PDOException $e) {
                    error_log('breed delete failed: ' . $e->getMessage());
                    $error = 'Could not delete breed.';
                }
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_species_id'])) {
    $id = (int) $_POST['delete_species_id'];
    try {
        $stmt = $pdo->prepare('SELECT * FROM species WHERE id = ? AND company_id = ?');
        $stmt->execute([$id, $companyId]);
        $oldRow = $stmt->fetch();

        $pdo->prepare('DELETE FROM species WHERE id = ? AND company_id = ?')->execute([$id, $companyId]);
        if ($oldRow) {
            hef_audit_log($pdo, $companyId, $currentUser['user_id'], 'species', $id, 'delete', $oldRow, null);
        }
        header('Location: /hef/admin/species.php');
        exit;
    } catch (PDOException $e) {
        error_log('species delete failed: ' . $e->getMessage());
        $error = str_contains($e->getMessage(), 'foreign key') || str_contains($e->getMessage(), 'a foreign key constraint fails')
            ? 'Cannot delete — this species still has batches, vaccination schedules, or feed types linked to it.'
            : 'Could not delete species.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_species_id'])) {
    $id = (int) $_POST['edit_species_id'];
    $name = trim($_POST['name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $maxDays = $_POST['max_days_to_sell'] !== '' ? (int) $_POST['max_days_to_sell'] : null;
    $weight = $_POST['typical_mature_weight_kg'] !== '' ? (float) $_POST['typical_mature_weight_kg'] : null;

    if (! $name) {
        $error = 'Species name is required.';
    } else {
        try {
            $stmt = $pdo->prepare('SELECT * FROM species WHERE id = ? AND company_id = ?');
            $stmt->execute([$id, $companyId]);
            $oldRow = $stmt->fetch();

            $stmt = $pdo->prepare(
                'UPDATE species SET name = ?, category = ?, max_days_to_sell = ?, typical_mature_weight_kg = ?, updated_at = NOW()
                 WHERE id = ? AND company_id = ?'
            );
            $stmt->execute([$name, $category ?: null, $maxDays, $weight, $id, $companyId]);
            if ($oldRow) {
                hef_audit_log($pdo, $companyId, $currentUser['user_id'], 'species', $id, 'update', $oldRow, [
                    'name' => $name, 'category' => $category ?: null, 'max_days_to_sell' => $maxDays, 'typical_mature_weight_kg' => $weight,
                ]);
            }
            header('Location: /hef/admin/species.php');
            exit;
        } catch (PDOException $e) {
            error_log('species update failed: ' . $e->getMessage());
            $error = 'Could not update species. Please try again.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $maxDays = $_POST['max_days_to_sell'] !== '' ? (int) $_POST['max_days_to_sell'] : null;
    $weight = $_POST['typical_mature_weight_kg'] !== '' ? (float) $_POST['typical_mature_weight_kg'] : null;

    if (! $name) {
        $error = 'Species name is required.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO species (company_id, name, category, max_days_to_sell, typical_mature_weight_kg, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
            );
            $stmt->execute([$companyId, $name, $category ?: null, $maxDays, $weight]);
            header('Location: /hef/admin/species.php');
            exit;
        } catch (PDOException $e) {
            error_log('species insert failed: ' . $e->getMessage());
            $error = 'Could not save species. Please check the values and try again.';
        }
    }
}

require_once __DIR__ . '/header.php';

$perPage = 10;
$page = hef_current_page();
$stmt = $pdo->prepare('SELECT COUNT(*) FROM species WHERE company_id = ?');
$stmt->execute([$companyId]);
$totalSpecies = (int) $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT * FROM species WHERE company_id = ? ORDER BY name LIMIT ' . $perPage . ' OFFSET ' . hef_offset($page, $perPage));
$stmt->execute([$companyId]);
$speciesList = $stmt->fetchAll();

// Breeds per species, for the badges and the "Breeds" modal.
$breedsBySpecies = [];
if ($canManageBreeds && $speciesList) {
    $stmt = $pdo->prepare('SELECT id, species_id, name FROM breeds WHERE company_id = ? ORDER BY name');
    $stmt->execute([$companyId]);
    foreach ($stmt->fetchAll() as $b) {
        $breedsBySpecies[$b['species_id']][] = $b;
    }
}
?>
<h4 class="mb-3"><i class="bi bi-list-ul me-2"></i>Species</h4>

<div class="row g-3">
    <?php if ($currentUser['role'] === 'owner'): ?>
    <div class="col-12 col-lg-5">
        <div class="card p-3">
            <h6 class="mb-3">Add species</h6>
            <?php if ($error && ! $breedsModalSpecies): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <form method="POST">
                <div class="mb-2">
                    <label class="form-label small">Name</label>
                    <input type="text" name="name" class="form-control" required placeholder="e.g. Chicken">
                </div>
                <div class="mb-2">
                    <label class="form-label small">Category</label>
                    <input type="text" name="category" class="form-control" placeholder="e.g. Poultry">
                </div>
                <div class="mb-2">
                    <label class="form-label small">Max days to sell</label>
                    <input type="number" name="max_days_to_sell" class="form-control" placeholder="e.g. 90">
                </div>
                <div class="mb-3">
                    <label class="form-label small">Typical mature weight (kg)</label>
                    <input type="number" step="0.01" name="typical_mature_weight_kg" class="form-control" placeholder="e.g. 2.5">
                </div>
                <button type="submit" class="btn btn-hef text-white w-100">Add species</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="list-group list-group-flush">
                <?php if (empty($speciesList)): ?>
                    <div class="list-group-item text-muted">No species added yet.</div>
                <?php endif; ?>
                <?php foreach ($speciesList as $s): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-semibold"><?= htmlspecialchars($s['name']) ?></div>
                            <div class="text-muted small">
                                <?= htmlspecialchars($s['category'] ?? '—') ?>
                                <?php if ($s['max_days_to_sell']): ?> · sell by <?= (int) $s['max_days_to_sell'] ?> days<?php endif; ?>
                            </div>
                            <?php if (! empty($breedsBySpecies[$s['id']])): ?>
                                <div class="mt-1">
                                    <?php foreach ($breedsBySpecies[$s['id']] as $b): ?>
                                        <span class="badge bg-light text-dark border me-1"><?= htmlspecialchars($b['name']) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($currentUser['role'] === 'owner'): ?>
                        <div class="d-flex gap-1">
                            <?php if ($canManageBreeds): ?>
                            <button class="btn btn-sm btn-outline-secondary" title="Breeds" data-bs-toggle="modal" data-bs-target="#breedsSpecies<?= $s['id'] ?>">
                                <i class="bi bi-tags"></i>
                            </button>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editSpecies<?= $s['id'] ?>">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" onsubmit="return confirm('Delete this species? Only possible if no batches, schedules or feed types reference it.');" class="d-inline">
                                <input type="hidden" name="delete_species_id" value="<?= $s['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Breeds modal for this species -->
                    <?php if ($canManageBreeds): ?>
                    <div class="modal fade" id="breedsSpecies<?= $s['id'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">Breeds — <?= htmlspecialchars($s['name']) ?></h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <?php if ($error && $breedsModalSpecies === (int) $s['id']): ?>
                                        <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
                                    <?php endif; ?>
                                    <?php if (empty($breedsBySpecies[$s['id']])): ?>
                                        <div class="text-muted small mb-3">No breeds yet. Leave a species without breeds if it doesn't need them (e.g. turkey).</div>
                                    <?php else: ?>
                                        <ul class="list-group mb-1">
                                            <?php foreach ($breedsBySpecies[$s['id']] as $b): ?>
                                                <li class="list-group-item d-flex align-items-center gap-2 py-2">
                                                    <form method="POST" class="d-flex gap-2 flex-grow-1">
                                                        <input type="hidden" name="edit_breed_id" value="<?= (int) $b['id'] ?>">
                                                        <input type="text" name="breed_name" value="<?= htmlspecialchars($b['name']) ?>" maxlength="100" required
                                                            class="form-control form-control-sm" aria-label="Breed name">
                                                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Save name"><i class="bi bi-check-lg"></i></button>
                                                    </form>
                                                    <form method="POST" onsubmit="return confirm('Delete this breed?');" class="d-inline">
                                                        <input type="hidden" name="delete_breed_id" value="<?= (int) $b['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                                    </form>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                        <div class="form-text mb-3">To rename a breed, change the name and press <i class="bi bi-check-lg"></i>. Existing customers, suppliers and batches keep the breed under its new name.</div>
                                    <?php endif; ?>
                                    <form method="POST" class="d-flex gap-2">
                                        <input type="hidden" name="add_breed_species_id" value="<?= (int) $s['id'] ?>">
                                        <input type="text" name="breed_name" maxlength="100" class="form-control" required placeholder="e.g. Sonali">
                                        <button type="submit" class="btn btn-hef text-white">Add</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Edit modal for this species -->
                    <?php if ($currentUser['role'] === 'owner'): ?>
                    <div class="modal fade" id="editSpecies<?= $s['id'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <form method="POST">
                                    <input type="hidden" name="edit_species_id" value="<?= $s['id'] ?>">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Edit species</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="mb-2">
                                            <label class="form-label small">Name</label>
                                            <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($s['name']) ?>">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small">Category</label>
                                            <input type="text" name="category" class="form-control" value="<?= htmlspecialchars($s['category'] ?? '') ?>">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small">Max days to sell</label>
                                            <input type="number" name="max_days_to_sell" class="form-control" value="<?= htmlspecialchars((string) $s['max_days_to_sell']) ?>">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small">Typical mature weight (kg)</label>
                                            <input type="number" step="0.01" name="typical_mature_weight_kg" class="form-control" value="<?= htmlspecialchars((string) $s['typical_mature_weight_kg']) ?>">
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="submit" class="btn btn-hef text-white w-100">Save changes</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?= hef_pagination_links($page, $totalSpecies, $perPage) ?>
    </div>
</div>

<?php if ($canManageBreeds): ?>
<script>
// Reopen the breeds modal after adding/removing a breed (or after an error),
// so several breeds can be added in a row.
window.addEventListener('load', function () {
    var id = <?= $breedsModalSpecies ?: (int) ($_GET['open_breeds'] ?? 0) ?>;
    var el = id ? document.getElementById('breedsSpecies' + id) : null;
    if (el) { new bootstrap.Modal(el).show(); }
});
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
