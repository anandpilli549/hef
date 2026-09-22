<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/permissions.php';
hef_require_role(['owner'], $currentUser['role']);
require_once __DIR__ . '/../includes/uploads.php';

$companyId = $currentUser['company_id'];
$error = '';
$status = '';
$hasPro = hef_company_has_pro($pdo, (int) $companyId);

$tabs = [
    'profile' => ['Company profile', 'bi-building'],
    'refund' => ['Refund policy', 'bi-arrow-return-left'],
    'razorpay' => ['Razorpay', 'bi-credit-card'],
    'sms' => ['SMS', 'bi-chat-dots'],
    'whatsapp' => ['WhatsApp', 'bi-whatsapp'],
    'app' => ['App settings', 'bi-sliders'],
];
$activeTab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'profile';

/**
 * Saves some columns of this company's notification_settings row (created on
 * first use). $cols is column => value; only fixed column names are passed in.
 */
function hef_save_notification_cols(PDO $pdo, $companyId, array $cols): void
{
    $stmt = $pdo->prepare('SELECT id FROM notification_settings WHERE company_id = ?');
    $stmt->execute([$companyId]);
    if ($stmt->fetch()) {
        $sets = implode(', ', array_map(function ($c) { return $c . ' = ?'; }, array_keys($cols)));
        $pdo->prepare('UPDATE notification_settings SET ' . $sets . ', updated_at = NOW() WHERE company_id = ?')
            ->execute(array_merge(array_values($cols), [$companyId]));
    } else {
        $names = implode(', ', array_keys($cols));
        $marks = implode(', ', array_fill(0, count($cols), '?'));
        $pdo->prepare('INSERT INTO notification_settings (company_id, ' . $names . ', created_at, updated_at) VALUES (?, ' . $marks . ', NOW(), NOW())')
            ->execute(array_merge([$companyId], array_values($cols)));
    }
}

// Current saved values (needed by the handlers that keep a secret when its box is left blank).
$stmt = $pdo->prepare('SELECT * FROM notification_settings WHERE company_id = ?');
$stmt->execute([$companyId]);
$notificationSettings = $stmt->fetch() ?: [];
$stmt = $pdo->prepare('SELECT * FROM payment_settings WHERE company_id = ?');
$stmt->execute([$companyId]);
$paymentSettings = $stmt->fetch() ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_company_profile'])) {
    $activeTab = 'profile';
    $name = trim($_POST['company_name'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $timezone = trim($_POST['timezone'] ?? 'Asia/Kolkata');
    $currency = strtoupper(trim($_POST['currency_code'] ?? 'INR'));

    if (! $name) {
        $error = 'Company name is required.';
    } elseif (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
        $error = 'Choose a valid time zone, for example Asia/Kolkata.';
    } elseif (! preg_match('/^[A-Z]{3}$/', $currency)) {
        $error = 'The currency must be a 3-letter code such as INR.';
    } else {
        try {
            $logoPath = hef_handle_image_upload('logo', 'companies');
            $bannerPath = hef_handle_image_upload('banner', 'companies');

            $stmt = $pdo->prepare('SELECT logo_path, banner_path FROM companies WHERE id = ?');
            $stmt->execute([$companyId]);
            $existingPaths = $stmt->fetch();

            $sql = 'UPDATE companies SET name = ?, address = ?, timezone = ?, currency_code = ?';
            $params = [$name, $address ?: null, $timezone, $currency];

            if ($logoPath) {
                $sql .= ', logo_path = ?';
                $params[] = $logoPath;
            }
            if ($bannerPath) {
                $sql .= ', banner_path = ?';
                $params[] = $bannerPath;
            }
            $sql .= ', updated_at = NOW() WHERE id = ?';
            $params[] = $companyId;

            $pdo->prepare($sql)->execute($params);

            if ($logoPath) hef_delete_uploaded_image($existingPaths['logo_path'] ?? null);
            if ($bannerPath) hef_delete_uploaded_image($existingPaths['banner_path'] ?? null);

            $status = 'Company profile updated.';
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        } catch (PDOException $e) {
            error_log('company profile update failed: ' . $e->getMessage());
            $error = 'Could not save company profile.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_payroll_settings']) && $hasPro) {
    $activeTab = 'app';
    $salaryDay = (int) ($_POST['salary_day'] ?? 5);
    $divisor = (int) ($_POST['salary_divisor'] ?? 0);
    if ($salaryDay < 1 || $salaryDay > 31 || ! in_array($divisor, [0, 26, 30], true)) {
        $error = 'Choose a salary day between 1 and 31.';
    } else {
        $pdo->prepare('UPDATE companies SET salary_day = ?, salary_divisor = ?, updated_at = NOW() WHERE id = ?')->execute([$salaryDay, $divisor, $companyId]);
        $status = 'Payroll settings saved.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_refund_policy'])) {
    $activeTab = 'refund';
    $refundPercentage = (float) ($_POST['refund_percentage'] ?? 100);
    $replacementAllowed = isset($_POST['replacement_allowed']) ? 1 : 0;
    $slaHours = (int) ($_POST['response_sla_hours'] ?? 48);
    $notes = trim($_POST['refund_notes'] ?? '');

    if ($refundPercentage < 0 || $refundPercentage > 100) {
        $error = 'Refund percentage must be between 0 and 100.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM refund_policies WHERE company_id = ?');
        $stmt->execute([$companyId]);
        $existing = $stmt->fetch();

        try {
            if ($existing) {
                $pdo->prepare(
                    'UPDATE refund_policies SET refund_percentage = ?, replacement_allowed = ?, response_sla_hours = ?, notes = ?, updated_at = NOW()
                     WHERE company_id = ?'
                )->execute([$refundPercentage, $replacementAllowed, $slaHours, $notes ?: null, $companyId]);
            } else {
                $pdo->prepare(
                    'INSERT INTO refund_policies (company_id, refund_percentage, replacement_allowed, response_sla_hours, notes, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
                )->execute([$companyId, $refundPercentage, $replacementAllowed, $slaHours, $notes ?: null]);
            }
            $status = 'Refund policy saved.';
        } catch (PDOException $e) {
            error_log('refund_policies save failed: ' . $e->getMessage());
            $error = 'Could not save refund policy.';
        }
    }
}

