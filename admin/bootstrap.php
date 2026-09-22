<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/global.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/alerts.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/plan_limits.php';
require_once __DIR__ . '/../includes/batch_stats.php';
require_once __DIR__ . '/../includes/payroll.php';

// Never show PHP errors to visitors: they can reveal file paths and database
// details. They still go to the server error log. To see them on screen while
// debugging, add  define('HEF_DEBUG', true);  to includes/config.php.
if (! (defined('HEF_DEBUG') && HEF_DEBUG)) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}
ini_set('log_errors', '1');

/**
 * Refuses form posts that come from another website (cross-site request
 * forgery). Browsers say where a request came from: Sec-Fetch-Site on modern
 * ones, otherwise the Origin / Referer header. Only posts from this same site
 * are let through. Reads (GET) are never blocked, and a request that carries
 * no origin information at all (some privacy tools strip it) is allowed, as
 * there is nothing to judge it by. Your login cookie is also SameSite=Lax, so
 * this is a second layer on top of that.
 */
function hef_block_cross_site_posts(): void
{
    if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }

    $fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if ($fetchSite === 'same-origin' || $fetchSite === 'none') {
        return;
    }

    $blocked = $fetchSite === 'cross-site';
    if (! $blocked) {
        // Older browser, or same-site but maybe another host: compare hosts.
        $source = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($source === '' || $source === 'null') {
            $source = $_SERVER['HTTP_REFERER'] ?? '';
        }
        if ($source === '') {
            return;
        }
        $sourceHost = strtolower((string) parse_url($source, PHP_URL_HOST));
        $siteHost = strtolower((string) preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
        $blocked = $sourceHost !== $siteHost;
    }

    if ($blocked) {
        error_log('HEFarm blocked cross-site POST to ' . ($_SERVER['SCRIPT_NAME'] ?? '?')
            . ' (Sec-Fetch-Site=' . $fetchSite . ', Origin=' . ($_SERVER['HTTP_ORIGIN'] ?? '') . ')');
        http_response_code(403);
        die('Request blocked: this form was submitted from another website. Please go back to HEFarm and try again.');
    }
}
hef_block_cross_site_posts();

$currentUser = hef_require_login($pdo);
hef_messaging_context($pdo, (int) $currentUser['company_id']); // lets the Call / SMS / WhatsApp buttons follow Settings
// Alert emails and subscription expiry now run only from the scheduled job
// (cron/run-alerts.php, daily in the morning window), not on page loads.
// To go back to running them whenever someone opens an admin page, remove
// the leading // below.
// hef_maybe_run_alert_checks($pdo);

$currentPage = basename($_SERVER['SCRIPT_NAME']);

/**
 * Sidebar menu. Related pages are grouped under collapsible headings
 * (native <details>, so it needs no JavaScript and works the same in the
 * mobile drawer). The group containing the current page opens by itself.
 * A group with only one visible page for this user is shown as a plain link,
 * and a group with none is left out.
 *
 * Each page is [file, icon, label, [pages that highlight it], visible?].
 */
