<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/line_items.php';
require_once __DIR__ . '/../includes/contacts.php';

/**
 * CSV download of your own data, opened in Excel or Google Sheets.
 *
 *   export.php?type=batches | performance | finances | bookings | customers | suppliers | requirements
 *   (bookings also takes &status=… and performance &show=… to match the
 *   filter on those pages)
 *
 * Each type is limited to the people who can already open that page:
 * batches and performance — everyone (performance leaves out the money
 * columns unless you are the Owner); bookings — Owner and Worker; finances — Owner;
 * customers, suppliers and requirements — Owner on a Pro plan.
 * The file is UTF-8 with a byte-order mark so Excel shows names in Telugu,
 * Hindi and other scripts correctly.
 */

$companyId = (int) $currentUser['company_id'];
$role = $currentUser['role'];
$type = (string) ($_GET['type'] ?? '');

$allowed = [
    'batches' => true,
    'performance' => true,
    'bookings' => $role !== 'vet',
    'finances' => $role === 'owner',
    'payroll' => hef_user_can_access_pro_pages($pdo, $currentUser),
    'customers' => hef_user_can_access_pro_pages($pdo, $currentUser),
    'suppliers' => hef_user_can_access_pro_pages($pdo, $currentUser),
    'requirements' => hef_user_can_access_pro_pages($pdo, $currentUser),
];

if (! isset($allowed[$type]) || ! $allowed[$type]) {
    header('Location: /hef/admin/dashboard.php?error=access_denied');
    exit;
}

/**
 * Makes one value safe to put in a CSV cell. Spreadsheet programs treat a
 * cell starting with = + - @ as a formula, and names, notes and addresses can
 * come from public booking forms, so those get a leading apostrophe. Numbers
 * and phone-number-like text (+91 98765 43210) are left alone.
 */
function hef_csv_cell($value)
{
    if ($value === null) {
        return '';
    }
    if (is_int($value) || is_float($value)) {
        return $value;
    }
    $value = (string) $value;
    if ($value === '') {
        return '';
    }
    if (preg_match('/^[=@\t\r]/', $value)) {
        return "'" . $value;
    }
    if (preg_match('/^[+\-]/', $value) && ! preg_match('/^[+\-][0-9 ()\-.]*$/', $value)) {
        return "'" . $value;
    }

    return $value;
}

/** Nice text for an enum value: "animal_sale" -> "Animal sale". */
function hef_csv_label($value): string
{
    return $value === null ? '' : ucfirst(str_replace('_', ' ', (string) $value));
}

/** "₹25.00/piece (MOQ 1)" */
function hef_csv_rate(array $row): string
{
    $text = '₹' . $row['rate'] . ($row['unit'] ? '/' . $row['unit'] : '');

    return $text . ' (MOQ ' . (int) $row['moq'] . ')';
}

$headers = [];
$rows = [];