// SMS (MSG91). A secret left blank keeps what is saved; the checkbox removes it.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_sms_settings'])) {
    $activeTab = 'sms';
    $key = trim($_POST['msg91_auth_key'] ?? '');
    $sender = trim($_POST['msg91_sender_id'] ?? '');
    $apiUrl = trim($_POST['sms_api_url'] ?? '');
    if (! empty($_POST['clear_msg91_key'])) {
        $key = null;
    } elseif ($key === '') {
        $key = $notificationSettings['msg91_auth_key'] ?? null;
    }
    if ($apiUrl !== '' && ! hef_is_safe_public_https_url($apiUrl)) {
        $error = 'The API address must be a public https address, for example https://api.msg91.com/api/v5/flow/';
    } else {
        try {
            hef_save_notification_cols($pdo, $companyId, ['msg91_auth_key' => $key ?: null, 'msg91_sender_id' => $sender ?: null, 'sms_api_url' => $apiUrl ?: null]);
            $notificationSettings['msg91_auth_key'] = $key ?: null;
            $notificationSettings['msg91_sender_id'] = $sender ?: null;
            $notificationSettings['sms_api_url'] = $apiUrl ?: null;
            $status = 'SMS settings saved.';
        } catch (PDOException $e) {
            error_log('sms settings save failed: ' . $e->getMessage());
            $error = 'Could not save SMS settings.';
        }
    }
}

