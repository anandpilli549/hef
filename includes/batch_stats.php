<?php
/**
 * Batch performance figures, worked out live from what is already recorded:
 * mortality, animals sold, age, the sell-by date, and (for the Owner) costs,
 * revenue and profit.
 *
 *  - Mortality %  = animals recorded dead / animals the batch started with.
 *  - Sell-by date = date acquired + (the species' "max days to sell" minus
 *                   the age when acquired). Only when the species has that set.
 *  - Costs        = expenses logged against the batch (including its purchase
 *                   cost) + feed consumption costs + vaccination costs +
 *                   medicine costs. Feed purchases themselves are company
 *                   expenses, not tied to a batch, so they aren't counted twice.
 *                   If you also log a feed or vaccine cost as a batch expense in
 *                   Finances, it will count twice, so log it in one place.
 *  - Revenue      = income logged against the batch.
 *  - Profit       = revenue - costs. Per-animal figures divide by the number of
 *                   animals the batch started with (revenue per sold animal
 *                   divides by animals sold).
 */

/** Today's date (Y-m-d) in the company's own time zone. */
function hef_company_today_for(PDO $pdo, int $companyId): string
{
    $stmt = $pdo->prepare('SELECT timezone FROM companies WHERE id = ?');
    $stmt->execute([$companyId]);

    return hef_company_today($stmt->fetchColumn() ?: null);
}

/**
 * Performance rows keyed by batch id, newest batch first. Pass $batchId for a
 * single batch.
 */
function hef_batch_performance(PDO $pdo, int $companyId, string $today, ?int $batchId = null): array
{
    $sql = "SELECT b.id, b.batch_code, b.status, b.date_acquired, b.age_at_acquisition_days,
                   (b.initial_count_male + b.initial_count_female + b.initial_count_unknown) AS initial_total,
                   (b.current_count_male + b.current_count_female + b.current_count_unknown) AS alive,
                   s.name AS species_name, br.name AS breed_name, s.max_days_to_sell,
                   COALESCE((SELECT SUM(ml.`count`) FROM mortality_log ml WHERE ml.batch_id = b.id), 0) AS dead,
                   COALESCE((SELECT SUM(i.quantity_sold) FROM income i WHERE i.batch_id = b.id), 0) AS sold,
                   COALESCE((SELECT SUM(i.amount) FROM income i WHERE i.batch_id = b.id), 0) AS revenue,
                   COALESCE((SELECT SUM(e.amount) FROM expenses e WHERE e.batch_id = b.id), 0) AS other_costs,
                   COALESCE((SELECT SUM(fc.cost) FROM feed_consumption fc WHERE fc.batch_id = b.id), 0) AS feed_cost,
                   COALESCE((SELECT SUM(vr.cost) FROM vaccination_records vr WHERE vr.batch_id = b.id), 0)
                   + COALESCE((SELECT SUM(hr.medicine_cost) FROM health_records hr WHERE hr.batch_id = b.id), 0) AS health_cost
            FROM batches b
            JOIN species s ON s.id = b.species_id
            LEFT JOIN breeds br ON br.id = b.breed_id
            WHERE b.company_id = ?";
    $params = [$companyId];
    if ($batchId !== null) {
        $sql .= ' AND b.id = ?';
        $params[] = $batchId;
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY b.date_acquired DESC, b.id DESC');
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $initial = (int) $r['initial_total'];
        $dead = (int) $r['dead'];
        $sold = (int) $r['sold'];
        $ageAtAcq = (int) $r['age_at_acquisition_days'];

        $acquired = new DateTimeImmutable($r['date_acquired']);
        $todayDate = new DateTimeImmutable($today);
        $daysSince = (int) $acquired->diff($todayDate)->format('%r%a');

        $sellBy = null;
        $daysLeft = null;
        if ($r['max_days_to_sell'] !== null && (int) $r['max_days_to_sell'] > 0) {
            $offset = (int) $r['max_days_to_sell'] - $ageAtAcq;
            $sellByDate = $acquired->modify(sprintf('%+d days', $offset));
            $sellBy = $sellByDate->format('Y-m-d');
            $daysLeft = (int) $todayDate->diff($sellByDate)->format('%r%a');
        }

        $revenue = (float) $r['revenue'];
        $otherCosts = (float) $r['other_costs'];
        $feedCost = (float) $r['feed_cost'];
        $healthCost = (float) $r['health_cost'];
        $totalCost = $otherCosts + $feedCost + $healthCost;
        $profit = $revenue - $totalCost;

        $out[(int) $r['id']] = [
            'id' => (int) $r['id'],
            'batch_code' => $r['batch_code'],
            'status' => $r['status'],
            'species_name' => $r['species_name'],
            'breed_name' => $r['breed_name'],
            'date_acquired' => $r['date_acquired'],
            'initial' => $initial,
            'alive' => (int) $r['alive'],
            'dead' => $dead,
            'sold' => $sold,
            'mortality_pct' => $initial > 0 ? round($dead / $initial * 100, 1) : null,
            'age_days' => $ageAtAcq + max(0, $daysSince),
            'sell_by' => $sellBy,
            'days_left' => $daysLeft,
            'revenue' => $revenue,
            'other_costs' => $otherCosts,
            'feed_cost' => $feedCost,
            'health_cost' => $healthCost,
            'total_cost' => $totalCost,
            'profit' => $profit,
            'cost_per_animal' => $initial > 0 ? $totalCost / $initial : null,
            'feed_cost_per_animal' => $initial > 0 ? $feedCost / $initial : null,
            'profit_per_animal' => $initial > 0 ? $profit / $initial : null,
            'revenue_per_sold' => $sold > 0 ? $revenue / $sold : null,
        ];
    }

    return $out;
}

/** Bootstrap badge classes for a mortality percentage: 10%+ red, 5%+ amber, otherwise green. */
function hef_mortality_class(?float $pct): string
{
    if ($pct === null) {
        return 'bg-light text-dark border';
    }
    if ($pct >= 10) {
        return 'bg-danger';
    }
    if ($pct >= 5) {
        return 'bg-warning text-dark';
    }

    return 'bg-success-subtle text-success';
}

/**
 * [label, badge classes] for the sell-by date of an animal batch that is
 * still being raised, or null when there is nothing useful to say.
 */
function hef_sell_by_badge(array $p): ?array
{
    if ($p['status'] !== 'active' || $p['sell_by'] === null || $p['alive'] <= 0) {
        return null;
    }
    $left = $p['days_left'];
    $date = date('d M', strtotime($p['sell_by']));
    if ($left < 0) {
        return ['Past sell-by (' . $date . ')', 'bg-danger'];
    }
    if ($left <= 7) {
        return ['Sell by ' . $date . ' (' . $left . ' d)', 'bg-warning text-dark'];
    }

    return ['Sell by ' . $date . ' (' . $left . ' d)', 'bg-light text-dark border'];
}
