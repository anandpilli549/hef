<?php
/**
 * The Customers and Suppliers page, shared. admin/customers.php and
 * admin/suppliers.php load bootstrap, require Pro, set $contactType to
 * 'customer' or 'supplier' and include this file.
 *
 * Contacts are shown as soft-coloured cards. Each has several phone numbers
 * (2 for customers, 4 for suppliers) saved in one format, timestamped call
 * notes, a follow-up calendar button, a private link so they can update their
 * own list, and a Close / Reopen status for customers who no longer need
 * animals (or booked from us) and suppliers who no longer supply.
 */

require_once __DIR__ . '/line_items.php';
require_once __DIR__ . '/public_links.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/contacts.php';

$T = hef_contact_meta($contactType);
$isCustomer = $contactType === 'customer';
$Type = ucfirst($contactType);
$companyId = (int) $currentUser['company_id'];
$userId = (int) $currentUser['user_id'];
$isOwner = $currentUser['role'] === 'owner';
$tz = hef_company_tz($pdo, $companyId);
$syncLines = $isCustomer ? 'hef_sync_customer_lines' : 'hef_sync_supplier_lines';

$error = '';
$status = '';
$addModalOpen = false;
$openNotesFor = 0;
$reopenLinkFor = 0;
$addFormLines = [];
$addValues = ['name' => '', 'email' => '', 'address' => '', 'notes' => ''];
foreach ($T['phone_cols'] as $col) {
    $addValues[$col] = '';
}

[$allSpecies, $allBreeds] = hef_load_species_breeds($pdo, $companyId);
$stmt = $pdo->prepare('SELECT name FROM companies WHERE id = ?');
$stmt->execute([$companyId]);
$companyName = (string) $stmt->fetchColumn();

/** Reads and checks the add / edit form: name, email, phone numbers, address, note. */
$readForm = function (array $post) use ($T): array {
    $name = trim((string) ($post['name'] ?? ''));
    $email = trim((string) ($post['email'] ?? ''));
    [$phones, $phoneError] = hef_read_phones($post, $T);
    $error = '';
    if ($name === '') {
        $error = $T['label'] . ' name is required.';
    } elseif ($phoneError !== '') {
        $error = $phoneError;
    } elseif ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'That email address doesn\'t look right.';
    }

    return [
        'name' => $name, 'email' => $email !== '' ? $email : null, 'phones' => $phones,
        'address' => trim((string) ($post['address'] ?? '')) ?: null, 'notes' => trim((string) ($post['notes'] ?? '')) ?: null,
        'error' => $error,
    ];
};