// WhatsApp (Meta Business Cloud API).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_whatsapp_settings'])) {
    $activeTab = 'whatsapp';
    $phoneId = trim($_POST['whatsapp_phone_number_id'] ?? '');
    $token = trim($_POST['whatsapp_access_token'] ?? '');
    $alertTemplate = trim($_POST['whatsapp_alert_template'] ?? '');
    $bookingTemplate = trim($_POST['whatsapp_booking_template'] ?? '');
    $messageTemplate = trim($_POST['whatsapp_message_template'] ?? '');
    $enabled = ! empty($_POST['whatsapp_enabled']) ? 1 : 0;
    if (! empty($_POST['clear_whatsapp_token'])) {
        $token = null;
    } elseif ($token === '') {
        $token = $notificationSettings['whatsapp_access_token'] ?? null;
    }
    if ($enabled && (! $phoneId || ! $token)) {
        $error = 'To turn WhatsApp API sending on, save both the Phone Number ID and the Access Token.';
    } else {
        try {
            hef_save_notification_cols($pdo, $companyId, [
                'whatsapp_phone_number_id' => $phoneId ?: null, 'whatsapp_access_token' => $token ?: null,
                'whatsapp_alert_template' => $alertTemplate ?: null, 'whatsapp_booking_template' => $bookingTemplate ?: null,
                'whatsapp_message_template' => $messageTemplate ?: null, 'whatsapp_enabled' => $enabled,
            ]);
            $notificationSettings['whatsapp_phone_number_id'] = $phoneId ?: null;
            $notificationSettings['whatsapp_access_token'] = $token ?: null;
            $notificationSettings['whatsapp_alert_template'] = $alertTemplate ?: null;
            $notificationSettings['whatsapp_booking_template'] = $bookingTemplate ?: null;
            $notificationSettings['whatsapp_message_template'] = $messageTemplate ?: null;
            $notificationSettings['whatsapp_enabled'] = $enabled;
            $status = 'WhatsApp settings saved. ' . ($enabled ? 'The WhatsApp buttons now send through your business account.' : 'The WhatsApp buttons open WhatsApp (the app on a phone, WhatsApp Web on a computer).');
        } catch (PDOException $e) {
            error_log('whatsapp settings save failed: ' . $e->getMessage());
            $error = 'Could not save WhatsApp settings.';
        }
    }
}

// Razorpay. The secret boxes are never pre-filled; leaving one blank keeps the saved value.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_payment_settings'])) {
    $activeTab = 'razorpay';
    $pdo->prepare('DELETE FROM payment_settings WHERE company_id = ?')->execute([$companyId]);
    $paymentSettings = [];
    $status = 'Razorpay credentials removed.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_payment_settings'])) {
    $activeTab = 'razorpay';
    $keyId = trim($_POST['razorpay_key_id'] ?? '');
    $keySecret = trim($_POST['razorpay_key_secret'] ?? '');
    $webhookSecret = trim($_POST['razorpay_webhook_secret'] ?? '');
    if ($keySecret === '') {
        $keySecret = $paymentSettings['razorpay_key_secret'] ?? '';
    }
    if ($webhookSecret === '') {
        $webhookSecret = $paymentSettings['razorpay_webhook_secret'] ?? '';
    }

    try {
        if ($paymentSettings) {
            $pdo->prepare('UPDATE payment_settings SET razorpay_key_id = ?, razorpay_key_secret = ?, razorpay_webhook_secret = ?, updated_at = NOW() WHERE company_id = ?')
                ->execute([$keyId ?: null, $keySecret ?: null, $webhookSecret ?: null, $companyId]);
        } else {
            $pdo->prepare('INSERT INTO payment_settings (company_id, razorpay_key_id, razorpay_key_secret, razorpay_webhook_secret, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())')
                ->execute([$companyId, $keyId ?: null, $keySecret ?: null, $webhookSecret ?: null]);
        }
        $paymentSettings = ['razorpay_key_id' => $keyId, 'razorpay_key_secret' => $keySecret, 'razorpay_webhook_secret' => $webhookSecret];
        $status = 'Payment settings saved.';
    } catch (PDOException $e) {
        error_log('payment_settings save failed: ' . $e->getMessage());
        $error = 'Could not save settings.';
    }
}

require_once __DIR__ . '/header.php';

$stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
$stmt->execute([$companyId]);
$company = $stmt->fetch();

$stmt = $pdo->prepare('SELECT * FROM refund_policies WHERE company_id = ?');
$stmt->execute([$companyId]);
$refundPolicy = $stmt->fetch();

$stmt = $pdo->prepare('SELECT salary_day, salary_divisor FROM companies WHERE id = ?');
$stmt->execute([$companyId]);
$payrollRow = $stmt->fetch() ?: ['salary_day' => 5, 'salary_divisor' => 0];

