<?php
/**
 * Plan limits: what a company's subscription plan allows.
 *
 * Set per plan in Manage Plans:
 *   - Max users    (team members; pending invites count, disabled users don't)
 *   - Max batches  (batches with status "active"; sold-out and closed don't count)
 *   - Storefront   (whether the public store and online booking are on)
 * A max of 0 (or blank) means unlimited.
 *
 * Which plan applies: the company's active, unexpired subscription. A company
 * with none, such as one on the free trial, is not limited, and neither is
 * the platform operator's own company (HEF_OWNER_COMPANY_ID). Companies that
 * are already over a limit, say after moving to a smaller plan, keep what
 * they have; they just can't add more until they are back under it.
 */

require_once __DIR__ . '/config.php';

/** The company's active plan row, or null when no plan limits apply. */
function hef_company_plan(PDO $pdo, int $companyId): ?array
{
    static $cache = [];

    if (array_key_exists($companyId, $cache)) {
        return $cache[$companyId];
    }
    if ($companyId === (int) HEF_OWNER_COMPANY_ID) {
        return $cache[$companyId] = null;
    }

    $stmt = $pdo->prepare(
        "SELECT sp.*
         FROM company_subscriptions cs
         JOIN subscription_plans sp ON sp.id = cs.subscription_plan_id
         WHERE cs.company_id = ?
           AND cs.status = 'active'
           AND (cs.ends_at IS NULL OR cs.ends_at > NOW())
         ORDER BY cs.id DESC
         LIMIT 1"
    );
    $stmt->execute([$companyId]);
    $row = $stmt->fetch();

    return $cache[$companyId] = ($row ?: null);
}

/** The limit for 'users' or 'batches', or null when unlimited. */
function hef_plan_limit(PDO $pdo, int $companyId, string $what): ?int
{
    $column = $what === 'users' ? 'max_users' : 'max_batches';
    $plan = hef_company_plan($pdo, $companyId);
    if ($plan === null) {
        return null;
    }
    $limit = (int) ($plan[$column] ?? 0);

    return $limit > 0 ? $limit : null;
}

/** How many of 'users' or 'batches' the company is currently using. */
function hef_plan_usage(PDO $pdo, int $companyId, string $what): int
{
    $sql = $what === 'users'
        ? "SELECT COUNT(*) FROM users WHERE company_id = ? AND status IN ('active', 'invited')"
        : "SELECT COUNT(*) FROM batches WHERE company_id = ? AND status = 'active'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$companyId]);

    return (int) $stmt->fetchColumn();
}

/**
 * Null when one more 'users' or 'batches' fits the plan, otherwise a
 * message to show the user.
 */
function hef_limit_reached(PDO $pdo, int $companyId, string $what): ?string
{
    $limit = hef_plan_limit($pdo, $companyId, $what);
    if ($limit === null || hef_plan_usage($pdo, $companyId, $what) < $limit) {
        return null;
    }

    $plan = hef_company_plan($pdo, $companyId);
    if ($what === 'users') {
        $noun = $limit === 1 ? 'team member' : 'team members';
        $extra = ' Pending invites count towards this limit.';
    } else {
        $noun = $limit === 1 ? 'active batch' : 'active batches';
        $extra = ' Sold-out and closed batches don\'t count.';
    }

    return 'Your ' . $plan['name'] . ' plan allows up to ' . $limit . ' ' . $noun . '.' . $extra
        . ' Upgrade your plan on the Billing page to add more.';
}

/** Does the company's plan include the public storefront and online booking? */
function hef_company_storefront_allowed(PDO $pdo, int $companyId): bool
{
    $plan = hef_company_plan($pdo, $companyId);

    return $plan === null || (int) $plan['storefront_enabled'] === 1;
}