if ($type === 'batches') {
    $headers = [
        'Batch code', 'Species', 'Breed', 'Room', 'Status', 'Date acquired', 'Age at acquisition (days)',
        'Initial male', 'Initial female', 'Initial unknown',
        'Current male', 'Current female', 'Current unknown', 'Current total',
        'Source supplier', 'Purchase cost',
    ];
    $stmt = $pdo->prepare(
        'SELECT b.batch_code, s.name AS species, br.name AS breed, r.name AS room, b.status, b.date_acquired,
                b.age_at_acquisition_days, b.initial_count_male, b.initial_count_female, b.initial_count_unknown,
                b.current_count_male, b.current_count_female, b.current_count_unknown,
                b.source_supplier, b.purchase_cost
         FROM batches b
         JOIN species s ON s.id = b.species_id
         LEFT JOIN breeds br ON br.id = b.breed_id
         LEFT JOIN rooms r ON r.id = b.room_id
         WHERE b.company_id = ?
         ORDER BY b.date_acquired DESC, b.batch_code'
    );
    $stmt->execute([$companyId]);
    foreach ($stmt as $r) {
        $rows[] = [
            $r['batch_code'], $r['species'], $r['breed'], $r['room'], hef_csv_label($r['status']), $r['date_acquired'],
            (int) $r['age_at_acquisition_days'],
            (int) $r['initial_count_male'], (int) $r['initial_count_female'], (int) $r['initial_count_unknown'],
            (int) $r['current_count_male'], (int) $r['current_count_female'], (int) $r['current_count_unknown'],
            (int) $r['current_count_male'] + (int) $r['current_count_female'] + (int) $r['current_count_unknown'],
            $r['source_supplier'], $r['purchase_cost'],
        ];
    }
} elseif ($type === 'performance') {
    $headers = [
        'Batch', 'Species', 'Breed', 'Status', 'Date acquired', 'Age today (days, active only)',
        'Sell-by date', 'Days to sell-by', 'Raised', 'Dead', 'Mortality %', 'Sold', 'Alive',
    ];
    if ($role === 'owner') {
        $headers = array_merge($headers, [
            'Purchase & other costs', 'Feed cost', 'Health cost', 'Total cost', 'Revenue', 'Profit',
            'Cost per animal', 'Profit per animal',
        ]);
    }
    $show = (string) ($_GET['show'] ?? 'all');
    $today = hef_company_today_for($pdo, $companyId);
    foreach (hef_batch_performance($pdo, $companyId, $today) as $p) {
        if ($show !== 'all' && $p['status'] !== $show) {
            continue;
        }
        $row = [
            $p['batch_code'], $p['species_name'], $p['breed_name'], hef_csv_label($p['status']), $p['date_acquired'],
            $p['status'] === 'active' ? $p['age_days'] : null,
            $p['sell_by'], $p['days_left'], $p['initial'], $p['dead'], $p['mortality_pct'], $p['sold'], $p['alive'],
        ];
        if ($role === 'owner') {
            $row = array_merge($row, [
                round($p['other_costs'], 2), round($p['feed_cost'], 2), round($p['health_cost'], 2),
                round($p['total_cost'], 2), round($p['revenue'], 2), round($p['profit'], 2),
                $p['cost_per_animal'] !== null ? round($p['cost_per_animal'], 2) : null,
                $p['profit_per_animal'] !== null ? round($p['profit_per_animal'], 2) : null,
            ]);
        }
        $rows[] = $row;
    }
} elseif ($type === 'finances') {
    $headers = ['Type', 'Date', 'Category / source', 'Amount', 'Currency', 'Batch', 'Quantity sold', 'Gender sold', 'Notes'];
    $stmt = $pdo->prepare(
        "(SELECT 'Expense' AS type, e.date, e.category AS label, e.amount, e.currency_code, b.batch_code,
                 NULL AS quantity_sold, NULL AS gender_sold, e.notes
          FROM expenses e LEFT JOIN batches b ON b.id = e.batch_id
          WHERE e.company_id = ?)
         UNION ALL
         (SELECT 'Income' AS type, i.date, i.source AS label, i.amount, i.currency_code, b.batch_code,
                 i.quantity_sold, i.gender_sold, i.notes
          FROM income i LEFT JOIN batches b ON b.id = i.batch_id
          WHERE i.company_id = ?)
         ORDER BY date DESC, type"
    );
    $stmt->execute([$companyId, $companyId]);
    foreach ($stmt as $r) {
        $rows[] = [
            $r['type'], $r['date'], hef_csv_label($r['label']), $r['amount'], $r['currency_code'], $r['batch_code'],
            $r['quantity_sold'] !== null ? (int) $r['quantity_sold'] : null, hef_csv_label($r['gender_sold']), $r['notes'],
        ];
    }
} elseif ($type === 'payroll') {
    $headers = ['Staff', 'Designation', 'Month', 'Basic salary', 'Absence days', 'Absence deduction', 'Additions', 'Other deductions', 'Advance recovered', 'Net pay', 'Status', 'Paid on', 'Paid by', 'Note'];
    $monthStart = hef_month_start($_GET['month'] ?? null, date('Y-m-d'));
    $stmt = $pdo->prepare(
        'SELECT s.name, s.designation, p.* FROM staff_payroll p JOIN staff s ON s.id = p.staff_id
         WHERE p.company_id = ? AND p.month = ? ORDER BY s.name'
    );
    $stmt->execute([$companyId, $monthStart]);
    foreach ($stmt as $r) {
        $rows[] = [
            $r['name'], $r['designation'], date('F Y', strtotime($r['month'])), $r['base_salary'], $r['deduction_days'],
            $r['attendance_deduction'], $r['additions'], $r['other_deductions'], $r['advance_recovery'], $r['net_pay'],
            hef_csv_label($r['status']), $r['paid_on'], hef_csv_label($r['payment_mode']), $r['notes'],
        ];
    }
    $type = 'payroll-' . substr($monthStart, 0, 7);
} elseif ($type === 'bookings') {
    $headers = [
        'Booking #', 'Date', 'Customer', 'Email', 'Phone', 'Fulfilment', 'Delivery address', 'Pincode',
        'Items', 'Subtotal', 'Delivery fee', 'Total', 'Currency', 'Status',
    ];
    $statusFilter = (string) ($_GET['status'] ?? '');
    $sql = "SELECT bk.id, bk.created_at, bk.customer_name, bk.customer_email, bk.customer_phone, bk.fulfillment_type,
                   bk.delivery_address, bk.delivery_pincode,
                   (SELECT GROUP_CONCAT(CONCAT(sl.title, ' x', bi.quantity) SEPARATOR '; ')
                    FROM booking_items bi JOIN storefront_listings sl ON sl.id = bi.storefront_listing_id
                    WHERE bi.booking_id = bk.id) AS items,
                   bk.subtotal, bk.delivery_fee, bk.total_amount, bk.currency_code, bk.status
            FROM bookings bk
            WHERE bk.company_id = ?";
    $params = [$companyId];
    if ($statusFilter !== '') {
        $sql .= ' AND bk.status = ?'; // a value that isn't a real status simply matches nothing
        $params[] = $statusFilter;
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY bk.created_at DESC');
    $stmt->execute($params);
    foreach ($stmt as $r) {
        $rows[] = [
            (int) $r['id'], $r['created_at'], $r['customer_name'], $r['customer_email'], $r['customer_phone'],
            hef_csv_label($r['fulfillment_type']), $r['delivery_address'], $r['delivery_pincode'],
            $r['items'], $r['subtotal'], $r['delivery_fee'], $r['total_amount'], $r['currency_code'],
            hef_csv_label($r['status']),
        ];
    }
} elseif ($type === 'customers' || $type === 'suppliers') {
    $isCust = $type === 'customers';
    $T = hef_contact_meta($isCust ? 'customer' : 'supplier');
    $phoneHeaders = array_map(function ($i) { return 'Phone ' . ($i + 1); }, array_keys($T['phone_cols']));
    $headers = array_merge(['Name'], $phoneHeaders, ['Email', 'Address', 'Status', 'Notes', 'Species', 'Breed', 'Age']);
    $headers[] = $isCust ? 'Quantity' : 'Rate';
    if (! $isCust) {
        $headers[] = 'Unit';
        $headers[] = 'MOQ';
    }
    $detailCols = $isCust ? 'l.quantity' : 'l.rate, l.unit, l.moq';
    $phoneCols = implode(', ', array_map(function ($c) { return 'c.' . $c; }, $T['phone_cols']));
    $stmt = $pdo->prepare(
        "SELECT c.name, {$phoneCols}, c.email, c.address, c.status, c.status_reason, c.notes,
                s.name AS species, br.name AS breed, l.age_days, {$detailCols}
         FROM {$T['table']} c
         LEFT JOIN {$T['lines_table']} l ON l.{$T['fk']} = c.id
         LEFT JOIN species s ON s.id = l.species_id
         LEFT JOIN breeds br ON br.id = l.breed_id
         WHERE c.company_id = ?
         ORDER BY c.name, s.name, br.name, l.age_days"
    );
    $stmt->execute([$companyId]);
    foreach ($stmt as $r) {
        $hasLine = $r['species'] !== null;
        $row = [$r['name']];
        foreach ($T['phone_cols'] as $col) {
            $row[] = hef_format_phone($r[$col]);
        }
        $statusText = $r['status'] === 'active' ? 'Active' : ($T['reasons'][$r['status_reason']] ?? $T['closed_label']);
        $row = array_merge($row, [
            $r['email'], $r['address'], $statusText, $r['notes'],
            $r['species'], $r['breed'],
            $hasLine ? hef_format_age($r['age_days'] !== null ? (int) $r['age_days'] : null) : '',
        ]);
        if ($isCust) {
            $row[] = $hasLine ? (int) $r['quantity'] : null;
        } else {
            $row[] = $hasLine ? $r['rate'] : null;
            $row[] = $hasLine ? $r['unit'] : null;
            $row[] = $hasLine ? (int) $r['moq'] : null;
        }
        $rows[] = $row;
    }
} else { // requirements
    $headers = ['Species', 'Breed', 'Age', 'Total needed', 'Customers', 'Customers asking', 'Supplier match', 'Suppliers who can fill it'];

    // Same buckets and matching rules as the Requirements page.
    $stmt = $pdo->prepare(
        'SELECT s.id AS species_id, s.name AS species_name, cs.breed_id, b.name AS breed_name, cs.age_days,
                SUM(cs.quantity) AS total_needed
         FROM customer_species cs
         JOIN customers c ON c.id = cs.customer_id AND c.company_id = ?
         JOIN species s ON s.id = cs.species_id AND s.company_id = ?
         LEFT JOIN breeds b ON b.id = cs.breed_id
         WHERE cs.quantity > 0
         GROUP BY s.id, s.name, cs.breed_id, b.name, cs.age_days
         ORDER BY s.name, b.name, cs.age_days'
    );
    $stmt->execute([$companyId, $companyId]);
    $buckets = $stmt->fetchAll();

    $customersByBucket = [];
    $stmt = $pdo->prepare(
        'SELECT cs.species_id, cs.breed_id, cs.age_days, c.name, cs.quantity
         FROM customer_species cs JOIN customers c ON c.id = cs.customer_id
         WHERE c.company_id = ? AND cs.quantity > 0
         ORDER BY cs.quantity DESC'
    );
    $stmt->execute([$companyId]);
    foreach ($stmt->fetchAll() as $r) {
        $key = hef_line_key($r['species_id'], $r['breed_id'], $r['age_days'] !== null ? (int) $r['age_days'] : null);
        $customersByBucket[$key][] = $r['name'] . ' (' . (int) $r['quantity'] . ')';
    }

    $stmt = $pdo->prepare(
        'SELECT ss.species_id, ss.breed_id, ss.age_days, sup.name, ss.rate, ss.unit, ss.moq
         FROM supplier_species ss JOIN suppliers sup ON sup.id = ss.supplier_id
         WHERE sup.company_id = ?'
    );
    $stmt->execute([$companyId]);
    $supplierLines = $stmt->fetchAll();

    $matchLabels = ['exact' => 'Matching age', 'any' => 'Any age (all ages listed)', 'other' => 'Other ages only', 'none' => 'No supplier'];

    foreach ($buckets as $bucket) {
        $age = $bucket['age_days'] !== null ? (int) $bucket['age_days'] : null;
        $key = hef_line_key($bucket['species_id'], $bucket['breed_id'], $age);
        $customerList = $customersByBucket[$key] ?? [];
        $match = hef_match_suppliers($supplierLines, $bucket['species_id'], $bucket['breed_id'], $age);

        $supplierList = [];
        foreach ($match['rows'] as $sr) {
            $srAge = $sr['age_days'] !== null ? (int) $sr['age_days'] : null;
            $text = $sr['name'] . ' — ' . hef_csv_rate($sr);
            if ($match['mode'] !== 'exact' || $srAge === null) {
                $text .= ' [' . hef_format_age($srAge) . ']';
            }
            $supplierList[] = $text;
        }

        $rows[] = [
            $bucket['species_name'], $bucket['breed_name'], hef_format_age($age), (int) $bucket['total_needed'],
            count($customerList), implode('; ', $customerList),
            $matchLabels[$match['mode']] ?? '', implode('; ', $supplierList),
        ];
    }
}

// --- Send the file ---
$filename = 'hefarm-' . $type . '-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // byte-order mark so Excel reads it as UTF-8
fputcsv($out, $headers, ',', '"', '\\');
foreach ($rows as $row) {
    fputcsv($out, array_map('hef_csv_cell', $row), ',', '"', '\\');
}
fclose($out);
exit;