// ---------------------------------------------------------------- actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_contact_note']) || isset($_POST['edit_contact_note']) || isset($_POST['delete_contact_note']))) {
    $r = hef_contact_notes_handle($pdo, $companyId, $userId, $contactType, $tz);
    $status = $r['status'];
    $error = $r['error'];
    $openNotesFor = $r['open'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_contact_status'])) {
    $r = hef_contact_status_handle($pdo, $companyId, $userId, $contactType, $tz);
    $status = $r['status'];
    $error = $r['error'];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contact_link'])) {
    // Private link for them to keep their own list up to date: create, replace (the old one stops working) or switch off.
    $id = (int) $_POST['contact_link'];
    $action = (string) ($_POST['link_action'] ?? '');
    $stmt = $pdo->prepare('SELECT id, name FROM ' . $T['table'] . ' WHERE id = ? AND company_id = ?');
    $stmt->execute([$id, $companyId]);
    $linked = $stmt->fetch();

    if (! $linked) {
        $error = $T['label'] . ' not found.';
    } elseif ($action === 'create' || $action === 'regenerate') {
        $pdo->prepare('UPDATE ' . $T['table'] . ' SET update_token = ?, update_token_created_at = NOW() WHERE id = ?')
            ->execute([hef_new_public_token($pdo, $T['table']), $id]);
        $reopenLinkFor = $id;
        $status = $action === 'create' ? 'Link created for ' . $linked['name'] . '.' : 'New link created for ' . $linked['name'] . '. The old link no longer works.';
    } elseif ($action === 'disable') {
        $pdo->prepare('UPDATE ' . $T['table'] . ' SET update_token = NULL, update_token_created_at = NULL WHERE id = ?')->execute([$id]);
        $status = 'Link switched off for ' . $linked['name'] . '.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_contact_id'])) {
    $id = (int) $_POST['delete_contact_id'];
    $stmt = $pdo->prepare('SELECT * FROM ' . $T['table'] . ' WHERE id = ? AND company_id = ?');
    $stmt->execute([$id, $companyId]);
    $old = $stmt->fetch();
    if ($old) {
        $pdo->prepare('DELETE FROM contact_notes WHERE company_id = ? AND contact_type = ? AND contact_id = ?')->execute([$companyId, $contactType, $id]);
        $pdo->prepare('DELETE FROM ' . $T['table'] . ' WHERE id = ? AND company_id = ?')->execute([$id, $companyId]);
        hef_audit_log($pdo, $companyId, $userId, $T['table'], $id, 'delete', $old, null);
        $status = $T['label'] . ' deleted.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_contact_id'])) {
    $id = (int) $_POST['edit_contact_id'];
    $form = $readForm($_POST);
    $parsed = hef_parse_lines($_POST['lines'] ?? [], $contactType, $allSpecies, $allBreeds);

    if ($form['error']) {
        $error = $form['error'];
    } elseif ($parsed['error']) {
        $error = $parsed['error'];
    } else {
        try {
            $stmt = $pdo->prepare('SELECT * FROM ' . $T['table'] . ' WHERE id = ? AND company_id = ?');
            $stmt->execute([$id, $companyId]);
            $oldRow = $stmt->fetch();
            if ($oldRow) {
                $sets = ['name = ?', 'email = ?', 'address = ?', 'notes = ?'];
                $params = [$form['name'], $form['email'], $form['address'], $form['notes']];
                foreach ($T['phone_cols'] as $col) {
                    $sets[] = $col . ' = ?';
                    $params[] = $form['phones'][$col];
                }
                $pdo->prepare('UPDATE ' . $T['table'] . ' SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = ? AND company_id = ?')
                    ->execute(array_merge($params, [$id, $companyId]));
                $syncLines($pdo, $id, $parsed['rows']);
                hef_audit_log($pdo, $companyId, $userId, $T['table'], $id, 'update', $oldRow, ['name' => $form['name'], 'email' => $form['email']]);
                $status = $T['label'] . ' updated.';
            }
        } catch (PDOException $e) {
            error_log($contactType . ' update failed: ' . $e->getMessage());
            $error = 'Could not save changes.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add a new contact.
    $form = $readForm($_POST);
    $parsed = hef_parse_lines($_POST['lines'] ?? [], $contactType, $allSpecies, $allBreeds);
    $addFormLines = is_array($_POST['lines'] ?? null) ? $_POST['lines'] : [];
    foreach (array_keys($addValues) as $k) {
        $addValues[$k] = (string) ($_POST[$k] ?? '');
    }

    if ($form['error']) {
        $error = $form['error'];
        $addModalOpen = true;
    } elseif ($parsed['error']) {
        $error = $parsed['error'];
        $addModalOpen = true;
    } else {
        try {
            $cols = array_merge(['company_id', 'name', 'email', 'address', 'notes'], $T['phone_cols']);
            $params = [$companyId, $form['name'], $form['email'], $form['address'], $form['notes']];
            foreach ($T['phone_cols'] as $col) {
                $params[] = $form['phones'][$col];
            }
            $pdo->prepare(
                'INSERT INTO ' . $T['table'] . ' (' . implode(', ', $cols) . ', created_at, updated_at) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ', NOW(), NOW())'
            )->execute($params);
            $newId = (int) $pdo->lastInsertId();
            $syncLines($pdo, $newId, $parsed['rows']);
            hef_audit_log($pdo, $companyId, $userId, $T['table'], $newId, 'create', null, ['name' => $form['name']]);
            header('Location: /hef/admin/' . $T['page']);
            exit;
        } catch (PDOException $e) {
            error_log($contactType . ' insert failed: ' . $e->getMessage());
            $error = 'Could not save ' . strtolower($T['label']) . '.';
            $addModalOpen = true;
        }
    }
}

// ---------------------------------------------------------------- the list
$show = in_array($_GET['show'] ?? 'active', ['active', 'closed', 'all'], true) ? $_GET['show'] : 'active';

$stmt = $pdo->prepare('SELECT status, COUNT(*) AS c FROM ' . $T['table'] . ' WHERE company_id = ? GROUP BY status');
$stmt->execute([$companyId]);
$counts = ['active' => 0, 'closed' => 0];
foreach ($stmt->fetchAll() as $r) {
    $counts[$r['status'] === 'active' ? 'active' : 'closed'] += (int) $r['c'];
}

$where = 'company_id = ?';
$params = [$companyId];
if ($show === 'active') {
    $where .= " AND status = 'active'";
} elseif ($show === 'closed') {
    $where .= " AND status <> 'active'";
}

$perPage = 12;
$page = hef_current_page();
$total = $show === 'all' ? $counts['active'] + $counts['closed'] : $counts[$show];
$offset = ($page - 1) * $perPage;
$stmt = $pdo->prepare('SELECT * FROM ' . $T['table'] . " WHERE {$where} ORDER BY (status = 'active') DESC, name LIMIT " . (int) $perPage . ' OFFSET ' . (int) $offset);
$stmt->execute($params);
$contacts = $stmt->fetchAll();
$ids = array_map('intval', array_column($contacts, 'id'));

// What each one needs / supplies: short badges for the card, editor lines for the edit dialog.
$cardLines = [];
$editLines = [];
if ($ids) {
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $detailCols = $isCustomer ? 'l.quantity' : 'l.rate, l.unit, l.moq';
    $stmt = $pdo->prepare(
        "SELECT l.{$T['fk']} AS contact_id, l.species_id, l.breed_id, l.age_days, {$detailCols}, s.name AS species_name, b.name AS breed_name
         FROM {$T['lines_table']} l
         JOIN species s ON s.id = l.species_id
         LEFT JOIN breeds b ON b.id = l.breed_id
         WHERE l.{$T['fk']} IN ({$marks})
         ORDER BY s.name, b.name, l.age_days, l.id"
    );
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $r) {
        $age = $r['age_days'] !== null ? (int) $r['age_days'] : null;
        [$ageValue, $ageUnit] = hef_age_parts($age);
        $cid = (int) $r['contact_id'];
        if ($isCustomer) {
            if ((int) $r['quantity'] <= 0) {
                continue;
            }
            $detail = (int) $r['quantity'];
            $editLines[$cid][] = ['species_id' => (int) $r['species_id'], 'breed_id' => $r['breed_id'] !== null ? (int) $r['breed_id'] : null, 'age_value' => $ageValue, 'age_unit' => $ageUnit, 'quantity' => (int) $r['quantity']];
        } else {
            $unit = in_array($r['unit'], ['kg', 'piece'], true) ? $r['unit'] : 'kg';
            $detail = '₹' . rtrim(rtrim(number_format((float) $r['rate'], 2, '.', ''), '0'), '.') . '/' . $unit . ' · MOQ ' . max(1, (int) $r['moq']);
            $editLines[$cid][] = ['species_id' => (int) $r['species_id'], 'breed_id' => $r['breed_id'] !== null ? (int) $r['breed_id'] : null, 'age_value' => $ageValue, 'age_unit' => $ageUnit, 'rate' => $r['rate'], 'unit' => $unit, 'moq' => (int) $r['moq']];
        }
        $cardLines[$cid][] = ['label' => hef_line_label($r['species_name'], $r['breed_name'], $age), 'detail' => (string) $detail];
    }
}
$notesByContact = hef_contact_notes_load($pdo, $companyId, $contactType, $ids);

