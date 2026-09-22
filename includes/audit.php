<?php
/**
 * Audit logging: captures old/new data snapshots on edits and deletes,
 * so a Super Admin can review what changed and restore a record if
 * something was wrong or removed by mistake.
 */

// Only these tables can be logged/restored — a safety whitelist since
// table names can't be parameterized in SQL.
const HEF_AUDITED_TABLES = [
    'species', 'rooms', 'batches', 'mortality_log', 'vaccination_records',
    'health_records', 'expenses', 'income', 'storefront_listings', 'users',
];

function hef_audit_log(
    PDO $pdo,
    ?int $companyId,
    ?int $userId,
    string $table,
    int $recordId,
    string $action,
    ?array $oldData,
    ?array $newData
): void {
    if (! in_array($table, HEF_AUDITED_TABLES, true)) {
        return;
    }
    $pdo->prepare(
        "INSERT INTO audit_logs (company_id, user_id, table_name, record_id, action, old_data, new_data, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
    )->execute([
        $companyId, $userId, $table, $recordId, $action,
        $oldData !== null ? json_encode($oldData) : null,
        $newData !== null ? json_encode($newData) : null,
    ]);
}

/**
 * Reverts a record to its old_data snapshot. For 'update' entries this
 * writes the old values back; for 'delete' entries this re-inserts the
 * row (with its original id). Returns ['success' => bool, 'message' => string].
 *
 * Known limitation: restoring a deleted parent row (e.g. a batch) does
 * NOT bring back child rows that cascaded away with it (its own
 * mortality/health/vaccination records) — those would need their own
 * audit entries restored too, if any exist.
 */
function hef_restore_audit_entry(PDO $pdo, int $auditId): array
{
    $stmt = $pdo->prepare('SELECT * FROM audit_logs WHERE id = ?');
    $stmt->execute([$auditId]);
    $entry = $stmt->fetch();

    if (! $entry) {
        return ['success' => false, 'message' => 'Audit entry not found.'];
    }
    if (! in_array($entry['table_name'], HEF_AUDITED_TABLES, true)) {
        return ['success' => false, 'message' => 'This table is not restorable.'];
    }
    if ($entry['restored_at']) {
        return ['success' => false, 'message' => 'This entry was already restored.'];
    }

    $oldData = $entry['old_data'] ? json_decode($entry['old_data'], true) : null;
    if (! $oldData) {
        return ['success' => false, 'message' => 'No old data to restore from.'];
    }

    $table = $entry['table_name'];

    try {
        if ($entry['action'] === 'update') {
            $setParts = [];
            $values = [];
            foreach ($oldData as $col => $val) {
                if ($col === 'id') {
                    continue;
                }
                $setParts[] = "`{$col}` = ?";
                $values[] = $val;
            }
            $values[] = $entry['record_id'];
            $sql = "UPDATE `{$table}` SET " . implode(', ', $setParts) . ' WHERE id = ?';
            $pdo->prepare($sql)->execute($values);
        } elseif ($entry['action'] === 'delete') {
            $cols = array_keys($oldData);
            $placeholders = implode(', ', array_fill(0, count($cols), '?'));
            $colList = implode(', ', array_map(fn ($c) => "`{$c}`", $cols));
            $sql = "INSERT INTO `{$table}` ({$colList}) VALUES ({$placeholders})";
            $pdo->prepare($sql)->execute(array_values($oldData));
        } else {
            return ['success' => false, 'message' => 'Unknown action type.'];
        }

        $pdo->prepare('UPDATE audit_logs SET restored_at = NOW() WHERE id = ?')->execute([$auditId]);
        return ['success' => true, 'message' => 'Restored successfully.'];
    } catch (PDOException $e) {
        error_log("hef_restore_audit_entry failed for audit #{$auditId}: " . $e->getMessage());
        return ['success' => false, 'message' => 'Restore failed — the record may already exist or conflict with current data.'];
    }
}
