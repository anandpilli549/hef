<?php
/**
 * Encrypted backup and restore.
 *
 * Three kinds of backup (the "scope"):
 *   company       An Owner's backup of their own company's data. Login accounts,
 *                 subscription/billing details, provider credentials (payments,
 *                 SMS, WhatsApp) and logs are NOT included, and a restore never
 *                 touches them, so it can't lock anyone out or change billing.
 *   company_full  A Super Admin's backup of one whole company: everything above
 *                 as well, so the company can be rebuilt even after it was deleted.
 *   database      A Super Admin's backup of every table.
 *
 * Which tables belong to a company is found from the live database schema
 * (tables with a company_id column, plus tables that point at them by foreign
 * key), so new tables are covered automatically. Uploaded photos are files on
 * the server, not database rows, and are NOT inside a backup; a restore keeps
 * the links to them.
 *
 * The file is gzip-compressed JSON, encrypted with AES-256-GCM using a key
 * derived from the passphrase (PBKDF2-SHA256). The passphrase is never stored;
 * without it the file can't be opened. A restore runs in one transaction, so if
 * anything fails the database is left exactly as it was.
 *
 * File layout: "HEFBAK01" | iterations (4 bytes) | salt (16) | nonce (12) |
 * ciphertext | GCM tag (16). The header is authenticated too.
 */

const HEF_BACKUP_MAGIC = 'HEFBAK01';
const HEF_BACKUP_FORMAT = 'hefarm-backup';
const HEF_BACKUP_VERSION = 1;
const HEF_BACKUP_PBKDF2_ROUNDS = 200000;

/** A problem with this server's PHP that stops backups, or null when fine. */
function hef_backup_requirements(): ?string
{
    if (! extension_loaded('openssl') || ! in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
        return 'This server\'s PHP has no AES-256-GCM encryption (the OpenSSL extension), so backups are not available.';
    }
    if (! function_exists('gzencode') || ! function_exists('gzdecode')) {
        return 'This server\'s PHP has no compression support (zlib), so backups are not available.';
    }

    return null;
}

/** Encrypts $plain with a key made from $passphrase. */
function hef_backup_encrypt(string $plain, string $passphrase): string
{
    $salt = random_bytes(16);
    $nonce = random_bytes(12);
    $header = HEF_BACKUP_MAGIC . pack('N', HEF_BACKUP_PBKDF2_ROUNDS) . $salt . $nonce;
    $key = hash_pbkdf2('sha256', $passphrase, $salt, HEF_BACKUP_PBKDF2_ROUNDS, 32, true);

    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $header, 16);
    if ($cipher === false) {
        throw new RuntimeException('Encryption failed.');
    }

    return $header . $cipher . $tag;
}

/** The decrypted bytes, or null when the passphrase is wrong or the file is damaged / not a backup. */
function hef_backup_decrypt(string $blob, string $passphrase): ?string
{
    $headerLength = 8 + 4 + 16 + 12;
    if (strlen($blob) < $headerLength + 16 || substr($blob, 0, 8) !== HEF_BACKUP_MAGIC) {
        return null;
    }
    $rounds = unpack('N', substr($blob, 8, 4))[1];
    if ($rounds < 10000 || $rounds > 5000000) {
        return null;
    }
    $header = substr($blob, 0, $headerLength);
    $salt = substr($blob, 12, 16);
    $nonce = substr($blob, 28, 12);
    $tag = substr($blob, -16);
    $cipher = substr($blob, $headerLength, -16);

    $key = hash_pbkdf2('sha256', $passphrase, $salt, $rounds, 32, true);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $header);

    return $plain === false ? null : $plain;
}

/**
 * The live database layout: for each table its columns (in order), which are
 * nullable, and its foreign keys.
 *
 * @return array<string, array{columns: string[], nullable: array<string,bool>, fks: array<int, array{column: string, ref_table: string, ref_column: string}>}>
 */