/** The add / edit form fields (name, email, phone numbers, address, note). */
$renderFields = function (array $v, string $p) use ($T): void {
    $perRow = count($T['phone_cols']) > 2 ? 'col-6 col-md-3' : 'col-12 col-sm-6';
    ?>
    <div class="row g-2 mb-2">
        <div class="col-12 col-md-6"><label class="form-label small" for="<?= $p ?>Name">Name</label><input type="text" name="name" id="<?= $p ?>Name" class="form-control" required maxlength="255" value="<?= htmlspecialchars((string) $v['name']) ?>"></div>
        <div class="col-12 col-md-6"><label class="form-label small" for="<?= $p ?>Email">Email</label><input type="email" name="email" id="<?= $p ?>Email" class="form-control" maxlength="255" value="<?= htmlspecialchars((string) $v['email']) ?>"></div>
    </div>
    <div class="row g-2 mb-1">
        <?php foreach ($T['phone_cols'] as $i => $col): ?>
            <div class="<?= $perRow ?>"><?= hef_phone_input($col, $v[$col] ?? '', 'Phone ' . ($i + 1) . ($i === 0 ? '' : ' (optional)'), $p . 'Ph' . $i) ?></div>
        <?php endforeach; ?>
    </div>
    <div class="form-text mb-2">Type numbers any way you like, for example 98765 43210 or +91 98765 43210. They are all saved in the same format.</div>
    <div class="mb-2"><label class="form-label small" for="<?= $p ?>Address">Address</label><textarea name="address" id="<?= $p ?>Address" class="form-control" rows="2"><?= htmlspecialchars((string) $v['address']) ?></textarea></div>
    <div class="mb-2"><label class="form-label small" for="<?= $p ?>Notes">About them <span class="text-muted">(a short general note; call-by-call notes go in the notes button)</span></label><input type="text" name="notes" id="<?= $p ?>Notes" class="form-control" value="<?= htmlspecialchars((string) $v['notes']) ?>"></div>
    <?php
};

