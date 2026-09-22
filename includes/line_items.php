<?php
/**
 * Shared helpers for the repeatable "species + breed + age" lines used by
 * the Customers and Suppliers pages, and for how those lines are labelled
 * on the Requirements page.
 *
 * A line is one combination of species, an optional breed, and an optional
 * age. Ages are stored as whole days and entered as a number plus a unit
 * (days / months / years). A month is 30 days and a year is 365 days.
 * NULL breed means "no breed", NULL age means "any age" — which is also how
 * rows created before breeds/ages existed are treated.
 *
 * Kept PHP 7.4 compatible.
 */

const HEF_AGE_UNITS = ['days' => 1, 'months' => 30, 'years' => 365];

/** Converts a typed age (e.g. 6 + "months") to days, or null if blank/invalid. */
function hef_age_to_days($value, $unit): ?int
{
    if ($value === '' || $value === null || ! is_numeric($value) || (float) $value <= 0) {
        return null;
    }
    $multiplier = HEF_AGE_UNITS[$unit] ?? 1;

    return (int) min(round((float) $value * $multiplier), 36500);
}

/** Best-fit [value, unit] for pre-filling the form from a stored day count. */
function hef_age_parts(?int $days): array
{
    if ($days === null) {
        return ['', 'days'];
    }
    if ($days % 365 === 0) {
        return [(int) ($days / 365), 'years'];
    }
    if ($days >= 60 && $days % 30 === 0) {
        return [(int) ($days / 30), 'months'];
    }

    return [$days, 'days'];
}

/** "1 day", "30 days", "6 months", "1 year", or "any age". */
function hef_format_age(?int $days): string
{
    if ($days === null) {
        return 'any age';
    }
    [$number, $unit] = hef_age_parts($days);
    if ($number === 1) {
        $unit = rtrim($unit, 's');
    }

    return $number . ' ' . $unit;
}

/** "Chicken · Sonali · 1 day" (breed omitted when there is none). */
function hef_line_label(string $species, ?string $breed, ?int $ageDays): string
{
    $parts = [$species];
    if ($breed !== null && $breed !== '') {
        $parts[] = $breed;
    }
    $parts[] = hef_format_age($ageDays);

    return implode(' · ', $parts);
}

/**
 * Key used to match a customer line to supplier lines: species, breed and
 * age must all be equal (no breed only matches no breed, and so on).
 */
function hef_line_key($speciesId, $breedId, $ageDays): string
{
    return (int) $speciesId . ':'
        . ($breedId === null ? 0 : (int) $breedId) . ':'
        . ($ageDays === null ? 'x' : (int) $ageDays);
}

/** "Chicken · Sonali", or just "Chicken" when there is no breed. */
function hef_species_breed_label(string $species, ?string $breed): string
{
    return ($breed !== null && $breed !== '') ? $species . ' · ' . $breed : $species;
}

/**
 * Returns $breedId only when it is one of this company's breeds for
 * $speciesId, otherwise null (no breed). Guards against a tampered or
 * stale form pairing a breed with the wrong species.
 */
function hef_resolve_breed_id($breedId, int $speciesId, array $breedList): ?int
{
    $breedId = (int) $breedId;
    if ($breedId <= 0) {
        return null;
    }
    foreach ($breedList as $b) {
        if ((int) $b['id'] === $breedId && (int) $b['species_id'] === $speciesId) {
            return $breedId;
        }
    }

    return null;
}

/**
 * A single breed dropdown (used on the batch forms), pre-filtered to the
 * chosen species. Pair it with a species <select class="hef-species-select">
 * in the same form and print hef_breed_select_script() once on the page.
 */
function hef_render_breed_select(array $breedList, int $speciesId, ?int $selectedBreedId): void
{
    ?>
    <select name="breed_id" class="form-select hef-breed-select">
        <option value="">No breed</option>
        <?php foreach ($breedList as $b): ?>
            <?php if ((int) $b['species_id'] !== $speciesId) { continue; } ?>
            <option value="<?= (int) $b['id'] ?>" <?= $selectedBreedId === (int) $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php
}