function hef_backup_schema(PDO $pdo): array
{
    $schema = [];
    $stmt = $pdo->query("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $schema[$t] = ['columns' => [], 'nullable' => [], 'fks' => []];
    }

    $stmt = $pdo->query('SELECT TABLE_NAME AS t, COLUMN_NAME AS c, IS_NULLABLE AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION');
    foreach ($stmt->fetchAll() as $r) {
        if (isset($schema[$r['t']])) {
            $schema[$r['t']]['columns'][] = $r['c'];
            $schema[$r['t']]['nullable'][$r['c']] = $r['n'] === 'YES';
        }
    }

    $stmt = $pdo->query(
        'SELECT TABLE_NAME AS t, COLUMN_NAME AS c, REFERENCED_TABLE_NAME AS rt, REFERENCED_COLUMN_NAME AS rc
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL'
    );
    foreach ($stmt->fetchAll() as $r) {
        if (isset($schema[$r['t']])) {
            $schema[$r['t']]['fks'][] = ['column' => $r['c'], 'ref_table' => $r['rt'], 'ref_column' => $r['rc']];
        }
    }

    return $schema;
}

/**
 * Which tables a scope covers and how to pick that company's rows out of each.
 * Each entry has either 'where' (a SQL condition using {cid}) or 'via' (the
 * foreign key(s) linking the table to a parent already in the plan). If any of
 * those keys can never be empty, that one is used alone; otherwise a row
 * counts if ANY of them points into the company, so rows with an empty
 * (optional) key, such as a supplier line with no breed, are never missed.
 */
function hef_backup_plan(array $schema, string $scope): array
{
    $specs = [];

    if ($scope === 'database') {
        foreach ($schema as $t => $def) {
            if ($t !== 'backup_events') {
                $specs[$t] = ['where' => '1 = 1'];
            }
        }

        return $specs;
    }

    $exclude = ['audit_logs', 'notification_logs', 'otp_codes', 'user_devices', 'backup_events'];
    if ($scope === 'company') {
        $exclude = array_merge($exclude, ['users', 'companies', 'company_subscriptions', 'payment_settings', 'notification_settings', 'alerts_log']);
    }

    if ($scope === 'company_full' && isset($schema['companies'])) {
        $specs['companies'] = ['where' => 'id = {cid}'];
    }
    foreach ($schema as $t => $def) {
        if ($t !== 'companies' && ! in_array($t, $exclude, true) && in_array('company_id', $def['columns'], true)) {
            $specs[$t] = ['where' => 'company_id = {cid}'];
        }
    }

    // Tables with no company_id that hang off a table already included.
    do {
        $added = false;
        foreach ($schema as $t => $def) {
            if (isset($specs[$t]) || in_array($t, $exclude, true) || $t === 'companies') {
                continue;
            }
            $candidates = [];
            foreach ($def['fks'] as $fk) {
                if ($fk['ref_table'] !== 'companies' && isset($specs[$fk['ref_table']])) {
                    $candidates[] = $fk;
                }
            }
            if ($candidates) {
                $required = array_values(array_filter($candidates, function ($fk) use ($def) {
                    return empty($def['nullable'][$fk['column']]);
                }));
                $specs[$t] = ['via' => $required ? [$required[0]] : $candidates];
                $added = true;
            }
        }
    } while ($added);

    return $specs;
}

/** The SQL condition that picks one company's rows from $table. */
function hef_backup_where(array $specs, string $table, int $companyId): string
{
    $spec = $specs[$table];
    if (isset($spec['where'])) {
        return str_replace('{cid}', (string) $companyId, $spec['where']);
    }
    $parts = [];
    foreach ($spec['via'] as $fk) {
        $parts[] = '`' . $fk['column'] . '` IN (SELECT `' . $fk['ref_column'] . '` FROM `' . $fk['ref_table'] . '` WHERE '
            . hef_backup_where($specs, $fk['ref_table'], $companyId) . ')';
    }

    return count($parts) > 1 ? '(' . implode(' OR ', $parts) . ')' : $parts[0];
}