require_once __DIR__ . '/../admin/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h4 class="mb-0"><i class="bi <?= $T['icon'] ?> me-2"></i><?= $T['plural'] ?></h4>
    <div class="d-flex gap-2">
        <a href="/hef/admin/export.php?type=<?= $T['export'] ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-download me-1"></i>Export CSV</a>
        <?php if ($isOwner): ?>
            <button type="button" class="btn btn-hef text-white btn-sm" data-bs-toggle="modal" data-bs-target="#add<?= $Type ?>Modal"><i class="bi bi-plus-lg me-1"></i>Add <?= strtolower($T['label']) ?></button>
        <?php endif; ?>
    </div>
</div>

<?php if ($status): ?><div class="alert alert-success py-2"><?= htmlspecialchars($status) ?></div><?php endif; ?>
<?php if ($error && ! $addModalOpen): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<ul class="nav nav-pills mb-3 small">
    <?php foreach (['active' => 'Active (' . $counts['active'] . ')', 'closed' => $T['closed_label'] . ' (' . $counts['closed'] . ')', 'all' => 'All'] as $key => $label): ?>
        <li class="nav-item"><a class="nav-link py-1 <?= $show === $key ? 'active' : 'text-muted' ?>" href="?show=<?= $key ?>"><?= htmlspecialchars($label) ?></a></li>
    <?php endforeach; ?>
</ul>

<?php if (empty($contacts)): ?>
    <div class="card p-4 text-muted"><?= $show === 'closed' ? 'Nobody here.' : 'No ' . strtolower($T['plural']) . ' yet.' ?></div>
<?php endif; ?>

<div class="row g-3">
    <?php foreach ($contacts as $c): ?>
        <?php $cid = (int) $c['id']; ?>
        <div class="col-12 col-md-6 col-xl-4">
            <?php hef_render_contact_card([
                'type' => $contactType, 'row' => $c, 'tz' => $tz, 'is_owner' => $isOwner,
                'lines' => $cardLines[$cid] ?? [], 'notes' => $notesByContact[$cid] ?? [],
                'updated_text' => $c[$T['last_col']] ? 'Updated by the ' . $T['updated_by'] . ' on ' . hef_company_local($pdo, $c[$T['last_col']], $tz) : '',
            ]); ?>
        </div>
    <?php endforeach; ?>
</div>

<?= hef_pagination_links($page, $total, $perPage) ?>