/** Keeps each form's breed dropdown in step with its species dropdown. Print once per page. */
function hef_breed_select_script(array $breedList): void
{
    $breeds = [];
    foreach ($breedList as $b) {
        $breeds[] = ['id' => (int) $b['id'], 'species_id' => (int) $b['species_id'], 'name' => $b['name']];
    }
    $json = json_encode($breeds, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>
<script>
(function () {
    var BREEDS = <?= $json ?>;
    document.addEventListener('change', function (e) {
        if (!e.target.classList.contains('hef-species-select')) { return; }
        var form = e.target.closest('form');
        var breed = form ? form.querySelector('.hef-breed-select') : null;
        if (!breed) { return; }
        breed.innerHTML = '';
        var none = document.createElement('option');
        none.value = '';
        none.textContent = 'No breed';
        breed.appendChild(none);
        BREEDS.forEach(function (b) {
            if (String(b.species_id) === e.target.value) {
                var opt = document.createElement('option');
                opt.value = b.id;
                opt.textContent = b.name;
                breed.appendChild(opt);
            }
        });
    });
})();
</script>
    <?php
}

/**
 * Which supplier lines can fill a demand bucket (species + breed + age)?
 * Species and breed must match exactly (no breed only matches no breed).
 * Age is matched like this:
 *
 *  - Demand with no age ("any age"): every supplier line for that species
 *    and breed, at all ages.                                      mode "any"
 *  - Demand with an age: supplier lines of exactly that age, plus lines
 *    with no age (a supplier who sells "any age" can fill it).   mode "exact"
 *  - If there are none of those: the same species and breed at other ages,
 *    as a fallback so there is always something to work from.     mode "other"
 *  - Nothing for that species and breed at all.                    mode "none"
 *
 * "exact" rows are cheapest first. "any" and "other" rows are ordered by age
 * (youngest first, no-age last) then rate, because prices at different ages
 * aren't comparable.
 *
 * $supplierLines: rows with species_id, breed_id, age_days, rate (and
 * whatever else the caller wants back).
 *
 * @return array{mode: string, rows: array}
 */
function hef_match_suppliers(array $supplierLines, $speciesId, $breedId, ?int $ageDays): array
{
    $speciesId = (int) $speciesId;
    $breedId = $breedId === null ? null : (int) $breedId;

    $candidates = [];
    foreach ($supplierLines as $row) {
        if ((int) $row['species_id'] !== $speciesId) {
            continue;
        }
        $rowBreed = $row['breed_id'] === null ? null : (int) $row['breed_id'];
        if ($rowBreed === $breedId) {
            $candidates[] = $row;
        }
    }
    if (! $candidates) {
        return ['mode' => 'none', 'rows' => []];
    }

    if ($ageDays === null) {
        $mode = 'any';
        $rows = $candidates;
    } else {
        $rows = [];
        foreach ($candidates as $row) {
            $rowAge = $row['age_days'] === null ? null : (int) $row['age_days'];
            if ($rowAge === null || $rowAge === $ageDays) {
                $rows[] = $row;
            }
        }
        if ($rows) {
            $mode = 'exact';
        } else {
            $mode = 'other';
            $rows = $candidates;
        }
    }

    usort($rows, function ($a, $b) use ($mode) {
        if ($mode !== 'exact') {
            $ageA = $a['age_days'] === null ? PHP_INT_MAX : (int) $a['age_days'];
            $ageB = $b['age_days'] === null ? PHP_INT_MAX : (int) $b['age_days'];
            if ($ageA !== $ageB) {
                return $ageA <=> $ageB;
            }
        }

        return (float) $a['rate'] <=> (float) $b['rate'];
    });

    return ['mode' => $mode, 'rows' => $rows];
}

/**
 * Replaces a supplier's offer lines with the given rows (species, optional
 * breed, optional age in days, rate, unit "kg"/"piece", minimum order
 * quantity). Simple delete-then-reinsert inside a transaction — fine at
 * this scale, avoids diffing. $rows comes from hef_parse_lines(), which
 * already drops lines without a rate and defaults MOQ to 1.
 */
function hef_sync_supplier_lines(PDO $pdo, int $supplierId, array $rows): void
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM supplier_species WHERE supplier_id = ?')->execute([$supplierId]);
        $stmt = $pdo->prepare(
            'INSERT INTO supplier_species (supplier_id, species_id, breed_id, age_days, rate, unit, moq, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        foreach ($rows as $row) {
            $stmt->execute([
                $supplierId, $row['species_id'], $row['breed_id'], $row['age_days'],
                $row['rate'], $row['unit'], $row['moq'],
            ]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Replaces a customer's requirement lines with the given rows (species,
 * optional breed, optional age in days, quantity). Simple delete-then-
 * reinsert inside a transaction — fine at this scale, avoids diffing.
 * $rows comes from hef_parse_lines(), which already drops blank lines.
 */
function hef_sync_customer_lines(PDO $pdo, int $customerId, array $rows): void
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM customer_species WHERE customer_id = ?')->execute([$customerId]);
        $stmt = $pdo->prepare(
            'INSERT INTO customer_species (customer_id, species_id, breed_id, age_days, quantity, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
        );
        foreach ($rows as $row) {
            $stmt->execute([$customerId, $row['species_id'], $row['breed_id'], $row['age_days'], $row['quantity']]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** This company's species and breeds, for the line editors. */
function hef_load_species_breeds(PDO $pdo, int $companyId): array
{
    $stmt = $pdo->prepare('SELECT id, name FROM species WHERE company_id = ? ORDER BY name');
    $stmt->execute([$companyId]);
    $species = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT id, species_id, name FROM breeds WHERE company_id = ? ORDER BY name');
    $stmt->execute([$companyId]);
    $breeds = $stmt->fetchAll();

    return [$species, $breeds];
}

/**
 * Cleans posted lines ($_POST['lines']) into rows ready to insert.
 *
 * $mode 'customer' -> species_id, breed_id, age_days, quantity
 * $mode 'supplier' -> species_id, breed_id, age_days, rate, unit, moq
 *
 * Species and breed are checked against this company's own lists, so a
 * tampered request can't attach another company's species. Blank lines
 * are skipped. Two lines with the same species + breed + age are merged
 * for customers (quantities add up) and rejected for suppliers (there is
 * no sensible way to merge two rates).
 *
 * @return array{rows: array, error: string}
 */
function hef_parse_lines($raw, string $mode, array $speciesList, array $breedList): array
{
    $rows = [];
    $error = '';
    if (! is_array($raw)) {
        return ['rows' => [], 'error' => ''];
    }

    $speciesNames = [];
    foreach ($speciesList as $sp) {
        $speciesNames[(int) $sp['id']] = $sp['name'];
    }
    $breedMap = [];
    foreach ($breedList as $b) {
        $breedMap[(int) $b['id']] = ['species_id' => (int) $b['species_id'], 'name' => $b['name']];
    }

    foreach ($raw as $line) {
        if (! is_array($line)) {
            continue;
        }
        $speciesId = (int) ($line['species_id'] ?? 0);
        if (! isset($speciesNames[$speciesId])) {
            continue;
        }

        $breedId = (int) ($line['breed_id'] ?? 0);
        if ($breedId <= 0 || ! isset($breedMap[$breedId]) || $breedMap[$breedId]['species_id'] !== $speciesId) {
            $breedId = null;
        }

        $ageDays = hef_age_to_days($line['age_value'] ?? '', (string) ($line['age_unit'] ?? 'days'));
        $key = hef_line_key($speciesId, $breedId, $ageDays);

        if ($mode === 'customer') {
            $quantity = (int) ($line['quantity'] ?? 0);
            if ($quantity <= 0) {
                continue;
            }
            if (isset($rows[$key])) {
                $rows[$key]['quantity'] += $quantity;
            } else {
                $rows[$key] = [
                    'species_id' => $speciesId, 'breed_id' => $breedId,
                    'age_days' => $ageDays, 'quantity' => $quantity,
                ];
            }
        } else {
            $rate = $line['rate'] ?? '';
            if ($rate === '' || ! is_numeric($rate) || (float) $rate <= 0) {
                continue;
            }
            if (isset($rows[$key])) {
                $label = hef_line_label(
                    $speciesNames[$speciesId],
                    $breedId !== null ? $breedMap[$breedId]['name'] : null,
                    $ageDays
                );
                $error = 'Duplicate line: ' . $label . '. Each species, breed and age can only appear once.';
                continue;
            }

            $unit = trim((string) ($line['unit'] ?? ''));
            $unit = in_array($unit, ['kg', 'piece'], true) ? $unit : 'kg';
            $moq = $line['moq'] ?? '';
            $moq = ($moq !== '' && is_numeric($moq) && (int) $moq > 0) ? (int) $moq : 1;

            $rows[$key] = [
                'species_id' => $speciesId, 'breed_id' => $breedId, 'age_days' => $ageDays,
                'rate' => (float) $rate, 'unit' => $unit, 'moq' => $moq,
            ];
        }
    }

    return ['rows' => array_values($rows), 'error' => $error];
}

/**
 * Keeps the species and breeds last handed to hef_render_line_editor(), so
 * hef_line_editor_script() can give them to the browser without every page
 * passing them twice.
 */
function hef_line_editor_data(?array $species = null, ?array $breeds = null): array
{
    static $data = ['species' => [], 'breeds' => []];
    if ($species !== null) {
        $data['species'] = $species;
    }
    if ($breeds !== null) {
        $data['breeds'] = $breeds;
    }

    return $data;
}

/** "1 day", "6 months" from the typed age fields, or "any age". */
function hef_line_age_text($value, $unit): string
{
    $value = trim((string) $value);
    if ($value === '' || ! is_numeric($value) || (float) $value <= 0) {
        return 'any age';
    }
    $unit = isset(HEF_AGE_UNITS[$unit]) ? $unit : 'days';
    if ((float) $value === 1.0) {
        $unit = rtrim($unit, 's');
    }

    return $value . ' ' . $unit;
}

/** [title, detail] shown for one line, e.g. ["Chicken · Sonali · 1 day", "₹25/piece · MOQ 1"]. */
function hef_line_summary(string $mode, array $line, array $speciesNames, array $breedNames): array
{
    $parts = [$speciesNames[(int) ($line['species_id'] ?? 0)] ?? '?'];
    $breedId = (int) ($line['breed_id'] ?? 0);
    if ($breedId > 0 && isset($breedNames[$breedId])) {
        $parts[] = $breedNames[$breedId];
    }
    $parts[] = hef_line_age_text($line['age_value'] ?? '', $line['age_unit'] ?? 'days');

    if ($mode === 'customer') {
        $detail = 'Need ' . (int) ($line['quantity'] ?? 0);
    } else {
        $rate = rtrim(rtrim(number_format((float) ($line['rate'] ?? 0), 2, '.', ''), '0'), '.');
        $unit = ($line['unit'] ?? 'kg') === 'piece' ? 'piece' : 'kg';
        $detail = '₹' . $rate . '/' . $unit . ' · MOQ ' . max(1, (int) ($line['moq'] ?? 1));
    }

    return [implode(' · ', $parts), $detail];
}

/** One line in the list: a summary with edit and remove buttons, plus the hidden fields that get posted. */
function hef_render_line_item(string $mode, string $idx, array $line, array $speciesNames, array $breedNames): string
{
    [$title, $detail] = hef_line_summary($mode, $line, $speciesNames, $breedNames);
    $fields = $mode === 'customer'
        ? ['species_id', 'breed_id', 'age_value', 'age_unit', 'quantity']
        : ['species_id', 'breed_id', 'age_value', 'age_unit', 'rate', 'unit', 'moq'];
    $name = 'lines[' . htmlspecialchars($idx) . ']';

    ob_start();
    ?>
    <div class="hef-line-item d-flex align-items-center gap-2 border rounded px-2 py-2 mb-2 bg-white">
        <div class="flex-grow-1" style="min-width:0;">
            <div class="fw-semibold hef-li-title" style="overflow-wrap:anywhere;"><?= htmlspecialchars($title) ?></div>
            <div class="small text-muted hef-li-sub"><?= htmlspecialchars($detail) ?></div>
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary hef-line-edit" title="Edit"><i class="bi bi-pencil"></i></button>
        <button type="button" class="btn btn-sm btn-outline-danger hef-line-remove" title="Remove"><i class="bi bi-x-lg"></i></button>
        <?php foreach ($fields as $f): ?>
            <input type="hidden" data-f="<?= $f ?>" name="<?= $name ?>[<?= $f ?>]" value="<?= htmlspecialchars((string) ($line[$f] ?? '')) ?>">
        <?php endforeach; ?>
    </div>
    <?php

    return (string) ob_get_clean();
}

/** The floating dialog used to add or edit one line. Filled and shown by the script below. */
function hef_line_dialog_html(): string
{
    ob_start();
    ?>
    <div class="hef-float" hidden>
        <div class="hef-float-backdrop hef-dlg-close"></div>
        <div class="hef-float-card card shadow-lg" role="dialog" aria-modal="true">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <strong class="hef-dlg-title">Add species</strong>
                <button type="button" class="btn-close hef-dlg-close" aria-label="Close"></button>
            </div>
            <div class="card-body">
                <div class="mb-2">
                    <label class="form-label small mb-1">Species</label>
                    <select class="form-select hef-dlg-species"></select>
                </div>
                <div class="mb-2">
                    <label class="form-label small mb-1">Breed <span class="text-muted">(optional)</span></label>
                    <select class="form-select hef-dlg-breed"></select>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-6">
                        <label class="form-label small mb-1">Age <span class="text-muted">(optional)</span></label>
                        <input type="text" inputmode="decimal" maxlength="3" autocomplete="off" class="form-control hef-decimal hef-dlg-age" placeholder="e.g. 30">
                    </div>
                    <div class="col-6">
                        <label class="form-label small mb-1">&nbsp;</label>
                        <select class="form-select hef-dlg-age-unit">
                            <?php foreach (array_keys(HEF_AGE_UNITS) as $u): ?><option value="<?= $u ?>"><?= $u ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mb-2" data-for="customer">
                    <label class="form-label small mb-1">How many do you need?</label>
                    <input type="text" inputmode="numeric" maxlength="5" autocomplete="off" class="form-control hef-digits hef-dlg-qty" placeholder="e.g. 100">
                </div>
                <div class="row g-2 mb-2" data-for="supplier">
                    <div class="col-5">
                        <label class="form-label small mb-1">Rate (₹)</label>
                        <input type="text" inputmode="decimal" maxlength="8" autocomplete="off" class="form-control hef-decimal hef-dlg-rate" placeholder="e.g. 25">
                    </div>
                    <div class="col-4">
                        <label class="form-label small mb-1">Per</label>
                        <select class="form-select hef-dlg-unit"><option value="piece">piece</option><option value="kg">kg</option></select>
                    </div>
                    <div class="col-3">
                        <label class="form-label small mb-1">MOQ</label>
                        <input type="text" inputmode="numeric" maxlength="5" autocomplete="off" class="form-control hef-digits hef-dlg-moq" placeholder="1">
                    </div>
                </div>
                <div class="text-danger small hef-dlg-error"></div>
            </div>
            <div class="card-footer bg-white d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary flex-fill hef-dlg-close">Cancel</button>
                <button type="button" class="btn btn-hef text-white flex-fill hef-dlg-save">Add</button>
            </div>
        </div>
    </div>
    <?php

    return (string) ob_get_clean();
}

/**
 * The species editor for a customer's requirements or a supplier's products:
 * a tidy list of what has been added, with an "Add species" button that opens
 * a floating dialog. Each line is kept in hidden fields (lines[N][...]) that
 * are posted with the form. $lines use the form-field shape (species_id,
 * breed_id, age_value, age_unit, quantity | rate, unit, moq). Behaviour comes
 * from hef_line_editor_script(), printed once per page.
 */
function hef_render_line_editor(string $mode, array $lines, array $speciesList, array $breedList): void
{
    hef_line_editor_data($speciesList, $breedList);

    if (empty($speciesList)) {
        echo '<div class="text-muted small">Add species first.</div>';

        return;
    }

    $speciesNames = array_column($speciesList, 'name', 'id');
    $breedNames = array_column($breedList, 'name', 'id');

    $items = '';
    $count = 0;
    $nextIndex = 0;
    foreach ($lines as $i => $line) {
        if (! is_array($line) || (int) ($line['species_id'] ?? 0) <= 0) {
            continue; // an empty leftover line
        }
        $items .= hef_render_line_item($mode, (string) $i, $line, $speciesNames, $breedNames);
        $count++;
        $nextIndex = max($nextIndex, (int) $i + 1);
    }

    echo '<div class="hef-line-editor" data-mode="' . htmlspecialchars($mode) . '" data-next-index="' . $nextIndex . '">';
    echo '<div class="hef-line-list">' . $items . '</div>';
    echo '<div class="hef-line-empty text-muted small py-2"' . ($count > 0 ? ' hidden' : '') . '>Nothing added yet.</div>';
    echo '<button type="button" class="btn btn-sm btn-outline-secondary hef-line-add"><i class="bi bi-plus-lg me-1"></i>Add species</button>';
    echo hef_line_dialog_html();
    echo '</div>';
}

/** Styles and behaviour for every species editor on the page. Print once, before the footer. */
function hef_line_editor_script(array $breedList, ?array $speciesList = null): void
{
    $data = hef_line_editor_data();
    $species = [];
    foreach (($speciesList ?? $data['species']) as $sp) {
        $species[] = ['id' => (int) $sp['id'], 'name' => $sp['name']];
    }
    $breeds = [];
    foreach ($breedList as $b) {
        $breeds[] = ['id' => (int) $b['id'], 'species_id' => (int) $b['species_id'], 'name' => $b['name']];
    }
    $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    $speciesJson = json_encode($species, $flags);
    $breedsJson = json_encode($breeds, $flags);
    ?>
<style>
    .hef-line-list { max-height: 18rem; overflow-y: auto; padding-right: .25rem; }
    /* The floating dialog: fixed over the page (and over any Bootstrap dialog it sits inside). */
    .hef-float { position: fixed; top: 0; right: 0; bottom: 0; left: 0; z-index: 3000; display: flex; align-items: center; justify-content: center; padding: 1rem; }
    .hef-float[hidden] { display: none; }
    .hef-float-backdrop { position: absolute; top: 0; right: 0; bottom: 0; left: 0; background: rgba(0, 0, 0, .45); }
    .hef-float-card { position: relative; width: 100%; max-width: 440px; max-height: 92vh; overflow-y: auto; }
</style>
<script>
(function () {
    var SPECIES = <?= $speciesJson ?>;
    var BREEDS = <?= $breedsJson ?>;
    var UNIT_DAYS = { days: 1, months: 30, years: 365 };

    function speciesName(id) {
        for (var i = 0; i < SPECIES.length; i++) { if (String(SPECIES[i].id) === String(id)) { return SPECIES[i].name; } }
        return '?';
    }
    function breedName(id) {
        for (var i = 0; i < BREEDS.length; i++) { if (String(BREEDS[i].id) === String(id)) { return BREEDS[i].name; } }
        return '';
    }
    function ageText(value, unit) {
        var v = parseFloat(value);
        if (!(v > 0)) { return 'any age'; }
        var u = UNIT_DAYS[unit] ? unit : 'days';
        if (v === 1) { u = u.replace(/s$/, ''); }
        return String(value).trim() + ' ' + u;
    }
    function ageDays(value, unit) {
        var v = parseFloat(value);
        return v > 0 ? Math.round(v * (UNIT_DAYS[unit] || 1)) : 'x';
    }
    function trimNumber(n) { return String(parseFloat(n.toFixed(2))); }

    function summarize(mode, d) {
        var parts = [speciesName(d.species_id)];
        if (d.breed_id) { parts.push(breedName(d.breed_id)); }
        parts.push(ageText(d.age_value, d.age_unit));
        var detail;
        if (mode === 'customer') {
            detail = 'Need ' + (parseInt(d.quantity, 10) || 0);
        } else {
            var rate = parseFloat(d.rate);
            detail = '₹' + (isFinite(rate) ? trimNumber(rate) : '0') + '/' + (d.unit === 'piece' ? 'piece' : 'kg') + ' · MOQ ' + Math.max(1, parseInt(d.moq, 10) || 1);
        }
        return { title: parts.join(' · '), detail: detail };
    }

    function itemData(item) {
        var d = {};
        item.querySelectorAll('input[data-f]').forEach(function (i) { d[i.dataset.f] = i.value; });
        return d;
    }
    function keyOf(d) { return [d.species_id, d.breed_id || '', ageDays(d.age_value, d.age_unit)].join('|'); }

    function writeItem(item, mode, d) {
        item.querySelectorAll('input[data-f]').forEach(function (i) { i.value = d[i.dataset.f] !== undefined ? d[i.dataset.f] : ''; });
        var s = summarize(mode, d);
        item.querySelector('.hef-li-title').textContent = s.title;
        item.querySelector('.hef-li-sub').textContent = s.detail;
    }
    function buildItem(editor, idx) {
        var mode = editor.dataset.mode;
        var item = document.createElement('div');
        item.className = 'hef-line-item d-flex align-items-center gap-2 border rounded px-2 py-2 mb-2 bg-white';
        item.innerHTML = '<div class="flex-grow-1" style="min-width:0;"><div class="fw-semibold hef-li-title" style="overflow-wrap:anywhere;"></div><div class="small text-muted hef-li-sub"></div></div>'
            + '<button type="button" class="btn btn-sm btn-outline-secondary hef-line-edit" title="Edit"><i class="bi bi-pencil"></i></button>'
            + '<button type="button" class="btn btn-sm btn-outline-danger hef-line-remove" title="Remove"><i class="bi bi-x-lg"></i></button>';
        var fields = mode === 'customer'
            ? ['species_id', 'breed_id', 'age_value', 'age_unit', 'quantity']
            : ['species_id', 'breed_id', 'age_value', 'age_unit', 'rate', 'unit', 'moq'];
        fields.forEach(function (f) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.dataset.f = f;
            input.name = 'lines[' + idx + '][' + f + ']';
            item.appendChild(input);
        });
        return item;
    }
    function toggleEmpty(editor) {
        editor.querySelector('.hef-line-empty').hidden = editor.querySelectorAll('.hef-line-item').length > 0;
    }

    // ---- the floating dialog ----
    function q(dlg, cls) { return dlg.querySelector('.' + cls); }

    function fillBreeds(dlg, speciesId, selected) {
        var select = q(dlg, 'hef-dlg-breed');
        select.innerHTML = '';
        var none = document.createElement('option');
        none.value = '';
        none.textContent = 'No breed';
        select.appendChild(none);
        BREEDS.forEach(function (b) {
            if (String(b.species_id) === String(speciesId)) {
                var opt = document.createElement('option');
                opt.value = b.id;
                opt.textContent = b.name;
                if (String(b.id) === String(selected)) { opt.selected = true; }
                select.appendChild(opt);
            }
        });
    }

    function openDialog(editor, item) {
        var dlg = editor.querySelector('.hef-float');
        var mode = editor.dataset.mode;
        var d = item ? itemData(item) : { species_id: '', breed_id: '', age_value: '', age_unit: 'days', quantity: '', rate: '', unit: 'piece', moq: '' };
        dlg._editor = editor;
        dlg._item = item || null;

        dlg.querySelectorAll('[data-for]').forEach(function (n) { n.hidden = n.dataset.for !== mode; });
        var speciesSelect = q(dlg, 'hef-dlg-species');
        speciesSelect.innerHTML = '<option value="">Choose species…</option>';
        SPECIES.forEach(function (sp) {
            var opt = document.createElement('option');
            opt.value = sp.id;
            opt.textContent = sp.name;
            if (String(sp.id) === String(d.species_id)) { opt.selected = true; }
            speciesSelect.appendChild(opt);
        });
        fillBreeds(dlg, d.species_id, d.breed_id);
        q(dlg, 'hef-dlg-age').value = d.age_value || '';
        q(dlg, 'hef-dlg-age-unit').value = UNIT_DAYS[d.age_unit] ? d.age_unit : 'days';
        q(dlg, 'hef-dlg-qty').value = d.quantity || '';
        q(dlg, 'hef-dlg-rate').value = d.rate || '';
        q(dlg, 'hef-dlg-unit').value = d.unit === 'kg' ? 'kg' : 'piece';
        q(dlg, 'hef-dlg-moq').value = d.moq || '';
        q(dlg, 'hef-dlg-error').textContent = '';
        q(dlg, 'hef-dlg-title').textContent = item ? 'Edit species' : (mode === 'customer' ? 'Add species you need' : 'Add species you supply');
        q(dlg, 'hef-dlg-save').textContent = item ? 'Save' : 'Add';

        dlg.hidden = false;
        speciesSelect.focus();
    }
    function closeDialog(dlg) { dlg.hidden = true; }

    function saveDialog(dlg) {
        var editor = dlg._editor;
        var mode = editor.dataset.mode;
        var fail = function (msg) { q(dlg, 'hef-dlg-error').textContent = msg; };

        var d = {
            species_id: q(dlg, 'hef-dlg-species').value,
            breed_id: q(dlg, 'hef-dlg-breed').value,
            age_value: q(dlg, 'hef-dlg-age').value.trim(),
            age_unit: q(dlg, 'hef-dlg-age-unit').value
        };
        if (!d.species_id) { return fail('Choose a species.'); }
        if (mode === 'customer') {
            d.quantity = q(dlg, 'hef-dlg-qty').value.trim();
            if (!(parseInt(d.quantity, 10) > 0)) { return fail('Enter how many you need.'); }
        } else {
            d.rate = q(dlg, 'hef-dlg-rate').value.trim();
            if (!(parseFloat(d.rate) > 0)) { return fail('Enter your rate.'); }
            d.unit = q(dlg, 'hef-dlg-unit').value;
            d.moq = q(dlg, 'hef-dlg-moq').value.trim() || '1';
        }

        var wanted = keyOf(d);
        var duplicate = false;
        editor.querySelectorAll('.hef-line-item').forEach(function (it) {
            if (it !== dlg._item && keyOf(itemData(it)) === wanted) { duplicate = true; }
        });
        if (duplicate) { return fail('That species, breed and age is already in the list. Edit that line instead.'); }

        var item = dlg._item;
        if (!item) {
            var idx = parseInt(editor.dataset.nextIndex, 10);
            editor.dataset.nextIndex = idx + 1;
            item = buildItem(editor, idx);
            var list = editor.querySelector('.hef-line-list');
            list.appendChild(item);
            list.scrollTop = list.scrollHeight;
        }
        writeItem(item, mode, d);
        toggleEmpty(editor);
        closeDialog(dlg);
    }

    // Keep the short numeric boxes numeric: digits only, or digits with one dot.
    document.addEventListener('input', function (e) {
        var t = e.target;
        if (t.classList.contains('hef-digits')) {
            t.value = t.value.replace(/\D/g, '');
        } else if (t.classList.contains('hef-decimal')) {
            var v = t.value.replace(/[^0-9.]/g, '');
            var dot = v.indexOf('.');
            if (dot !== -1) { v = v.slice(0, dot + 1) + v.slice(dot + 1).replace(/\./g, ''); }
            t.value = v;
        }
    });

    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('hef-dlg-species')) {
            fillBreeds(e.target.closest('.hef-float'), e.target.value, '');
        }
    });

    document.addEventListener('click', function (e) {
        var t = e.target;
        var add = t.closest('.hef-line-add');
        if (add) { openDialog(add.closest('.hef-line-editor'), null); return; }
        var edit = t.closest('.hef-line-edit');
        if (edit) { var it = edit.closest('.hef-line-item'); openDialog(it.closest('.hef-line-editor'), it); return; }
        var remove = t.closest('.hef-line-remove');
        if (remove) {
            var editor = remove.closest('.hef-line-editor');
            remove.closest('.hef-line-item').remove();
            toggleEmpty(editor);
            return;
        }
        var close = t.closest('.hef-dlg-close');
        if (close) { closeDialog(close.closest('.hef-float')); return; }
        var save = t.closest('.hef-dlg-save');
        if (save) { saveDialog(save.closest('.hef-float')); }
    });

    // Escape closes just this dialog (not the Bootstrap dialog around it) and
    // Enter saves it instead of submitting the whole form.
    document.querySelectorAll('.hef-float').forEach(function (dlg) {
        dlg.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                e.stopPropagation();
                closeDialog(dlg);
            } else if (e.key === 'Enter' && e.target.tagName !== 'BUTTON') {
                e.preventDefault();
                e.stopPropagation();
                saveDialog(dlg);
            }
        });
    });
})();
</script>
    <?php
}