// A green tick on a tab once that integration has what it needs.
$configured = [
    'razorpay' => ! empty($paymentSettings['razorpay_key_id']) && ! empty($paymentSettings['razorpay_key_secret']),
    'sms' => ! empty($notificationSettings['msg91_auth_key']),
    'whatsapp' => ! empty($notificationSettings['whatsapp_enabled']) && ! empty($notificationSettings['whatsapp_phone_number_id']) && ! empty($notificationSettings['whatsapp_access_token']),
];
?>
<h4 class="mb-3"><i class="bi bi-gear me-2"></i>Settings</h4>

<?php if ($status): ?><div class="alert alert-success py-2"><?= htmlspecialchars($status) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<ul class="nav nav-tabs flex-nowrap overflow-auto mb-3" id="settingsTabs" role="tablist" style="white-space:nowrap;">
    <?php foreach ($tabs as $key => [$label, $icon]): ?>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $key === $activeTab ? 'active' : '' ?>" id="tab-btn-<?= $key ?>" data-tab="<?= $key ?>" data-bs-toggle="tab" data-bs-target="#tab-<?= $key ?>" type="button" role="tab">
                <i class="bi <?= $icon ?> me-1"></i><?= htmlspecialchars($label) ?>
                <?php if (! empty($configured[$key])): ?><i class="bi bi-check-circle-fill text-success ms-1" title="Set up"></i><?php endif; ?>
            </button>
        </li>
    <?php endforeach; ?>
</ul>

