<?php
/**
 * Central role enforcement. Call hef_require_role() at the top of any
 * page that should be entirely off-limits to certain roles. For pages
 * that mix "everyone can view, only Owner can edit" (Batches,
 * Species, Rooms), check $currentUser['role'] inline around the
 * specific write actions instead — see those files for the pattern.
 *
 * Roles: owner (full access), worker (operational data entry), vet
 * (health/vaccination focus). Super-admin is a separate flag,
 * unrelated to these company-level roles.
 */
function hef_require_role(array $allowedRoles, string $currentUserRole): void
{
    if (! in_array($currentUserRole, $allowedRoles, true)) {
        header('Location: /hef/admin/dashboard.php?error=access_denied');
        exit;
    }
}

/**
 * Does this company currently have a Pro membership?
 *
 * "Pro" is not a hard-coded plan name — a super admin marks which
 * plan(s) count as Pro by ticking "Pro features" on Manage Plans
 * (subscription_plans.pro_features_enabled). A company qualifies when
 * it has an ACTIVE, un-expired subscription on such a plan.
 *
 * - The platform operator's own company (HEF_OWNER_COMPANY_ID) has
 *   lifetime access, same as it does for billing.
 * - Free trials do NOT count as Pro.
 * - The result is cached per request so the nav and the page guard
 *   don't each hit the database.
 */
function hef_company_has_pro(PDO $pdo, int $companyId): bool
{
    static $cache = [];

    if (isset($cache[$companyId])) {
        return $cache[$companyId];
    }

    if ($companyId === (int) HEF_OWNER_COMPANY_ID) {
        return $cache[$companyId] = true;
    }

    $stmt = $pdo->prepare(
        "SELECT 1
         FROM company_subscriptions cs
         JOIN subscription_plans sp ON sp.id = cs.subscription_plan_id
         JOIN companies c ON c.id = cs.company_id
         WHERE cs.company_id = ?
           AND cs.status = 'active'
           AND c.status = 'active'
           AND (cs.ends_at IS NULL OR cs.ends_at > NOW())
           AND sp.pro_features_enabled = 1
         LIMIT 1"
    );
    $stmt->execute([$companyId]);

    return $cache[$companyId] = (bool) $stmt->fetchColumn();
}

/**
 * Can this user open Customers / Suppliers / Requirements?
 * Only the company Owner, and only while the company is on Pro.
 * Used by both the page guard and the sidebar so they never disagree.
 */
function hef_user_can_access_pro_pages(PDO $pdo, array $user): bool
{
    return ($user['role'] ?? '') === 'owner'
        && hef_company_has_pro($pdo, (int) $user['company_id']);
}

/**
 * Page guard for the Pro-only pages. Call right after bootstrap.php,
 * before any output.
 *
 * - Non-owners (worker / vet) are sent back to the dashboard.
 * - Owners without Pro are sent to Billing with an upgrade prompt.
 */
function hef_require_pro(PDO $pdo, array $user): void
{
    if (($user['role'] ?? '') !== 'owner') {
        header('Location: /hef/admin/dashboard.php?error=access_denied');
        exit;
    }

    if (! hef_company_has_pro($pdo, (int) $user['company_id'])) {
        header('Location: /hef/admin/billing.php?upgrade=pro');
        exit;
    }
}