/**
 * Tables ordered so every table comes after the ones it points at.
 *
 * @return array{0: string[], 1: bool} the order, and whether foreign keys form a loop
 */
function hef_backup_order(array $schema, array $tables): array
{
    $deps = [];
    foreach ($tables as $t) {
        $deps[$t] = [];
        foreach ($schema[$t]['fks'] as $fk) {
            if ($fk['ref_table'] !== $t && in_array($fk['ref_table'], $tables, true)) {
                $deps[$t][$fk['ref_table']] = true;
            }
        }
    }

    $order = [];
    $remaining = $deps;
    while ($remaining) {
        $ready = [];
        foreach ($remaining as $t => $d) {
            if (! array_diff_key($d, array_flip($order))) {
                $ready[] = $t;
            }
        }
        if (! $ready) {
            return [array_merge($order, array_keys($remaining)), true]; // a loop: caller must relax FK checks
        }
        foreach ($ready as $t) {
            $order[] = $t;
            unset($remaining[$t]);
        }
    }

    return [$order, false];
}

/** Raises PHP's time and memory limits for a big export or restore, where the host allows it. */
function hef_backup_relax_limits(): void
{
    @set_time_limit(0);
    @ini_set('memory_limit', '768M');
}

/**
 * Reads the data for a scope into a payload array (not yet encrypted).
 *
 * @return array{payload: array, tables: int, records: int}
 */
function hef_backup_build(PDO $pdo, string $scope, ?int $companyId, array $meta = []): array
{
    hef_backup_relax_limits();
    $schema = hef_backup_schema($pdo);
    $specs = hef_backup_plan($schema, $scope);
    [$order] = hef_backup_order($schema, array_keys($specs));

    $tables = [];
    $records = 0;
    foreach ($order as $t) {
        $cols = $schema[$t]['columns'];
        $quoted = implode(', ', array_map(function ($c) { return '`' . $c . '`'; }, $cols));
        $stmt = $pdo->query('SELECT ' . $quoted . ' FROM `' . $t . '` WHERE ' . hef_backup_where($specs, $t, (int) $companyId));
        $rows = $stmt->fetchAll(PDO::FETCH_NUM);
        $tables[$t] = ['columns' => $cols, 'rows' => $rows];
        $records += count($rows);
    }

    $payload = [
        'format' => HEF_BACKUP_FORMAT,
        'version' => HEF_BACKUP_VERSION,
        'scope' => $scope,
        'created_at' => gmdate('c'),
        'meta' => $meta,
        'tables' => $tables,
    ];
    if ($scope !== 'database') {
        $stmt = $pdo->prepare('SELECT id, name, slug FROM companies WHERE id = ?');
        $stmt->execute([$companyId]);
        $payload['company'] = $stmt->fetch() ?: ['id' => $companyId];
    }

    return ['payload' => $payload, 'tables' => count($tables), 'records' => $records];
}