function hef_nav_items(string $currentPage, bool $isSuperAdmin = false, string $role = 'worker', bool $hasProAccess = false, bool $companyHasPro = false): string
{
    $isOwner = $role === 'owner';

    $menu = [
        ['dashboard.php', 'bi-speedometer2', 'Dashboard', ['dashboard.php'], true],
        ['group', 'Farm', 'bi-tree', [
            ['batches.php', 'bi-egg-fried', 'Batches', ['batches.php', 'batch-view.php'], true],
            ['performance.php', 'bi-graph-up-arrow', 'Performance', ['performance.php'], true],
            ['species.php', 'bi-list-ul', 'Species', ['species.php'], true],
            ['rooms.php', 'bi-door-open', 'Rooms', ['rooms.php'], true],
            ['feed.php', 'bi-basket', 'Feed', ['feed.php'], $role !== 'vet'],
            ['health.php', 'bi-heart-pulse', 'Health & Vaccinations', ['health.php'], $isOwner || $role === 'vet'],
        ]],
        ['group', 'Sales & Contacts', 'bi-cart3', [
            ['storefront.php', 'bi-shop', 'Storefront', ['storefront.php'], $isOwner],
            ['bookings.php', 'bi-bag-check', 'Bookings', ['bookings.php'], $role !== 'vet'],
            ['customers.php', 'bi-person-badge', 'Customers', ['customers.php'], $hasProAccess],
            ['suppliers.php', 'bi-truck', 'Suppliers', ['suppliers.php'], $hasProAccess],
            ['requirements.php', 'bi-clipboard-check', 'Requirements', ['requirements.php'], $hasProAccess],
        ]],
        ['group', 'Staff', 'bi-person-lines-fill', [
            ['staff.php', 'bi-person-vcard', 'Staff', ['staff.php', 'staff-view.php'], $hasProAccess],
            ['staff-attendance.php', 'bi-calendar-check', 'Attendance', ['staff-attendance.php'], $hasProAccess || ($role === 'worker' && $companyHasPro)],
            ['staff-payroll.php', 'bi-cash-stack', 'Payroll', ['staff-payroll.php', 'staff-payslip.php'], $hasProAccess],
        ]],
        ['finances.php', 'bi-cash-coin', 'Finances', ['finances.php'], $isOwner],
        ['notes.php', 'bi-journal-check', 'Notes & Reminders', ['notes.php'], true],
        ['group', 'Account', 'bi-person-gear', [
            ['users.php', 'bi-people', 'Team', ['users.php'], $isOwner],
            ['devices.php', 'bi-phone', 'Devices', ['devices.php'], true],
            ['billing.php', 'bi-credit-card', 'Billing', ['billing.php', 'billing-pay.php'], $isOwner],
            ['profile.php', 'bi-person-circle', 'My Profile', ['profile.php'], true],
            ['settings.php', 'bi-gear', 'Settings', ['settings.php'], $isOwner],
            ['backup.php', 'bi-cloud-arrow-down', 'Backup', ['backup.php'], $isOwner],
        ]],
    ];

    if ($isSuperAdmin) {
        $menu[] = ['group', 'Super Admin', 'bi-shield-lock', [
            ['super-companies.php', 'bi-buildings', 'All Companies', ['super-companies.php', 'super-company-view.php'], true],
            ['super-plans.php', 'bi-tags', 'Manage Plans', ['super-plans.php'], true],
            ['super-audit-log.php', 'bi-clock-history', 'Audit Log', ['super-audit-log.php'], true],
            ['super-backup.php', 'bi-hdd-stack', 'Backup & Restore', ['super-backup.php'], true],
        ]];
    }

    // One page as a menu link (used for top-level pages and group children).
    $link = function (array $item) use ($currentPage): string {
        [$file, $icon, $label, $activeOn] = $item;
        $active = in_array($currentPage, $activeOn, true) ? ' active' : '';

        return '<li class="nav-item"><a href="/hef/admin/' . $file . '" class="nav-link' . $active . '">'
            . '<i class="bi ' . $icon . ' me-2"></i>' . htmlspecialchars($label) . '</a></li>';
    };

    $html = '';
    foreach ($menu as $entry) {
        if ($entry[0] !== 'group') {
            if ($entry[4]) {
                $html .= $link($entry);
            }
            continue;
        }

        [, $groupLabel, $groupIcon, $children] = $entry;
        $visible = array_values(array_filter($children, function ($c) {
            return $c[4];
        }));

        if (count($visible) === 0) {
            continue;
        }
        if (count($visible) === 1) {
            $html .= $link($visible[0]); // nothing to expand — just link it
            continue;
        }

        $hasActive = false;
        foreach ($visible as $c) {
            if (in_array($currentPage, $c[3], true)) {
                $hasActive = true;
                break;
            }
        }

        $html .= '<li class="nav-item"><details class="hef-nav-group"' . ($hasActive ? ' open' : '') . '>'
            . '<summary class="nav-link' . ($hasActive ? ' hef-group-active' : '') . '">'
            . '<i class="bi ' . $groupIcon . ' me-2"></i>' . htmlspecialchars($groupLabel)
            . '<i class="bi bi-chevron-right hef-chevron"></i></summary>'
            . '<ul class="nav flex-column hef-subnav gap-1">';
        foreach ($visible as $c) {
            $html .= $link($c);
        }
        $html .= '</ul></details></li>';
    }

    return $html;
}