<div class="tab-content" style="max-width: 640px;">

    <!-- Company profile -->
    <div class="tab-pane fade <?= $activeTab === 'profile' ? 'show active' : '' ?>" id="tab-profile" role="tabpanel">
        <div class="card p-4">
            <h6 class="mb-3">Company profile</h6>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="save_company_profile" value="1">
                <div class="mb-2">
                    <label class="form-label small">Logo <?= $company['logo_path'] ? '(leave blank to keep current)' : '' ?></label>
                    <?php if ($company['logo_path']): ?><img src="/hef/<?= htmlspecialchars($company['logo_path']) ?>" style="height:50px;" class="mb-1 d-block"><?php endif; ?>
                    <input type="file" name="logo" class="form-control" accept="image/*">
                </div>
                <div class="mb-2">
                    <label class="form-label small">Banner <?= $company['banner_path'] ? '(leave blank to keep current)' : '' ?></label>
                    <?php if ($company['banner_path']): ?><img src="/hef/<?= htmlspecialchars($company['banner_path']) ?>" style="height:60px; width:100%; object-fit:cover;" class="mb-1 d-block rounded"><?php endif; ?>
                    <input type="file" name="banner" class="form-control" accept="image/*">
                </div>
                <div class="mb-2">
                    <label class="form-label small">Company name</label>
                    <input type="text" name="company_name" class="form-control" required value="<?= htmlspecialchars($company['name']) ?>">
                </div>
                <div class="mb-2">
                    <label class="form-label small">Location / address</label>
                    <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($company['address'] ?? '') ?></textarea>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-8">
                        <label class="form-label small">Time zone</label>
                        <input type="text" name="timezone" class="form-control" list="timezoneList" value="<?= htmlspecialchars($company['timezone']) ?>">
                        <datalist id="timezoneList"><?php foreach (DateTimeZone::listIdentifiers() as $tz): ?><option value="<?= htmlspecialchars($tz) ?>"><?php endforeach; ?></datalist>
                    </div>
                    <div class="col-4">
                        <label class="form-label small">Currency</label>
                        <input type="text" name="currency_code" class="form-control text-uppercase" value="<?= htmlspecialchars($company['currency_code']) ?>" maxlength="3">
                    </div>
                </div>
                <button type="submit" class="btn btn-hef text-white w-100">Save company profile</button>
            </form>
        </div>
    </div>

    <!-- Refund policy -->
    <div class="tab-pane fade <?= $activeTab === 'refund' ? 'show active' : '' ?>" id="tab-refund" role="tabpanel">
        <div class="card p-4">
            <h6 class="mb-3">Refund policy</h6>
            <p class="text-muted small">This is your default policy for handling refunds — e.g. if a booked animal dies or becomes unavailable before pickup/delivery. It's a reference for you when processing refunds in Bookings; it doesn't auto-execute refunds on its own.</p>
            <form method="POST">
                <input type="hidden" name="save_refund_policy" value="1">
                <div class="mb-2">
                    <label class="form-label small">Default refund percentage</label>
                    <input type="number" name="refund_percentage" class="form-control" min="0" max="100" step="1"
                        value="<?= htmlspecialchars((string) ($refundPolicy['refund_percentage'] ?? 100)) ?>">
                </div>
                <div class="form-check mb-2">
                    <input type="checkbox" name="replacement_allowed" class="form-check-input" id="replacementAllowed"
                        <?= (! $refundPolicy || $refundPolicy['replacement_allowed']) ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="replacementAllowed">Offer a replacement instead of a refund, if the customer prefers</label>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Response time (hours)</label>
                    <input type="number" name="response_sla_hours" class="form-control" min="1"
                        value="<?= htmlspecialchars((string) ($refundPolicy['response_sla_hours'] ?? 48)) ?>">
                    <div class="form-text">How quickly you commit to responding to a refund request.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Policy notes (shown to you as a reminder, not published)</label>
                    <textarea name="refund_notes" class="form-control" rows="2" placeholder="e.g. Full refund for deaths before pickup; no refund after delivery is accepted."><?= htmlspecialchars($refundPolicy['notes'] ?? '') ?></textarea>
                </div>
                <button type="submit" class="btn btn-hef text-white w-100">Save refund policy</button>
            </form>
        </div>
    </div>

    <!-- Razorpay -->
    <div class="tab-pane fade <?= $activeTab === 'razorpay' ? 'show active' : '' ?>" id="tab-razorpay" role="tabpanel">
        <div class="card p-4">
            <h6 class="mb-3">Razorpay payment credentials</h6>
            <form method="POST" autocomplete="off">
                <input type="hidden" name="save_payment_settings" value="1">
                <div class="mb-2">
                    <label class="form-label small">Razorpay Key ID</label>
                    <input type="text" name="razorpay_key_id" class="form-control" value="<?= htmlspecialchars($paymentSettings['razorpay_key_id'] ?? '') ?>" placeholder="rzp_live_...">
                </div>
                <div class="mb-3">
                    <label class="form-label small">Razorpay Key Secret</label>
                    <input type="password" name="razorpay_key_secret" class="form-control" autocomplete="new-password"
                        placeholder="<?= ! empty($paymentSettings['razorpay_key_secret']) ? '•••••••• saved — leave blank to keep' : 'Paste your key secret' ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label small">Razorpay Webhook Secret</label>
                    <input type="password" name="razorpay_webhook_secret" class="form-control" autocomplete="new-password"
                        placeholder="<?= ! empty($paymentSettings['razorpay_webhook_secret']) ? '•••••••• saved — leave blank to keep' : 'Paste your webhook secret' ?>">
                    <div class="form-text">
                        In Razorpay Dashboard → Settings → Webhooks, add <code>https://cthkennels.com/hef/webhook.php</code>, subscribe to <strong>payment.captured</strong>, and paste the secret it gives you here. This makes payment confirmation reliable even if a customer closes their browser right after paying.
                    </div>
                </div>
                <button type="submit" class="btn btn-hef text-white w-100 mb-2">Save credentials</button>
            </form>

            <?php if ($paymentSettings): ?>
                <form method="POST" onsubmit="return confirm('Remove your saved Razorpay credentials? The storefront won\'t be able to accept payments until you add them again.');">
                    <input type="hidden" name="delete_payment_settings" value="1">
                    <button type="submit" class="btn btn-outline-danger w-100">Remove credentials</button>
                </form>
            <?php endif; ?>

            <div class="alert alert-info small mt-3 mb-0">
                Get your keys from the Razorpay Dashboard → Settings → API Keys. Use test keys while you're setting things up, switch to live keys when you're ready to accept real payments.
            </div>
        </div>
    </div>

    <!-- SMS -->
    <div class="tab-pane fade <?= $activeTab === 'sms' ? 'show active' : '' ?>" id="tab-sms" role="tabpanel">
        <div class="card p-4">
            <h6 class="mb-3">SMS (MSG91)</h6>
            <p class="text-muted small mb-2">Add your SMS provider details to send SMS alerts (vaccination due, feed low) and booking confirmations, and to send SMS from the SMS button on a computer.</p>
            <div class="alert alert-light border small py-2">
                <i class="bi bi-phone me-1"></i>On a <strong>phone</strong>, the SMS button opens your phone's own messaging app, so it is sent from your SIM card and needs none of this.
                On a <strong>computer</strong>, it sends through the API below.
            </div>
            <form method="POST" autocomplete="off">
                <input type="hidden" name="save_sms_settings" value="1">
                <div class="mb-2">
                    <label class="form-label small">MSG91 Auth Key</label>
                    <input type="password" name="msg91_auth_key" class="form-control" autocomplete="new-password"
                        placeholder="<?= ! empty($notificationSettings['msg91_auth_key']) ? '•••••••• saved — leave blank to keep' : 'Paste your auth key' ?>">
                    <?php if (! empty($notificationSettings['msg91_auth_key'])): ?>
                        <div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="clear_msg91_key" value="1" id="clearMsg91"><label class="form-check-label small text-danger" for="clearMsg91">Remove the saved key</label></div>
                    <?php endif; ?>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Sender ID</label>
                    <input type="text" name="msg91_sender_id" class="form-control" value="<?= htmlspecialchars($notificationSettings['msg91_sender_id'] ?? '') ?>" placeholder="HEFAPP" maxlength="6">
                    <div class="form-text">The 6-letter name your SMS show as coming from.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label small">API address</label>
                    <input type="url" name="sms_api_url" class="form-control" value="<?= htmlspecialchars($notificationSettings['sms_api_url'] ?? '') ?>" placeholder="https://api.msg91.com/api/v5/flow/">
                    <div class="form-text">Leave blank to use MSG91's standard address. It must be a public https address.</div>
                </div>
                <button type="submit" class="btn btn-hef text-white w-100">Save SMS settings</button>
            </form>
        </div>
    </div>

    <!-- WhatsApp -->
    <div class="tab-pane fade <?= $activeTab === 'whatsapp' ? 'show active' : '' ?>" id="tab-whatsapp" role="tabpanel">
        <div class="card p-4">
            <h6 class="mb-3">WhatsApp (Meta Business Cloud API)</h6>
            <form method="POST" autocomplete="off">
                <input type="hidden" name="save_whatsapp_settings" value="1">

                <div class="form-check form-switch mb-1">
                    <input class="form-check-input" type="checkbox" role="switch" name="whatsapp_enabled" id="waEnabled" value="1" <?= ! empty($notificationSettings['whatsapp_enabled']) ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold" for="waEnabled">Send WhatsApp through my business account (API)</label>
                </div>
                <div class="form-text mb-3">
                    <strong>On:</strong> the WhatsApp buttons in Customers, Suppliers and Requirements send the message through your WhatsApp Business API, and alerts and booking confirmations use it too.<br>
                    <strong>Off:</strong> those buttons just open WhatsApp: the app on a phone, WhatsApp Web on a computer.
                </div>
                <div class="mb-2">
                    <label class="form-label small">Phone Number ID</label>
                    <input type="text" name="whatsapp_phone_number_id" class="form-control" value="<?= htmlspecialchars($notificationSettings['whatsapp_phone_number_id'] ?? '') ?>">
                </div>
                <div class="mb-2">
                    <label class="form-label small">Access Token</label>
                    <input type="password" name="whatsapp_access_token" class="form-control" autocomplete="new-password"
                        placeholder="<?= ! empty($notificationSettings['whatsapp_access_token']) ? '•••••••• saved — leave blank to keep' : 'Paste your access token' ?>">
                    <?php if (! empty($notificationSettings['whatsapp_access_token'])): ?>
                        <div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="clear_whatsapp_token" value="1" id="clearWaToken"><label class="form-check-label small text-danger" for="clearWaToken">Remove the saved token</label></div>
                    <?php endif; ?>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Alert template name</label>
                    <input type="text" name="whatsapp_alert_template" class="form-control" value="<?= htmlspecialchars($notificationSettings['whatsapp_alert_template'] ?? '') ?>" placeholder="e.g. hef_alert">
                </div>
                <div class="mb-2">
                    <label class="form-label small">Booking confirmation template name</label>
                    <input type="text" name="whatsapp_booking_template" class="form-control" value="<?= htmlspecialchars($notificationSettings['whatsapp_booking_template'] ?? '') ?>" placeholder="e.g. hef_booking_confirmed">
                </div>
                <div class="mb-3">
                    <label class="form-label small">Message template for the WhatsApp buttons <span class="text-muted">(optional)</span></label>
                    <input type="text" name="whatsapp_message_template" class="form-control" value="<?= htmlspecialchars($notificationSettings['whatsapp_message_template'] ?? '') ?>" placeholder="e.g. hef_message">
                    <div class="form-text">An approved template whose whole body is just <code>{{1}}</code>. Without it, typed messages only reach people who messaged you in the last 24 hours (a WhatsApp rule). If a message can't be sent, you're offered a link to open WhatsApp instead.</div>
                </div>
                <div class="alert alert-warning small">
                    WhatsApp requires templates pre-approved in your Meta Business Manager before they can be used — leave the template names blank until you've created them and had them approved.
                </div>
                <button type="submit" class="btn btn-hef text-white w-100">Save WhatsApp settings</button>
            </form>
        </div>
    </div>

    <!-- App settings -->
    <div class="tab-pane fade <?= $activeTab === 'app' ? 'show active' : '' ?>" id="tab-app" role="tabpanel">
        <div class="card p-4 mb-3">
            <h6 class="mb-3">Staff payroll</h6>
            <?php if ($hasPro): ?>
                <form method="POST">
                    <input type="hidden" name="save_payroll_settings" value="1">
                    <div class="mb-2">
                        <label class="form-label small">Salary day of the month</label>
                        <input type="number" name="salary_day" class="form-control" min="1" max="31" required value="<?= (int) $payrollRow['salary_day'] ?>">
                        <div class="form-text">Salary for a month is paid on this day of the next month (default the 5th). In a shorter month, the last day is used.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">A day's pay is worked out as monthly salary divided by</label>
                        <select name="salary_divisor" class="form-select">
                            <option value="0" <?= (int) $payrollRow['salary_divisor'] === 0 ? 'selected' : '' ?>>The actual days in that month</option>
                            <option value="30" <?= (int) $payrollRow['salary_divisor'] === 30 ? 'selected' : '' ?>>30 days</option>
                            <option value="26" <?= (int) $payrollRow['salary_divisor'] === 26 ? 'selected' : '' ?>>26 working days</option>
                        </select>
                        <div class="form-text">Used to cut pay for absences and to pro-rate someone who joins or leaves mid-month.</div>
                    </div>
                    <button type="submit" class="btn btn-hef text-white">Save</button>
                </form>
            <?php else: ?>
                <p class="text-muted small mb-0">Staff, attendance and payroll are part of Pro plans. <a href="/hef/admin/billing.php">See plans</a></p>
            <?php endif; ?>
        </div>

        <div class="card p-4 mb-3">
            <h6 class="mb-2">Your data</h6>
            <p class="text-muted small mb-2">Download an encrypted copy of your farm's data, or restore from one.</p>
            <a href="/hef/admin/backup.php" class="btn btn-outline-secondary btn-sm align-self-start"><i class="bi bi-cloud-arrow-down me-1"></i>Backup &amp; restore</a>
        </div>

        <div class="card p-4">
            <h6 class="mb-2">Your emails</h6>
            <p class="text-muted small mb-2">Each person chooses their own alert and daily-summary emails on their profile page.</p>
            <a href="/hef/admin/profile.php" class="btn btn-outline-secondary btn-sm align-self-start"><i class="bi bi-envelope me-1"></i>My profile</a>
        </div>
    </div>
</div>

<script>
// Remember the open tab in the address bar, so a refresh or a shared link comes back to it.
document.querySelectorAll('#settingsTabs [data-bs-toggle="tab"]').forEach(function (btn) {
    btn.addEventListener('shown.bs.tab', function (e) {
        history.replaceState(null, '', '?tab=' + e.target.dataset.tab);
    });
});
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