/** Builds, compresses and encrypts a backup, ready to send as a download. */
function hef_backup_create(PDO $pdo, string $scope, ?int $companyId, string $passphrase, array $meta = []): array
{
    $built = hef_backup_build($pdo, $scope, $companyId, $meta);
    $json = json_encode($built['payload'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Could not prepare the data for backup.');
    }

    return [
        'blob' => hef_backup_encrypt(gzencode($json, 6), $passphrase),
        'tables' => $built['tables'],
        'records' => $built['records'],
        'payload' => $built['payload'],
    ];
}

/**
 * Opens an uploaded backup: decrypts, decompresses and checks it is one of ours.
 *
 * @return array{payload?: array, error?: string}
 */
function hef_backup_open(string $blob, string $passphrase): array
{
    hef_backup_relax_limits();
    $plain = hef_backup_decrypt($blob, $passphrase);
    if ($plain === null) {
        return ['error' => 'The passphrase is wrong, or this isn\'t a valid HEFarm backup file (or it is damaged).'];
    }
    $json = @gzdecode($plain);
    $payload = $json === false ? null : json_decode($json, true, 512, JSON_BIGINT_AS_STRING);
    if (! is_array($payload) || ($payload['format'] ?? '') !== HEF_BACKUP_FORMAT || ! isset($payload['tables'], $payload['scope'])) {
        return ['error' => 'This file opened, but it isn\'t a HEFarm backup.'];
    }
    if ((int) ($payload['version'] ?? 0) > HEF_BACKUP_VERSION) {
        return ['error' => 'This backup was made by a newer version of HEFarm than this one.'];
    }

    return ['payload' => $payload];
}

/**
 * Replaces the data in a scope with the contents of a backup, all in one
 * transaction. $requireCompanyId (Owner restores) makes sure a backup can only
 * be restored into the company it came from.
 *
 * @return array{ok: bool, message: string, tables?: int, records?: int}
 */
function hef_backup_restore(PDO $pdo, array $payload, string $scope, ?int $requireCompanyId = null): array
{
    hef_backup_relax_limits();

    if (($payload['scope'] ?? '') !== $scope) {
        $names = ['company' => 'a company backup from Backup', 'company_full' => 'a single-company backup from Super Admin', 'database' => 'a whole-database backup'];

        return ['ok' => false, 'message' => 'This file is ' . ($names[$payload['scope'] ?? ''] ?? 'a different kind of backup') . ', not the kind this page restores.'];
    }

    $companyId = 0;
    if ($scope !== 'database') {
        $companyId = (int) ($payload['company']['id'] ?? 0);
        if ($companyId <= 0) {
            return ['ok' => false, 'message' => 'This backup does not say which company it belongs to.'];
        }
        if ($requireCompanyId !== null && $companyId !== $requireCompanyId) {
            return ['ok' => false, 'message' => 'This backup belongs to a different company, so it can\'t be restored here.'];
        }
    }

    $schema = hef_backup_schema($pdo);
    $specs = hef_backup_plan($schema, $scope);

    // Never trust names from the file: every table and column must exist here.
    $inBackup = [];
    foreach ($payload['tables'] as $t => $data) {
        if (! isset($specs[$t])) {
            return ['ok' => false, 'message' => 'The backup contains a table ("' . $t . '") that this backup type does not cover here, so nothing was changed.'];
        }
        foreach ($data['columns'] ?? [] as $c) {
            if (! in_array($c, $schema[$t]['columns'], true)) {
                return ['ok' => false, 'message' => 'The backup has a column ("' . $t . '.' . $c . '") this database doesn\'t have. It may be from a newer version, so nothing was changed.'];
            }
        }
        $inBackup[] = $t;
    }
    [$order, $cyclic] = hef_backup_order($schema, $inBackup);

    // Owner restores keep login accounts as they are, so references to people
    // must point at someone who still exists in this company.
    $userRefs = [];
    $validUsers = [];
    if ($scope === 'company' && $companyId > 0) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE company_id = ?');
        $stmt->execute([$companyId]);
        $validUsers = array_flip(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        foreach ($inBackup as $t) {
            foreach ($schema[$t]['fks'] as $fk) {
                if ($fk['ref_table'] === 'users') {
                    $userRefs[$t][$fk['column']] = $schema[$t]['nullable'][$fk['column']] ?? false;
                }
            }
        }
    }

    $records = 0;
    $skipped = 0;
    try {
        if ($cyclic) {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        }
        $pdo->beginTransaction();

        foreach (array_reverse($order) as $t) {
            $pdo->exec('DELETE FROM `' . $t . '` WHERE ' . hef_backup_where($specs, $t, $companyId));
        }

        foreach ($order as $t) {
            $data = $payload['tables'][$t];
            $cols = $data['columns'];
            $rows = $data['rows'] ?? [];
            if (! $cols || ! $rows) {
                continue;
            }

            // Which positions hold references to users that may need fixing up.
            $fixups = [];
            foreach ($userRefs[$t] ?? [] as $col => $nullable) {
                $pos = array_search($col, $cols, true);
                if ($pos !== false) {
                    $fixups[$pos] = $nullable;
                }
            }

            $quotedCols = implode(', ', array_map(function ($c) { return '`' . $c . '`'; }, $cols));
            $rowPlaceholder = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
            $chunkSize = max(1, min(200, (int) floor(60000 / count($cols))));

            $batch = [];
            $flush = function () use (&$batch, $pdo, $t, $quotedCols, $rowPlaceholder, &$records) {
                if (! $batch) {
                    return;
                }
                $sql = 'INSERT INTO `' . $t . '` (' . $quotedCols . ') VALUES ' . implode(', ', array_fill(0, count($batch), $rowPlaceholder));
                $params = [];
                foreach ($batch as $r) {
                    foreach ($r as $v) {
                        $params[] = $v;
                    }
                }
                $pdo->prepare($sql)->execute($params);
                $records += count($batch);
                $batch = [];
            };

            foreach ($rows as $row) {
                if (count($row) !== count($cols)) {
                    throw new RuntimeException('A row in "' . $t . '" is malformed.');
                }
                foreach ($fixups as $pos => $nullable) {
                    if ($row[$pos] !== null && ! isset($validUsers[(int) $row[$pos]])) {
                        if ($nullable) {
                            $row[$pos] = null;
                        } else {
                            $skipped++;
                            continue 2; // this row belongs to someone who no longer exists
                        }
                    }
                }
                $batch[] = $row;
                if (count($batch) >= $chunkSize) {
                    $flush();
                }
            }
            $flush();
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('backup restore failed: ' . $e->getMessage());

        return ['ok' => false, 'message' => 'The restore failed and nothing was changed. (' . substr($e->getMessage(), 0, 200) . ')'];
    } finally {
        if ($cyclic) {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    $message = 'Restored ' . number_format($records) . ' records across ' . count($order) . ' tables.';
    if ($skipped > 0) {
        $message .= ' ' . $skipped . ' record(s) belonging to people who no longer have an account here were left out.';
    }

    return ['ok' => true, 'message' => $message, 'tables' => count($order), 'records' => $records];
}

/** Notes a backup or restore in backup_events (silently does nothing if the table isn't there yet). */
function hef_backup_log(PDO $pdo, ?int $companyId, ?int $userId, string $action, string $scope, string $details): void
{
    try {
        $pdo->prepare(
            'INSERT INTO backup_events (company_id, user_id, action, scope, details, created_at) VALUES (?, ?, ?, ?, ?, NOW())'
        )->execute([$companyId, $userId, $action, $scope, substr($details, 0, 255)]);
    } catch (PDOException $e) {
        error_log('backup log skipped: ' . $e->getMessage());
    }
}

/** Recent backup / restore activity for the pages. $companyId null = everything. */
function hef_backup_recent(PDO $pdo, ?int $companyId, int $limit = 10): array
{
    try {
        $sql = 'SELECT e.*, u.name AS user_name, c.name AS company_name
                FROM backup_events e LEFT JOIN users u ON u.id = e.user_id LEFT JOIN companies c ON c.id = e.company_id';
        $params = [];
        if ($companyId !== null) {
            $sql .= ' WHERE e.company_id = ?';
            $params[] = $companyId;
        }
        $stmt = $pdo->prepare($sql . ' ORDER BY e.created_at DESC, e.id DESC LIMIT ' . (int) $limit);
        $stmt->execute($params);

        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/** A download file name such as hefarm-company-green-valley-2026-09-20-1430.hefbak */
function hef_backup_filename(string $label): string
{
    $label = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($label)), '-');

    return 'hefarm-' . ($label !== '' ? $label : 'backup') . '-' . date('Y-m-d-Hi') . '.hefbak';
}
