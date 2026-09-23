<?php
/**
 * "Add to Google Contacts" support.
 *
 * Google doesn't publish an official URL scheme for pre-filling the New
 * Contact screen. The confirmed-working parameters are givenname,
 * familyname, phone and email. address and notes are not documented
 * anywhere — they're a best guess based on Google's own field names.
 * Test them; if Google drops one, this is the only place to adjust it.
 */

/**
 * Build the "Add to Google Contacts" URL for one contact.
 */
function hef_google_contacts_url(string $fullName, ?string $phone, ?string $email, ?string $address, ?string $notes): string
{
    $parts = preg_split('/\s+/', trim($fullName), 2);
    $givenName = $parts[0] ?? '';
    $familyName = $parts[1] ?? '';

    $params = array_filter([
        'givenname'  => $givenName,
        'familyname' => $familyName,
        'phone'      => $phone,
        'email'      => $email,
        'address'    => $address,
        'notes'      => $notes,
    ], static fn($v) => $v !== null && $v !== '');

    return 'https://contacts.google.com/new?' . http_build_query($params);
}

/**
 * Fetch a customer's contact fields for the URL above. Uses SELECT * and
 * null-coalescing so this doesn't break if your customers table doesn't
 * have an address or email column — those fields are just left out.
 */
function hef_customer_contact_fields(PDO $pdo, int $customerId, int $companyId): array
{
    $stmt = $pdo->prepare('SELECT * FROM customers WHERE id = ? AND company_id = ?');
    $stmt->execute([$customerId, $companyId]);
    $row = $stmt->fetch() ?: [];

    return [
        'name'    => $row['name'] ?? '',
        'phone'   => $row['phone'] ?? null,
        'email'   => $row['email'] ?? null,
        'address' => $row['address'] ?? null,
    ];
}

/**
 * A customer's current requirements, formatted as one line per
 * species/breed/age, for the Notes field.
 */
function hef_customer_requirements_text(PDO $pdo, int $customerId): string
{
    $stmt = $pdo->prepare(
        "SELECT s.name AS species_name, b.name AS breed_name, cs.age_days, cs.quantity
         FROM customer_species cs
         JOIN species s ON s.id = cs.species_id
         LEFT JOIN breeds b ON b.id = cs.breed_id
         WHERE cs.customer_id = ? AND cs.quantity > 0
         ORDER BY s.name, b.name, cs.age_days"
    );
    $stmt->execute([$customerId]);

    $lines = [];
    foreach ($stmt->fetchAll() as $row) {
        $age = $row['age_days'] !== null ? hef_format_age((int) $row['age_days']) : 'any age';
        $breed = $row['breed_name'] ? $row['breed_name'] . ' ' : '';
        $lines[] = (int) $row['quantity'] . ' ' . $breed . $row['species_name'] . ' (' . $age . ')';
    }

    return implode('; ', $lines);
}