<?php foreach ($contacts as $c): ?>
    <?php
        $cid = (int) $c['id'];
        $o = ['type' => $contactType, 'row' => $c, 'tz' => $tz, 'notes' => $notesByContact[$cid] ?? []];
        hef_render_notes_modal($o);
        hef_render_calendar_modal($o);
        if (! $isOwner) {
            continue;
        }
        hef_render_status_modal($o);
        $linkUrl = $c['update_token'] ? hef_public_link_url($contactType, $c['update_token']) : '';
        $shareMsg = $isCustomer
            ? 'Hello ' . $c['name'] . ', this is ' . $companyName . '. Please use this private link to tell us which animals you need, and how many: ' . $linkUrl
            : 'Hello ' . $c['name'] . ', this is ' . $companyName . '. Please use this private link to update the animals you supply and your rates: ' . $linkUrl;
    ?>
    <!-- Private link -->
    <div class="modal fade" id="link<?= $Type . $cid ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><?= $isCustomer ? 'Requirements link' : 'Update link' ?> — <?= htmlspecialchars($c['name']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if ($c['update_token']): ?>
                        <p class="small text-muted">Send this private link to <?= $isCustomer ? 'the customer' : 'the supplier' ?>. They can <?= $isCustomer ? 'add and change their own requirements' : 'update their own products, ages and rates' ?> from their phone with no login, and you get an email when they do. Anyone with the link can change their list, so share it only with them.</p>
                        <div class="input-group mb-2">
                            <input type="text" class="form-control" id="linkInput<?= $cid ?>" readonly value="<?= htmlspecialchars($linkUrl) ?>">
                            <button type="button" class="btn btn-outline-secondary" onclick="hefCopyLink('linkInput<?= $cid ?>', this)">Copy</button>
                        </div>
                        <div class="d-flex gap-2 align-items-center flex-wrap mb-3">
                            <a href="<?= htmlspecialchars($linkUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">Preview</a>
                            <?php $firstNumber = hef_contact_numbers($c, $T)[0] ?? null; ?>
                            <?php if ($firstNumber): ?><span class="small text-muted">Send by:</span> <?= hef_contact_buttons($firstNumber, $shareMsg) ?><?php endif; ?>
                        </div>
                        <div class="d-flex gap-2 border-top pt-3">
                            <form method="POST" onsubmit="return confirm('Create a new link? The old one stops working straight away.');">
                                <input type="hidden" name="contact_link" value="<?= $cid ?>"><input type="hidden" name="link_action" value="regenerate">
                                <button type="submit" class="btn btn-sm btn-outline-secondary">Make a new link</button>
                            </form>
                            <form method="POST" onsubmit="return confirm('Switch this link off? They will no longer be able to open it.');">
                                <input type="hidden" name="contact_link" value="<?= $cid ?>"><input type="hidden" name="link_action" value="disable">
                                <button type="submit" class="btn btn-sm btn-outline-danger">Switch off</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <p class="text-muted">Let <?= htmlspecialchars($c['name']) ?> keep their own list up to date. You'll get a private link to send them; they need no login, and you get an email whenever they make a change.</p>
                        <form method="POST">
                            <input type="hidden" name="contact_link" value="<?= $cid ?>"><input type="hidden" name="link_action" value="create">
                            <button type="submit" class="btn btn-hef text-white w-100">Create the link</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit -->
    <div class="modal fade" id="edit<?= $Type . $cid ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="edit_contact_id" value="<?= $cid ?>">
                    <div class="modal-header"><h5 class="modal-title">Edit <?= htmlspecialchars($c['name']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <?php $renderFields($c, 'edit' . $Type . $cid); ?>
                        <div class="mb-2">
                            <label class="form-label small"><?= $isCustomer ? 'Species needed' : 'Species supplied' ?></label>
                            <?php hef_render_line_editor($contactType, $editLines[$cid] ?? [], $allSpecies, $allBreeds); ?>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="submit" class="btn btn-hef text-white w-100">Save changes</button></div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php if ($isOwner): ?>
<!-- Add -->
<div class="modal fade" id="add<?= $Type ?>Modal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header"><h5 class="modal-title">Add <?= strtolower($T['label']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <?php if ($error && $addModalOpen): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                    <?php $renderFields($addValues, 'add' . $Type); ?>
                    <div class="mb-2">
                        <label class="form-label small"><?= $isCustomer ? 'Species needed' : 'Species supplied' ?></label>
                        <?php hef_render_line_editor($contactType, $addFormLines, $allSpecies, $allBreeds); ?>
                    </div>
                </div>
                <div class="modal-footer"><button type="submit" class="btn btn-hef text-white w-100">Add <?= strtolower($T['label']) ?></button></div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php hef_line_editor_script($allBreeds); ?>
<?php hef_contacts_script(); ?>
<script>
window.addEventListener('load', function () {
    var show = function (id) { var el = document.getElementById(id); if (el) { new bootstrap.Modal(el).show(); } };
    <?php if ($addModalOpen): ?>show('add<?= $Type ?>Modal');<?php endif; ?>
    <?php if ($openNotesFor): ?>show('notes<?= $Type . (int) $openNotesFor ?>');<?php endif; ?>
    <?php if ($reopenLinkFor): ?>show('link<?= $Type . (int) $reopenLinkFor ?>');<?php endif; ?>
});
</script>
<?php require_once __DIR__ . '/../admin/footer.php'; ?>
