<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/global.php';
require_once __DIR__ . '/includes/mail-templates.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/line_items.php';
require_once __DIR__ . '/includes/public_links.php';
require_once __DIR__ . '/includes/contacts.php';

// Private page for one customer: they tell the farm which animals they need,
// and how many, without logging in. The link is a secret token the farm sends them.

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

function hef_link_gone(): void
{
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><title>Link not active</title>'
        . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>'
        . '<body class="bg-light"><div class="container py-5 text-center" style="max-width:480px;">'
        . '<h4>This link isn\'t active</h4><p class="text-muted">Please ask the farm to send you a new link.</p></div></body></html>';
    exit;
}

$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
if (! preg_match('/^[a-f0-9]{32}$/', $token)) {
    hef_link_gone();
}

$stmt = $pdo->prepare(
    'SELECT c.*, co.name AS company_name, co.logo_path
     FROM customers c JOIN companies co ON co.id = c.company_id
     WHERE c.update_token = ?'
);
$stmt->execute([$token]);
$customer = $stmt->fetch();

// Unknown or switched-off link, or a company that is no longer on a Pro plan.
if (! $customer || ! hef_company_has_pro($pdo, (int) $customer['company_id'])) {
    hef_link_gone();
}

$companyId = (int) $customer['company_id'];
$customerId = (int) $customer['id'];
[$species, $breeds] = hef_load_species_breeds($pdo, $companyId);
$error = '';
$postedLines = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawLines = is_array($_POST['lines'] ?? null) ? array_slice($_POST['lines'], 0, 100, true) : [];
    $parsed = hef_parse_lines($rawLines, 'customer', $species, $breeds);

    if ($parsed['error']) {
        $error = $parsed['error'];
        $postedLines = $rawLines; // keep what they typed
    } else {
        $stmt = $pdo->prepare('SELECT species_id, breed_id, age_days, quantity FROM customer_species WHERE customer_id = ?');
        $stmt->execute([$customerId]);
        $oldRows = $stmt->fetchAll();

        try {
            hef_sync_customer_lines($pdo, $customerId, $parsed['rows']);
            $tz = hef_company_tz($pdo, $companyId);
            $pdo->prepare('UPDATE customers SET last_customer_update_at = ?, updated_at = ? WHERE id = ?')->execute([hef_company_now($tz), hef_company_now($tz), $customerId]);
        } catch (PDOException $e) {
            error_log('customer link save failed: ' . $e->getMessage());
            $error = 'Sorry, we could not save your changes. Please try again.';
            $postedLines = $rawLines;
        }

        if ($error === '') {
            // Tell the farm's owners what changed. Never let email trouble undo the save.
            try {
                $speciesNames = array_column($species, 'name', 'id');
                $breedNames = array_column($breeds, 'name', 'id');
                $changes = hef_customer_line_changes($oldRows, $parsed['rows'], $speciesNames, $breedNames);
                if ($changes['added'] || $changes['removed'] || $changes['changed']) {
                    $rows = [];
                    foreach (['added' => 'Added', 'changed' => 'Changed', 'removed' => 'Removed'] as $k => $label) {
                        if ($changes[$k]) {
                            $rows[$label] = implode(' · ', array_slice($changes[$k], 0, 10)) . (count($changes[$k]) > 10 ? ' …and ' . (count($changes[$k]) - 10) . ' more' : '');
                        }
                    }
                    $inner = '<p style="margin:0 0 8px; color:#444; font-size:14px;"><strong>' . htmlspecialchars($customer['name'])
                        . '</strong> updated what they need using their private link.</p>'
                        . hef_email_details_table($rows)
                        . hef_email_button('https://cthkennels.com/hef/admin/requirements.php', 'Open Requirements');
                    $html = hef_email_wrap('🛒 Customer updated their requirements', $inner, $customer['name'] . ' updated their requirements');

                    $stmt = $pdo->prepare("SELECT id, email FROM users WHERE company_id = ? AND role = 'owner' AND status = 'active' AND email != ''");
                    $stmt->execute([$companyId]);
                    foreach ($stmt->fetchAll() as $owner) {
                        hef_send_mail($pdo, $owner['email'], $customer['name'] . ' updated their requirements', $html, $companyId, (int) $owner['id']);
                    }
                }
            } catch (Throwable $e) {
                error_log('customer link notify failed: ' . $e->getMessage());
            }

            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?t=' . $token . '&saved=1');
            exit;
        }
    }
}

// What to show in the editor: what they just typed if there was a problem, otherwise the saved list.
if ($postedLines !== null) {
    $editLines = $postedLines;
} else {
    $stmt = $pdo->prepare('SELECT species_id, breed_id, age_days, quantity FROM customer_species WHERE customer_id = ? AND quantity > 0 ORDER BY species_id, breed_id, age_days');
    $stmt->execute([$customerId]);
    $editLines = [];
    foreach ($stmt->fetchAll() as $ln) {
        [$ageValue, $ageUnit] = hef_age_parts($ln['age_days'] !== null ? (int) $ln['age_days'] : null);
        $editLines[] = [
            'species_id' => (int) $ln['species_id'], 'breed_id' => $ln['breed_id'] !== null ? (int) $ln['breed_id'] : null,
            'age_value' => $ageValue, 'age_unit' => $ageUnit, 'quantity' => (int) $ln['quantity'],
        ];
    }
}
$saved = isset($_GET['saved']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>What do you need? — <?= htmlspecialchars($customer['company_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>body { background: #f4f6f4; } .btn-hef { background: #2f7d4f; border-color: #2f7d4f; } .btn-hef:hover { background: #276841; border-color: #276841; }</style>
</head>
<body>
<div class="container py-4" style="max-width: 860px;">
    <div class="text-center mb-4">
        <?php if ($customer['logo_path']): ?><img src="/hef/<?= htmlspecialchars($customer['logo_path']) ?>" alt="" style="height:56px;" class="mb-2"><?php endif; ?>
        <h4 class="mb-0"><?= htmlspecialchars($customer['company_name']) ?></h4>
        <div class="text-muted">What you need, <strong><?= htmlspecialchars($customer['name']) ?></strong></div>
    </div>

    <?php if ($saved): ?><div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>Thank you — your requirements have been saved and <?= htmlspecialchars($customer['company_name']) ?> has been told.</div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body p-3 p-md-4">
            <p class="text-muted small mb-3">Press <strong>Add species</strong> for each kind of animal you need: the species, breed (if it matters), how old they should be, and how many. Use the pencil to change a line or ✕ to remove it. Press <strong>Save my requirements</strong> when you are done — you can come back to this page any time to change it.</p>
            <form method="POST">
                <input type="hidden" name="t" value="<?= htmlspecialchars($token) ?>">
                <?php hef_render_line_editor('customer', $editLines, $species, $breeds); ?>
                <button type="submit" class="btn btn-hef text-white w-100 mt-3">Save my requirements</button>
            </form>
        </div>
    </div>
    <p class="text-muted small text-center mt-3">Leave the age empty if any age will do.<br>
        This page is private to you — please don't share the link, because anyone with it can change your requirements.</p>
</div>
<?php hef_line_editor_script($breeds); ?>
</body>
</html>
