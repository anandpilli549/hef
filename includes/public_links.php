<?php
/**
 * Private public links: a supplier (or customer) gets an unguessable link they
 * can open without logging in to keep their own list up to date. The token is
 * 32 random hex characters stored on the record; clearing it switches the
 * link off, and generating a new one invalidates the old one.
 */

/** The address a supplier or customer opens. $type is 'supplier' or 'customer'. */
function hef_public_link_url(string $type, string $token): string
{
    $page = $type === 'customer' ? 'customer-requirements.php' : 'supplier-update.php';

    return 'https://cthkennels.com/hef/' . $page . '?t=' . $token;
}

/** A fresh token that no other supplier / customer uses. $table is 'suppliers' or 'customers'. */
function hef_new_public_token(PDO $pdo, string $table): string
{
    if (! in_array($table, ['suppliers', 'customers'], true)) {
        throw new InvalidArgumentException('Unknown table');
    }
    do {
        $token = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE update_token = ?");
        $stmt->execute([$token]);
    } while ($stmt->fetchColumn());

    return $token;
}

/**
 * What changed between two versions of a supplier's price list, for the
 * email to the farm. Rows have species_id, breed_id, age_days, rate, unit, moq.
 *
 * @return array{added: string[], removed: string[], changed: string[]}
 */
function hef_supplier_line_changes(array $oldRows, array $newRows, array $speciesNames, array $breedNames): array
{
    $key = function ($r) {
        return hef_line_key($r['species_id'], $r['breed_id'], $r['age_days'] !== null ? (int) $r['age_days'] : null);
    };
    $label = function ($r) use ($speciesNames, $breedNames) {
        return hef_line_label(
            $speciesNames[(int) $r['species_id']] ?? '?',
            $r['breed_id'] !== null ? ($breedNames[(int) $r['breed_id']] ?? null) : null,
            $r['age_days'] !== null ? (int) $r['age_days'] : null
        );
    };
    $price = function ($r) {
        return '₹' . rtrim(rtrim(number_format((float) $r['rate'], 2, '.', ''), '0'), '.') . '/' . $r['unit'] . ' (MOQ ' . (int) $r['moq'] . ')';
    };

    $old = [];
    foreach ($oldRows as $r) {
        $old[$key($r)] = $r;
    }
    $new = [];
    foreach ($newRows as $r) {
        $new[$key($r)] = $r;
    }

    $out = ['added' => [], 'removed' => [], 'changed' => []];
    foreach ($new as $k => $r) {
        if (! isset($old[$k])) {
            $out['added'][] = $label($r) . ': ' . $price($r);
        } elseif (abs((float) $old[$k]['rate'] - (float) $r['rate']) > 0.001 || $old[$k]['unit'] !== $r['unit'] || (int) $old[$k]['moq'] !== (int) $r['moq']) {
            $out['changed'][] = $label($r) . ': ' . $price($old[$k]) . ' → ' . $price($r);
        }
    }
    foreach ($old as $k => $r) {
        if (! isset($new[$k])) {
            $out['removed'][] = $label($r);
        }
    }

    return $out;
}

/**
 * What changed between two versions of a customer's requirement list, for the
 * email to the farm. Rows have species_id, breed_id, age_days, quantity.
 *
 * @return array{added: string[], removed: string[], changed: string[]}
 */
function hef_customer_line_changes(array $oldRows, array $newRows, array $speciesNames, array $breedNames): array
{
    $key = function ($r) {
        return hef_line_key($r['species_id'], $r['breed_id'], $r['age_days'] !== null ? (int) $r['age_days'] : null);
    };
    $label = function ($r) use ($speciesNames, $breedNames) {
        return hef_line_label(
            $speciesNames[(int) $r['species_id']] ?? '?',
            $r['breed_id'] !== null ? ($breedNames[(int) $r['breed_id']] ?? null) : null,
            $r['age_days'] !== null ? (int) $r['age_days'] : null
        );
    };

    $old = [];
    foreach ($oldRows as $r) {
        $old[$key($r)] = $r;
    }
    $new = [];
    foreach ($newRows as $r) {
        $new[$key($r)] = $r;
    }

    $out = ['added' => [], 'removed' => [], 'changed' => []];
    foreach ($new as $k => $r) {
        if (! isset($old[$k])) {
            $out['added'][] = $label($r) . ': ' . (int) $r['quantity'];
        } elseif ((int) $old[$k]['quantity'] !== (int) $r['quantity']) {
            $out['changed'][] = $label($r) . ': ' . (int) $old[$k]['quantity'] . ' → ' . (int) $r['quantity'];
        }
    }
    foreach ($old as $k => $r) {
        if (! isset($new[$k])) {
            $out['removed'][] = $label($r) . ': ' . (int) $r['quantity'];
        }
    }

    return $out;
}
