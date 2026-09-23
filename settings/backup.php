<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/helpers.php';
require_login();
require_role_admin();

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function sql_quote_value($value): string {
    if ($value === null) return 'NULL';
    return $GLOBALS['pdo']->quote((string)$value);
}

function dump_selected_tables(PDO $pdo, string $dbName, array $tables, string $prefix, bool $withData = true): void {
    $filename = $prefix . '-' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $dbName) . '-' . date('Ymd-His') . '.sql';

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');

    echo "-- KAMALTUR POS backup\n";
    echo "-- Database: `{$dbName}`\n";
    echo "-- Generated at: " . date('Y-m-d H:i:s') . "\n\n";
    echo "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
    echo "START TRANSACTION;\n";
    echo "SET time_zone = \"+00:00\";\n";
    echo "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
        $createRow = $createStmt->fetch(PDO::FETCH_ASSOC);
        $createSql = (string)($createRow['Create Table'] ?? array_values($createRow)[1] ?? '');

        echo "\n-- --------------------------------------------------------\n";
        echo "-- Table structure for table `{$table}`\n\n";
        echo "DROP TABLE IF EXISTS `{$table}`;\n";
        echo $createSql . ";\n\n";

        if ($withData) {
            $rows = $pdo->query("SELECT * FROM `{$table}`", PDO::FETCH_ASSOC);
            $first = true;
            foreach ($rows as $row) {
                if ($first) {
                    echo "-- Dumping data for table `{$table}`\n\n";
                    $first = false;
                }
                $cols = array_map(fn($c) => '`' . str_replace('`', '``', (string)$c) . '`', array_keys($row));
                $vals = array_map('sql_quote_value', array_values($row));
                echo "INSERT INTO `{$table}` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ");\n";
            }
            if (!$first) echo "\n";
        } else {
            echo "-- Structure only for table `{$table}`\n\n";
        }
    }

    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    echo "COMMIT;\n";
    exit;
}

function list_base_tables(PDO $pdo): array {
    $tablesStmt = $pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"');
    $tables = [];
    foreach ($tablesStmt->fetchAll(PDO::FETCH_NUM) as $row) {
        $tables[] = (string)$row[0];
    }
    return $tables;
}

function split_sql_statements(string $sql): array {
    $statements = [];
    $buffer = '';
    $inSingle = false;
    $inDouble = false;
    $inLineComment = false;
    $inBlockComment = false;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($inLineComment) {
            if ($char === "\n") {
                $inLineComment = false;
                $buffer .= $char;
            }
            continue;
        }

        if ($inBlockComment) {
            if ($char === '*' && $next === '/') {
                $inBlockComment = false;
                $i++;
            }
            continue;
        }

        if (!$inSingle && !$inDouble) {
            if ($char === '-' && $next === '-') {
                $inLineComment = true;
                $i++;
                continue;
            }
            if ($char === '#') {
                $inLineComment = true;
                continue;
            }
            if ($char === '/' && $next === '*') {
                $inBlockComment = true;
                $i++;
                continue;
            }
        }

        if ($char === "'" && !$inDouble) {
            $escaped = $i > 0 && $sql[$i - 1] === '\\';
            if (!$escaped) $inSingle = !$inSingle;
        } elseif ($char === '"' && !$inSingle) {
            $escaped = $i > 0 && $sql[$i - 1] === '\\';
            if (!$escaped) $inDouble = !$inDouble;
        }

        if ($char === ';' && !$inSingle && !$inDouble) {
            $statement = trim($buffer);
            if ($statement !== '') $statements[] = $statement;
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    $tail = trim($buffer);
    if ($tail !== '') $statements[] = $tail;
    return $statements;
}

function extract_create_table_block(string $sql, string $table): ?string {
    $pattern = '/CREATE\s+TABLE\s+`' . preg_quote($table, '/') . '`\s*\((?:[^()]+|\((?:[^()]+|\([^()]*\))*\))*\)\s*ENGINE=.*?;/is';
    if (!preg_match($pattern, $sql, $matches)) {
        return null;
    }
    return $matches[0] ?? null;
}

function extract_column_type_from_create(string $sql, string $table, string $column): ?string {
    $block = extract_create_table_block($sql, $table);
    if ($block === null) {
        return null;
    }

    $pattern = '/^\s*`' . preg_quote($column, '/') . '`\s+([a-z0-9(),]+(?:\s+unsigned)?(?:\s+zerofill)?)/im';
    if (!preg_match($pattern, $block, $matches)) {
        return null;
    }

    return trim((string)($matches[1] ?? '')) ?: null;
}

function normalize_fk_column_type(string $sql, string $table, string $column, string $targetType): string {
    $block = extract_create_table_block($sql, $table);
    if ($block === null) {
        return $sql;
    }

    $columnPattern = '/(^\s*`' . preg_quote($column, '/') . '`\s+)([a-z0-9(),]+(?:\s+unsigned)?(?:\s+zerofill)?)(\b.*$)/im';
    $updatedBlock = preg_replace($columnPattern, '$1' . $targetType . '$3', $block, 1);

    if (!is_string($updatedBlock) || $updatedBlock === $block) {
        return $sql;
    }

    return str_replace($block, $updatedBlock, $sql);
}

function collect_fk_id_references(string $sql): array {
    preg_match_all(
        '/CREATE\s+TABLE\s+`([^`]+)`\s*\((.*?)\)\s*ENGINE=.*?;/is',
        $sql,
        $tableMatches,
        PREG_SET_ORDER
    );

    $references = [];
    foreach ($tableMatches as $tableMatch) {
        $table = (string)$tableMatch[1];
        $body = (string)$tableMatch[2];
        preg_match_all(
            '/FOREIGN\s+KEY\s*\(`([^`]+)`\)\s+REFERENCES\s+`([^`]+)`\s*\(`([^`]+)`\)/i',
            $body,
            $fkMatches,
            PREG_SET_ORDER
        );
        foreach ($fkMatches as $fk) {
            $references[] = [
                'table' => $table,
                'column' => (string)$fk[1],
                'ref_table' => (string)$fk[2],
                'ref_column' => (string)$fk[3],
            ];
        }
    }

    return $references;
}

function strip_foreign_keys_from_create_blocks(string $sql): string {
    return preg_replace_callback(
        '/CREATE\s+TABLE\s+`([^`]+)`\s*\((.*?)\)\s*ENGINE=.*?;/is',
        static function (array $matches): string {
            $full = (string)$matches[0];
            $body = (string)$matches[2];
            $lines = preg_split("/\r\n|\n|\r/", $body) ?: [];
            $filtered = [];

            foreach ($lines as $line) {
                if (stripos($line, 'FOREIGN KEY') !== false) {
                    continue;
                }
                $filtered[] = $line;
            }

            for ($i = count($filtered) - 1; $i >= 0; $i--) {
                if (trim($filtered[$i]) === '') {
                    continue;
                }
                $filtered[$i] = rtrim(rtrim($filtered[$i]), ',');
                break;
            }

            $rebuilt = implode("\n", $filtered);
            return str_replace($body, $rebuilt, $full);
        },
        $sql
    ) ?? $sql;
}

function strip_alter_table_foreign_keys(string $sql): string {
    return preg_replace(
        '/^\s*ALTER\s+TABLE\b.*?\bFOREIGN\s+KEY\b.*?;\s*$/ims',
        '',
        $sql
    ) ?? $sql;
}

function normalize_import_sql_schema(string $sql): string {
    foreach (collect_fk_id_references($sql) as $reference) {
        $targetType = extract_column_type_from_create($sql, $reference['ref_table'], $reference['ref_column']);
        if ($targetType === null) {
            continue;
        }
        $sql = normalize_fk_column_type($sql, $reference['table'], $reference['column'], $targetType);
    }

    $sql = strip_foreign_keys_from_create_blocks($sql);
    return strip_alter_table_foreign_keys($sql);
}

function extract_sql_enum_values(string $sql, string $table, string $column): array {
    $block = extract_create_table_block($sql, $table);
    if ($block === null) {
        return [];
    }
    $pattern = '/^\s*`' . preg_quote($column, '/') . '`\s+enum\((.*?)\)/im';
    if (!preg_match($pattern, $block, $matches)) {
        return [];
    }

    preg_match_all("/'([^']*)'/", (string)$matches[1], $valueMatches);
    return array_values(array_filter(array_map('trim', $valueMatches[1] ?? []), static fn($v) => $v !== ''));
}

function has_column_in_sql(string $sql, string $table, string $column): bool {
    $block = extract_create_table_block($sql, $table);
    if ($block === null) {
        return false;
    }

    return (bool)preg_match('/^\s*`' . preg_quote($column, '/') . '`\s+/im', $block);
}

function collect_insert_counts(string $sql): array {
    $counts = [];
    foreach (split_sql_statements($sql) as $statement) {
        if (!preg_match('/^INSERT\s+INTO\s+`?([a-z0-9_]+)`?\s*\((.*?)\)\s*VALUES\s*(.+)$/is', trim($statement), $matches)) {
            continue;
        }
        $table = strtolower((string)$matches[1]);
        $counts[$table] = ($counts[$table] ?? 0) + count(split_insert_value_groups((string)$matches[3]));
    }
    ksort($counts);
    return $counts;
}

function collect_sql_agency_ids(string $sql): array {
    $agencyIds = [];
    foreach (split_sql_statements($sql) as $statement) {
        if (!preg_match('/^INSERT\s+INTO\s+`?([a-z0-9_]+)`?\s*\((.*?)\)\s*VALUES\s*(.+)$/is', trim($statement), $matches)) {
            continue;
        }
        $columns = array_map(
            static fn(string $column): string => trim($column, " \t\n\r\0\x0B`"),
            split_sql_csv((string)$matches[2])
        );
        $agencyIndex = array_search('agency_id', $columns, true);
        if ($agencyIndex === false) {
            continue;
        }
        foreach (split_insert_value_groups((string)$matches[3]) as $group) {
            $tokens = split_sql_csv($group);
            if (!isset($tokens[$agencyIndex])) {
                continue;
            }
            $value = sql_token_to_php($tokens[$agencyIndex]);
            if ($value === null || $value === '') {
                continue;
            }
            $agencyIds[(int)$value] = true;
        }
    }

    $ids = array_keys($agencyIds);
    sort($ids);
    return $ids;
}

function preview_sql_backup(string $sql): array {
    $tables = collect_import_table_names($sql);
    sort($tables);

    $insertCounts = collect_insert_counts($sql);
    $agencyIds = collect_sql_agency_ids($sql);
    $roleValues = extract_sql_enum_values($sql, 'users', 'role');
    $hasAgencyPasswordHash = has_column_in_sql($sql, 'agencies', 'password_hash');
    $foreignKeyCount = preg_match_all('/\bFOREIGN\s+KEY\b/i', $sql, $matches);

    $warnings = [];
    if ($hasAgencyPasswordHash) {
        $warnings[] = 'Tabela agencies ainda contém password_hash.';
    }
    if ($roleValues !== [] && !in_array('superadmin', $roleValues, true)) {
        $warnings[] = 'Enum users.role não contém superadmin.';
    }
    if ($foreignKeyCount > 0) {
        $warnings[] = 'O arquivo contém constraints FOREIGN KEY que podem conflitar com a base atual.';
    }

    return [
        'tables' => $tables,
        'table_count' => count($tables),
        'insert_counts' => $insertCounts,
        'agency_ids' => $agencyIds,
        'role_values' => $roleValues,
        'has_agencies_password_hash' => $hasAgencyPasswordHash,
        'foreign_key_count' => (int)$foreignKeyCount,
        'warnings' => $warnings,
    ];
}

function collect_import_table_names(string $sql): array {
    preg_match_all('/(?:CREATE|DROP)\s+TABLE(?:\s+IF\s+EXISTS)?\s+`?([a-z0-9_]+)`?/i', $sql, $matches);
    $tables = [];
    foreach (($matches[1] ?? []) as $name) {
        $table = strtolower(trim((string)$name));
        if ($table !== '') {
            $tables[$table] = true;
        }
    }
    return array_keys($tables);
}

function list_accessible_agencies(PDO $pdo): array {
    $labelParts = [];
    if (has_column($pdo, 'agencies', 'fantasy_name')) {
        $labelParts[] = "NULLIF(fantasy_name,'')";
    }
    if (has_column($pdo, 'agencies', 'name')) {
        $labelParts[] = "NULLIF(name,'')";
    }
    if (has_column($pdo, 'agencies', 'legal_name')) {
        $labelParts[] = "NULLIF(legal_name,'')";
    }
    $labelExpr = $labelParts
        ? 'COALESCE(' . implode(', ', $labelParts) . ", CONCAT('Empresa #', id))"
        : "CONCAT('Empresa #', id)";

    if (is_superadmin()) {
        $st = $pdo->query("SELECT id, {$labelExpr} AS label FROM agencies ORDER BY id");
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $st = $pdo->prepare("SELECT id, {$labelExpr} AS label FROM agencies WHERE id=? LIMIT 1");
    $st->execute([agency_id()]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function split_insert_value_groups(string $sql): array {
    $groups = [];
    $buffer = '';
    $depth = 0;
    $inSingle = false;
    $inDouble = false;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $prev = $i > 0 ? $sql[$i - 1] : '';

        if ($char === "'" && !$inDouble && $prev !== '\\') {
            $inSingle = !$inSingle;
        } elseif ($char === '"' && !$inSingle && $prev !== '\\') {
            $inDouble = !$inDouble;
        }

        if (!$inSingle && !$inDouble) {
            if ($char === '(') {
                if ($depth === 0) {
                    $buffer = '';
                } else {
                    $buffer .= $char;
                }
                $depth++;
                continue;
            }

            if ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    $groups[] = $buffer;
                    $buffer = '';
                    continue;
                }
            }
        }

        if ($depth > 0) {
            $buffer .= $char;
        }
    }

    return $groups;
}

function split_sql_csv(string $sql): array {
    $parts = [];
    $buffer = '';
    $inSingle = false;
    $inDouble = false;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $prev = $i > 0 ? $sql[$i - 1] : '';

        if ($char === "'" && !$inDouble && $prev !== '\\') {
            $inSingle = !$inSingle;
        } elseif ($char === '"' && !$inSingle && $prev !== '\\') {
            $inDouble = !$inDouble;
        }

        if ($char === ',' && !$inSingle && !$inDouble) {
            $parts[] = trim($buffer);
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    if (trim($buffer) !== '') {
        $parts[] = trim($buffer);
    }

    return $parts;
}

function sql_token_to_php(string $token) {
    $token = trim($token);
    $upper = strtoupper($token);
    if ($upper === 'NULL') return null;
    if ($token === '') return '';

    if ($token[0] === "'" && substr($token, -1) === "'") {
        $value = substr($token, 1, -1);
        $value = str_replace(["\\\\", "\\'", "\\n", "\\r", "\\0"], ["\\", "'", "\n", "\r", "\0"], $value);
        $value = str_replace("''", "'", $value);
        return $value;
    }

    if (is_numeric($token)) {
        return str_contains($token, '.') ? (float)$token : (int)$token;
    }

    return $token;
}

function collect_agency_import_rows(string $sql, array $allowedTables): array {
    $rows = array_fill_keys($allowedTables, []);
    $statements = split_sql_statements(normalize_import_sql_schema($sql));

    foreach ($statements as $statement) {
        if (!preg_match('/^INSERT\s+INTO\s+`?([a-z0-9_]+)`?\s*\((.*?)\)\s*VALUES\s*(.+)$/is', trim($statement), $matches)) {
            continue;
        }

        $table = strtolower((string)$matches[1]);
        if (!in_array($table, $allowedTables, true)) {
            continue;
        }

        $columns = array_map(
            static fn(string $column): string => trim($column, " \t\n\r\0\x0B`"),
            split_sql_csv((string)$matches[2])
        );

        foreach (split_insert_value_groups((string)$matches[3]) as $group) {
            $tokens = split_sql_csv($group);
            if (count($tokens) !== count($columns)) {
                throw new RuntimeException("Linha inválida no import da tabela {$table}.");
            }

            $row = [];
            foreach ($columns as $index => $column) {
                $row[$column] = sql_token_to_php($tokens[$index]);
            }
            $rows[$table][] = $row;
        }
    }

    return $rows;
}

function import_row_with_mapping(PDO $pdo, string $table, array $row, array $config, array &$maps, array &$deferred, int $targetAgencyId): void {
    $data = $row;
    $oldId = isset($data['id']) ? (int)$data['id'] : null;
    unset($data['id']);

    // In agency-scoped imports, the destination company owns the records.
    // Ignore any agency_id coming from the SQL file and force the selected one.
    if (array_key_exists('agency_id', $data)) {
        $data['agency_id'] = $targetAgencyId;
    }

    if ($table === 'clients' && array_key_exists('gender', $data)) {
        $gender = strtoupper(trim((string)$data['gender']));
        $data['gender'] = match ($gender) {
            'M' => 'male',
            'F' => 'female',
            'O' => 'other',
            '' => null,
            default => $data['gender'],
        };
    }

    foreach (($config['refs'] ?? []) as $column => $rule) {
        if (array_key_exists('const', $rule)) {
            $data[$column] = $rule['const'];
            continue;
        }

        $oldValue = $row[$column] ?? null;
        if ($oldValue === null || $oldValue === '') {
            $data[$column] = null;
            continue;
        }

        $mapped = $maps[$rule['map']][(int)$oldValue] ?? null;
        if ($mapped === null) {
            if (!empty($rule['defer'])) {
                $data[$column] = null;
            } elseif (!empty($rule['nullable'])) {
                $data[$column] = null;
            } else {
                throw new RuntimeException("Não foi possível mapear {$table}.{$column}={$oldValue}.");
            }
        } else {
            $data[$column] = $mapped;
        }
    }

    $columns = array_keys($data);
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $sql = "INSERT INTO `{$table}` (`" . implode('`,`', $columns) . "`) VALUES ({$placeholders})";
    $st = $pdo->prepare($sql);
    $st->execute(array_values($data));

    $newId = (int)$pdo->lastInsertId();
    if ($oldId !== null) {
        $maps[$table][$oldId] = $newId;
    }

    foreach (($config['refs'] ?? []) as $column => $rule) {
        if (empty($rule['defer'])) continue;
        $oldValue = $row[$column] ?? null;
        if ($oldValue === null || $oldValue === '') continue;
        if (isset($maps[$rule['map']][(int)$oldValue])) {
            $pdo->prepare("UPDATE `{$table}` SET `{$column}`=? WHERE id=?")->execute([$maps[$rule['map']][(int)$oldValue], $newId]);
        } else {
            $deferred[] = [
                'table' => $table,
                'id' => $newId,
                'column' => $column,
                'map' => $rule['map'],
                'old' => (int)$oldValue,
            ];
        }
    }
}

function import_sql_into_agency(PDO $pdo, string $sql, int $targetAgencyId): array {
    $config = [
        'clients' => ['refs' => ['created_by' => ['const' => user_id(), 'nullable' => true], 'employer_id' => ['map' => 'clients', 'nullable' => true, 'defer' => true]]],
        'suppliers' => ['refs' => ['created_by' => ['const' => user_id(), 'nullable' => true]]],
        'invoices' => ['refs' => ['client_id' => ['map' => 'clients'], 'supplier_id' => ['map' => 'suppliers', 'nullable' => true], 'created_by' => ['const' => user_id(), 'nullable' => true]]],
        'invoice_trips' => ['refs' => ['invoice_id' => ['map' => 'invoices'], 'supplier_id' => ['map' => 'suppliers', 'nullable' => true]]],
        'passengers' => ['refs' => ['invoice_id' => ['map' => 'invoices']]],
        'segments' => ['refs' => ['invoice_id' => ['map' => 'invoices'], 'trip_id' => ['map' => 'invoice_trips', 'nullable' => true]]],
        'aux_services' => ['refs' => ['invoice_id' => ['map' => 'invoices'], 'supplier_id' => ['map' => 'suppliers', 'nullable' => true]]],
        'refunds' => ['refs' => ['invoice_id' => ['map' => 'invoices', 'nullable' => true], 'client_id' => ['map' => 'clients'], 'passenger_id' => ['map' => 'passengers', 'nullable' => true], 'supplier_id' => ['map' => 'suppliers', 'nullable' => true]]],
        'service_sales' => ['refs' => ['client_id' => ['map' => 'clients'], 'supplier_id' => ['map' => 'suppliers', 'nullable' => true], 'created_by' => ['const' => user_id(), 'nullable' => true]]],
        'service_details' => ['refs' => ['service_id' => ['map' => 'service_sales']]],
        'service_hotels' => ['refs' => ['service_id' => ['map' => 'service_sales']]],
        'service_hotel_rooms' => ['refs' => ['service_id' => ['map' => 'service_sales']]],
        'service_guests' => ['refs' => ['service_id' => ['map' => 'service_sales']]],
        'service_cars' => ['refs' => ['service_id' => ['map' => 'service_sales']]],
        'service_insurance' => ['refs' => ['service_id' => ['map' => 'service_sales']]],
    ];

    $order = [
        'clients',
        'suppliers',
        'invoices',
        'invoice_trips',
        'passengers',
        'segments',
        'aux_services',
        'refunds',
        'service_sales',
        'service_details',
        'service_hotels',
        'service_hotel_rooms',
        'service_guests',
        'service_cars',
        'service_insurance',
    ];

    $rowsByTable = collect_agency_import_rows($sql, array_keys($config));
    $maps = [];
    $deferred = [];
    $summary = [];

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $pdo->beginTransaction();
    try {
        foreach ($order as $table) {
            $summary[$table] = 0;
            foreach ($rowsByTable[$table] ?? [] as $row) {
                import_row_with_mapping($pdo, $table, $row, $config[$table], $maps, $deferred, $targetAgencyId);
                $summary[$table]++;
            }
        }

        foreach ($deferred as $item) {
            $mapped = $maps[$item['map']][$item['old']] ?? null;
            if ($mapped === null) {
                throw new RuntimeException("Não foi possível finalizar o vínculo {$item['table']}.{$item['column']}.");
            }
            $pdo->prepare("UPDATE `{$item['table']}` SET `{$item['column']}`=? WHERE id=?")->execute([$mapped, $item['id']]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    return $summary;
}

function restore_sql_backup(PDO $pdo, string $sql): int {
    $sql = normalize_import_sql_schema($sql);
    $statements = split_sql_statements($sql);
    $executed = 0;
    $tables = collect_import_table_names($sql);
    $createStatements = [];
    $otherStatements = [];

    foreach ($statements as $statement) {
        $normalized = strtoupper(ltrim($statement));
        if (
            $normalized === ''
            || str_starts_with($normalized, 'START TRANSACTION')
            || str_starts_with($normalized, 'COMMIT')
            || str_starts_with($normalized, 'DROP TABLE')
            || (str_starts_with($normalized, 'ALTER TABLE') && str_contains($normalized, 'FOREIGN KEY'))
        ) {
            continue;
        }

        if (preg_match('/^CREATE\s+TABLE\b/i', $statement)) {
            $createStatements[] = $statement;
        } else {
            $otherStatements[] = $statement;
        }
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        foreach ($tables as $table) {
            $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        }

        $pending = $createStatements;
        while ($pending !== []) {
            $remaining = [];
            $progress = false;

            foreach ($pending as $statement) {
                try {
                    $pdo->exec($statement);
                    $executed++;
                    $progress = true;
                } catch (Throwable $e) {
                    $remaining[] = $statement;
                }
            }

            if (!$progress) {
                $pdo->exec($pending[0]);
            }

            $pending = $remaining;
        }

        foreach ($otherStatements as $statement) {
            $pdo->exec($statement);
            $executed++;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    return $executed;
}

function purge_section_map(): array {
    return [
        'operational' => [
            'label' => 'Operacional',
            'tables' => [
                'audit_logs',
                'refund_logs',
                'refunds',
                'segments',
                'passengers',
                'invoice_trips',
                'aux_services',
                'service_hotel_rooms',
                'service_hotels',
                'service_guests',
                'service_cars',
                'service_insurance',
                'service_details',
                'service_sales',
                'invoices',
                'invoice_counters',
            ],
        ],
        'clients' => [
            'label' => 'Clientes',
            'tables' => [
                'client_attachments',
                'client_passenger_profiles',
                'clients',
            ],
        ],
        'suppliers' => [
            'label' => 'Fornecedores',
            'tables' => [
                'suppliers',
            ],
        ],
        'users' => [
            'label' => 'Usuários',
            'tables' => [
                'users',
            ],
        ],
        'agencies' => [
            'label' => 'Empresas',
            'tables' => [
                'agencies',
            ],
        ],
    ];
}

function purge_selected_sections(PDO $pdo, array $sections): array {
    $map = purge_section_map();
    $selected = array_values(array_intersect(array_keys($map), $sections));
    if ($selected === []) {
        throw new RuntimeException('Selecione ao menos uma seção para apagar.');
    }

    $tables = [];
    foreach ($selected as $section) {
        foreach ($map[$section]['tables'] as $table) {
            if (has_table($pdo, $table)) {
                $tables[$table] = true;
            }
        }
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }
        foreach (array_keys($tables) as $table) {
            $pdo->exec("DELETE FROM `{$table}`");
        }
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    return $selected;
}

function purge_operational_data(PDO $pdo): void {
    purge_selected_sections($pdo, ['operational']);
}

$err = '';
$msg = '';
$token = csrf_token();
$agencies = list_accessible_agencies($pdo);
$preview = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $err = 'Sessão expirada. Recarregue a página e tente novamente.';
    } else {
        try {
            $action = (string)($_POST['backup_action'] ?? 'full_export');
            $dbName = $GLOBALS['DB_NAME'] ?? 'database';

            if ($action === 'full_export') {
                dump_selected_tables($pdo, $dbName, list_base_tables($pdo), 'backup');
            }

            if ($action === 'structure_export') {
                dump_selected_tables($pdo, $dbName, list_base_tables($pdo), 'estrutura', false);
            }

            if ($action === 'contacts_export') {
                dump_selected_tables($pdo, $dbName, ['clients', 'suppliers'], 'clientes-fornecedores');
            }

            if ($action === 'import_backup') {
                if (empty($_FILES['backup_file']) || !is_array($_FILES['backup_file'])) {
                    throw new RuntimeException('Selecione um arquivo SQL para importar.');
                }
                if (($_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Falha ao enviar o arquivo de backup.');
                }
                $tmp = (string)($_FILES['backup_file']['tmp_name'] ?? '');
                if ($tmp === '' || !is_uploaded_file($tmp)) {
                    throw new RuntimeException('Arquivo inválido para importação.');
                }
                $name = (string)($_FILES['backup_file']['name'] ?? '');
                if (!preg_match('/\.sql$/i', $name)) {
                    throw new RuntimeException('Envie um arquivo .sql');
                }
                $sql = (string)file_get_contents($tmp);
                if (trim($sql) === '') {
                    throw new RuntimeException('O arquivo SQL está vazio.');
                }
                $executed = restore_sql_backup($pdo, $sql);
                $msg = 'Backup importado com sucesso. Comandos executados: ' . $executed . '.';
            }

            if ($action === 'preview_backup') {
                if (empty($_FILES['backup_file']) || !is_array($_FILES['backup_file'])) {
                    throw new RuntimeException('Selecione um arquivo SQL para analisar.');
                }
                if (($_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Falha ao enviar o arquivo SQL.');
                }
                $tmp = (string)($_FILES['backup_file']['tmp_name'] ?? '');
                if ($tmp === '' || !is_uploaded_file($tmp)) {
                    throw new RuntimeException('Arquivo inválido para análise.');
                }
                $name = (string)($_FILES['backup_file']['name'] ?? '');
                if (!preg_match('/\.sql$/i', $name)) {
                    throw new RuntimeException('Envie um arquivo .sql');
                }
                $sql = (string)file_get_contents($tmp);
                if (trim($sql) === '') {
                    throw new RuntimeException('O arquivo SQL está vazio.');
                }
                $preview = preview_sql_backup($sql);
                $msg = 'Arquivo analisado. Revise os pontos abaixo antes de importar.';
            }

            if ($action === 'agency_import') {
                if (empty($_FILES['backup_file']) || !is_array($_FILES['backup_file'])) {
                    throw new RuntimeException('Selecione um arquivo SQL para importar.');
                }
                if (($_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Falha ao enviar o arquivo de backup.');
                }
                $tmp = (string)($_FILES['backup_file']['tmp_name'] ?? '');
                if ($tmp === '' || !is_uploaded_file($tmp)) {
                    throw new RuntimeException('Arquivo inválido para importação.');
                }
                $targetAgencyId = (int)($_POST['target_agency_id'] ?? 0);
                $allowedAgencyIds = array_map(static fn(array $agency): int => (int)$agency['id'], $agencies);
                if (!in_array($targetAgencyId, $allowedAgencyIds, true)) {
                    throw new RuntimeException('Selecione uma empresa válida para o import.');
                }
                $name = (string)($_FILES['backup_file']['name'] ?? '');
                if (!preg_match('/\.sql$/i', $name)) {
                    throw new RuntimeException('Envie um arquivo .sql');
                }
                $sql = (string)file_get_contents($tmp);
                if (trim($sql) === '') {
                    throw new RuntimeException('O arquivo SQL está vazio.');
                }
                $summary = import_sql_into_agency($pdo, $sql, $targetAgencyId);
                $parts = [];
                foreach ($summary as $table => $count) {
                    if ($count > 0) $parts[] = $table . ': ' . $count;
                }
                $msg = 'Dados importados para a empresa selecionada com remapeamento de IDs.' . ($parts ? ' ' . implode(' | ', $parts) : '');
            }

            if ($action === 'purge_operational_data') {
                $warn1 = !empty($_POST['warn_backup']);
                if (!$warn1) {
                    throw new RuntimeException('Confirme que gerou backup antes de apagar os dados.');
                }
                $mode = (string)($_POST['purge_mode'] ?? 'operational');
                if ($mode === 'all') {
                    $sections = array_keys(purge_section_map());
                } elseif ($mode === 'custom') {
                    $sections = array_values(array_filter(array_map('strval', (array)($_POST['purge_sections'] ?? []))));
                } else {
                    $sections = ['operational'];
                }

                $deleted = purge_selected_sections($pdo, $sections);
                $labels = array_map(static fn(string $key): string => purge_section_map()[$key]['label'] ?? $key, $deleted);
                $msg = 'Seções apagadas com sucesso: ' . implode(', ', $labels) . '.';
            }
        } catch (Throwable $e) {
            error_log('[SETTINGS_BACKUP] ' . $e->getMessage());
            $err = $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível concluir a operação de backup.';
        }
    }
}

$counts = [];
$countTables = ['agencies', 'users', 'clients', 'suppliers', 'invoices', 'aux_services', 'refunds'];
foreach ($countTables as $table) {
    try {
        if (has_column($pdo, $table, 'agency_id')) {
            [$scopeSql, $scopeParams] = agency_scope_sql('agency_id');
            $st = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE {$scopeSql}");
            $st->execute($scopeParams);
            $counts[$table] = (int)$st->fetchColumn();
        } else {
            $st = $pdo->query("SELECT COUNT(*) FROM `{$table}`");
            $counts[$table] = (int)$st->fetchColumn();
        }
    } catch (Throwable $e) {
        $counts[$table] = null;
    }
}

$summaryItems = [
    ['label' => 'Clientes', 'value' => $counts['clients'] ?? null, 'icon' => 'ti ti-users'],
    ['label' => 'Fornecedores', 'value' => $counts['suppliers'] ?? null, 'icon' => 'ti ti-building-store'],
    ['label' => 'Vendas', 'value' => $counts['invoices'] ?? null, 'icon' => 'ti ti-receipt-2'],
    ['label' => 'Serviços', 'value' => $counts['aux_services'] ?? null, 'icon' => 'ti ti-luggage'],
    ['label' => 'Reembolsos', 'value' => $counts['refunds'] ?? null, 'icon' => 'ti ti-rotate-2'],
    ['label' => 'Usuários', 'value' => $counts['users'] ?? null, 'icon' => 'ti ti-user-cog'],
];

$pageTitle = 'Backup';
ob_start();
?>
<div class="mx-auto max-w-6xl">

  <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
      <p class="text-xs font-bold uppercase tracking-wider text-brand-600">Configurações</p>
      <h2 class="text-xl font-bold text-ink-950">Backup</h2>
      <p class="mt-1 text-sm text-ink-500">Exportar backup completo, importar arquivo SQL ou copiar apenas clientes e fornecedores.</p>
    </div>
    <a href="/settings/index.php" class="btn-ghost">
      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"></path><path d="M12 19l-7-7 7-7"></path></svg>
      Voltar
    </a>
  </div>

  <?php if ($msg !== ''): ?>
    <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800" role="alert">
      <?= h($msg) ?>
    </div>
  <?php endif; ?>
  <?php if ($err !== ''): ?>
    <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800" role="alert">
      <?= h($err) ?>
    </div>
  <?php endif; ?>

  <div class="grid gap-5 lg:grid-cols-12">

    <div class="card p-5 lg:col-span-8">
      <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
          <p class="text-[11px] font-bold uppercase tracking-wider text-ink-500">Segurança da base</p>
          <h3 class="mt-1 text-base font-bold text-ink-950">Exportar, restaurar e limpar dados</h3>
          <p class="mt-1.5 max-w-2xl text-sm leading-relaxed text-ink-500">
            Use backup completo para recuperação total, estrutura sem dados para ambiente novo, e backup de contatos quando quiser migrar apenas clientes e fornecedores.
          </p>
        </div>
        <span class="rounded-full bg-ink-100 px-2.5 py-1 text-[11px] font-semibold text-ink-600">Formato padrão: <code>.sql</code></span>
      </div>

      <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-3">
        <?php foreach ($summaryItems as $item): ?>
          <div class="rounded-xl border border-ink-100 bg-white px-3 py-2.5">
            <p class="text-[11px] font-medium text-ink-500"><?= h($item['label']) ?></p>
            <p class="text-base font-bold text-ink-950"><?= $item['value'] === null ? '—' : (int)$item['value'] ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="space-y-4 lg:col-span-4">
      <div class="card overflow-hidden">
        <div class="border-b border-ink-100 px-5 py-4">
          <h3 class="text-sm font-bold text-ink-950">Resumo da base</h3>
        </div>
        <div class="p-5">
          <p class="mb-3 text-xs text-ink-500">Contagem rápida das tabelas principais para validar o estado atual antes de exportar ou importar.</p>
          <table class="table-modern">
            <tbody>
              <?php foreach ($counts as $table => $count): ?>
                <tr>
                  <td><code class="rounded-md bg-ink-100 px-1.5 py-0.5 text-[11px] font-semibold text-ink-700"><?= h($table) ?></code></td>
                  <td class="text-right font-semibold text-ink-900"><?= $count === null ? '—' : (int)$count ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card overflow-hidden">
        <div class="border-b border-ink-100 px-5 py-4">
          <h3 class="text-sm font-bold text-ink-950">Boas práticas</h3>
        </div>
        <div class="p-5">
          <p class="text-xs leading-relaxed text-ink-600">
            Antes de importar ou apagar dados, gere um backup completo. Em produção, valide o arquivo em uma base de teste primeiro.
          </p>
        </div>
      </div>
    </div>
  </div>

  <div class="mt-5 grid gap-4 md:grid-cols-2">

    <div class="card flex flex-col overflow-hidden">
      <div class="flex-1 p-5">
        <div class="mb-1 flex items-center gap-2.5">
          <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-50 text-brand-600">
            <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
          </span>
          <h3 class="text-sm font-bold text-ink-950">Backup completo</h3>
        </div>
        <p class="mt-2 text-sm text-ink-500">Exporta estrutura e dados de todas as tabelas da base atual em um único arquivo SQL.</p>
      </div>
      <form method="post" class="px-5 pb-5">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="backup_action" value="full_export">
        <button class="btn-primary w-full" type="submit">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
          Baixar backup SQL
        </button>
      </form>
    </div>

    <div class="card flex flex-col overflow-hidden">
      <div class="flex-1 p-5">
        <div class="mb-1 flex items-center gap-2.5">
          <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-sky-50 text-sky-600">
            <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
          </span>
          <h3 class="text-sm font-bold text-ink-950">Clientes e fornecedores</h3>
        </div>
        <p class="mt-2 text-sm text-ink-500">Gera um arquivo apenas com <code class="rounded bg-ink-100 px-1 py-0.5 font-mono text-[11px]">clients</code> e <code class="rounded bg-ink-100 px-1 py-0.5 font-mono text-[11px]">suppliers</code>, incluindo estrutura e dados.</p>
      </div>
      <form method="post" class="px-5 pb-5">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="backup_action" value="contacts_export">
        <button class="btn-ghost w-full" type="submit">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
          Baixar cadastros
        </button>
      </form>
    </div>

    <div class="card flex flex-col overflow-hidden">
      <div class="flex-1 p-5">
        <div class="mb-1 flex items-center gap-2.5">
          <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-violet-50 text-violet-600">
            <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline></svg>
          </span>
          <h3 class="text-sm font-bold text-ink-950">Estrutura sem dados</h3>
        </div>
        <p class="mt-2 text-sm text-ink-500">Exporta apenas a estrutura das tabelas. Útil para subir ambiente novo sem registros operacionais.</p>
      </div>
      <form method="post" class="px-5 pb-5">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="backup_action" value="structure_export">
        <button class="btn-ghost w-full" type="submit">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
          Baixar estrutura
        </button>
      </form>
    </div>

    <div class="card flex flex-col overflow-hidden">
      <div class="flex-1 p-5">
        <div class="mb-1 flex items-center gap-2.5">
          <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-amber-50 text-amber-600">
            <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
          </span>
          <h3 class="text-sm font-bold text-ink-950">Pré-visualizar arquivo</h3>
        </div>
        <p class="mt-2 text-sm text-ink-500">Lê o arquivo <code class="rounded bg-ink-100 px-1 py-0.5 font-mono text-[11px]">.sql</code> sem importar nada e mostra tabelas, agency_id, roles e alertas de compatibilidade.</p>
      </div>
      <form method="post" enctype="multipart/form-data" class="px-5 pb-5">
        <input type="hidden" name="csrf" value="<?= h($token) ?>">
        <input type="hidden" name="backup_action" value="preview_backup">
        <div class="mb-3">
          <label class="label-field" for="preview_file">Arquivo SQL</label>
          <input class="input-field file:mr-3 file:rounded-lg file:border-0 file:bg-ink-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-ink-700" id="preview_file" type="file" name="backup_file" accept=".sql" required>
        </div>
        <button class="btn-soft border-brand-200 text-brand-700 hover:bg-brand-50 hover:text-brand-800" type="submit">
          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
          Analisar arquivo
        </button>
      </form>
    </div>

    <div class="card flex flex-col overflow-hidden md:col-span-2">
      <div class="flex-1 p-5">
        <div class="mb-1 flex items-center gap-2.5">
          <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
            <svg class="h-4.5 w-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
          </span>
          <h3 class="text-sm font-bold text-ink-950">Importar backup</h3>
        </div>
        <p class="mt-2 text-sm text-ink-500">Restaura um arquivo <code class="rounded bg-ink-100 px-1 py-0.5 font-mono text-[11px]">.sql</code> na base atual. Use apenas arquivos confiáveis e compatíveis com o sistema.</p>
      </div>
      <div class="grid gap-6 border-t border-ink-100 p-5 md:grid-cols-2">
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="backup_action" value="import_backup">
          <div class="mb-3">
            <label class="label-field" for="import_file">Arquivo SQL</label>
            <input class="input-field file:mr-3 file:rounded-lg file:border-0 file:bg-ink-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-ink-700" id="import_file" type="file" name="backup_file" accept=".sql" required>
          </div>
          <button class="btn-soft border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 hover:text-emerald-800" type="submit" onclick="return confirm('Importar este backup na base atual?');">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
            Importar backup
          </button>
        </form>

        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?= h($token) ?>">
          <input type="hidden" name="backup_action" value="agency_import">
          <div class="mb-3">
            <label class="label-field" for="target_agency_id">Importar para empresa</label>
            <select class="select-field" id="target_agency_id" name="target_agency_id" required>
              <?php foreach ($agencies as $agency): ?>
                <option value="<?= (int)$agency['id'] ?>"><?= h($agency['label']) ?> (#<?= (int)$agency['id'] ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="label-field" for="agency_import_file">Arquivo SQL</label>
            <input class="input-field file:mr-3 file:rounded-lg file:border-0 file:bg-ink-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-ink-700" id="agency_import_file" type="file" name="backup_file" accept=".sql" required>
          </div>
          <button class="btn-soft border-brand-200 text-brand-700 hover:bg-brand-50 hover:text-brand-800" type="submit" onclick="return confirm('Importar os dados para a empresa selecionada com remapeamento de agency_id e IDs internos?');">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="5" cy="6" r="3"></circle><path d="M5 9v12"></path><circle cx="19" cy="6" r="3"></circle><path d="M19 9v4a2 2 0 0 1-2 2h-8"></path><path d="M9 15l-2-2 2-2"></path><path d="M15 17l2 2-2 2"></path></svg>
            Importar para empresa
          </button>
        </form>
      </div>
    </div>

  </div>

  <?php if (is_array($preview)): ?>
    <div class="card mt-5 overflow-hidden">
      <div class="border-b border-ink-100 px-5 py-4">
        <p class="text-[11px] font-bold uppercase tracking-wider text-ink-500">Pré-visualização</p>
        <h3 class="mt-0.5 text-sm font-bold text-ink-950">Leitura do arquivo antes do import</h3>
        <p class="mt-0.5 text-xs text-ink-500">Nada foi importado. Esta leitura serve apenas para validar compatibilidade.</p>
      </div>
      <div class="p-5">
        <div class="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-4">
          <div class="rounded-xl border border-ink-100 bg-ink-50/60 px-3.5 py-3">
            <p class="text-[11px] font-medium text-ink-500">Tabelas</p>
            <p class="mt-0.5 text-sm font-bold text-ink-950"><?= (int)$preview['table_count'] ?></p>
          </div>
          <div class="rounded-xl border border-ink-100 bg-ink-50/60 px-3.5 py-3">
            <p class="text-[11px] font-medium text-ink-500">FOREIGN KEY</p>
            <p class="mt-0.5 text-sm font-bold text-ink-950"><?= (int)$preview['foreign_key_count'] ?></p>
          </div>
          <div class="rounded-xl border border-ink-100 bg-ink-50/60 px-3.5 py-3">
            <p class="text-[11px] font-medium text-ink-500">agency_id encontrados</p>
            <p class="mt-0.5 text-sm font-bold text-ink-950"><?= $preview['agency_ids'] ? h(implode(', ', $preview['agency_ids'])) : '—' ?></p>
          </div>
          <div class="rounded-xl border border-ink-100 bg-ink-50/60 px-3.5 py-3">
            <p class="text-[11px] font-medium text-ink-500">users.role</p>
            <p class="mt-0.5 text-sm font-bold text-ink-950"><?= $preview['role_values'] ? h(implode(', ', $preview['role_values'])) : '—' ?></p>
          </div>
        </div>

        <?php if (!empty($preview['warnings'])): ?>
          <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <p class="mb-1 font-semibold">Alertas</p>
            <ul class="list-disc space-y-0.5 pl-4">
              <?php foreach ($preview['warnings'] as $warning): ?>
                <li><?= h($warning) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php else: ?>
          <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
            Nenhum alerta estrutural óbvio foi encontrado.
          </div>
        <?php endif; ?>

        <?php if (!empty($preview['agency_ids'])): ?>
          <div class="mt-3 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
            Para <strong>Importar para empresa</strong>, o sistema ignora o <code class="rounded bg-sky-100 px-1 py-0.5 font-mono text-[11px]">agency_id</code> do arquivo e grava tudo na empresa escolhida.
          </div>
        <?php endif; ?>

        <div class="mt-5 grid gap-4 lg:grid-cols-2">
          <div class="rounded-xl border border-ink-100 p-4">
            <h3 class="mb-3 text-xs font-bold uppercase tracking-wider text-ink-500">Tabelas no arquivo</h3>
            <div class="flex flex-wrap gap-1.5">
              <?php foreach ($preview['tables'] as $table): ?>
                <code class="rounded-md bg-ink-100 px-1.5 py-0.5 text-[11px] font-semibold text-ink-700"><?= h($table) ?></code>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="rounded-xl border border-ink-100 p-4">
            <h3 class="mb-3 text-xs font-bold uppercase tracking-wider text-ink-500">Linhas por tabela</h3>
            <table class="table-modern">
              <tbody>
                <?php foreach ($preview['insert_counts'] as $table => $count): ?>
                  <tr>
                    <td><code class="rounded-md bg-ink-100 px-1.5 py-0.5 text-[11px] font-semibold text-ink-700"><?= h($table) ?></code></td>
                    <td class="text-right font-semibold text-ink-900"><?= (int)$count ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <div class="mt-5 rounded-2xl border border-red-200/70 bg-gradient-to-b from-white to-red-50/60 p-5 shadow-sm">
    <div class="mb-4 flex items-start gap-2.5">
      <svg class="mt-0.5 h-5 w-5 shrink-0 text-red-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
      <div>
        <h3 class="text-sm font-bold text-red-700">Apagar dados</h3>
        <p class="text-xs text-ink-500">Apague tudo ou apenas os grupos que fizerem sentido para esta limpeza.</p>
      </div>
    </div>

    <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800">
      Se apagar <strong>usuários</strong> e <strong>empresas</strong>, o acesso volta quando a página de login recriar automaticamente o master e a empresa <code class="rounded bg-amber-100 px-1 py-0.5 font-mono">id=1</code>.
    </div>

    <form method="post" id="purgeOperationalDataForm">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <input type="hidden" name="backup_action" value="purge_operational_data">

      <div class="mb-4 space-y-2.5">
        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-ink-100 bg-white px-4 py-3">
          <input class="mt-0.5 h-4 w-4 accent-brand-600" type="radio" name="purge_mode" value="operational" checked>
          <span>
            <span class="block text-sm font-bold text-ink-900">Somente operacional</span>
            <span class="mt-0.5 block text-xs text-ink-500">Vendas, viagens, passageiros, segmentos, reembolsos, serviços, contadores e logs.</span>
          </span>
        </label>

        <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-ink-100 bg-white px-4 py-3">
          <input class="mt-0.5 h-4 w-4 accent-brand-600" type="radio" name="purge_mode" value="all">
          <span>
            <span class="block text-sm font-bold text-ink-900">Apagar tudo</span>
            <span class="mt-0.5 block text-xs text-ink-500">Empresas, usuários, clientes, fornecedores e todo o conteúdo operacional.</span>
          </span>
        </label>

        <div class="rounded-xl border border-ink-100 bg-white px-4 py-3">
          <label class="flex cursor-pointer items-center gap-3">
            <input class="h-4 w-4 accent-brand-600" type="radio" name="purge_mode" value="custom">
            <span class="text-sm font-bold text-ink-900">Escolher seções</span>
          </label>
          <div class="mt-3 grid gap-1.5 sm:grid-cols-2">
            <label class="flex cursor-pointer items-center gap-2.5">
              <input class="purge-section h-4 w-4 accent-brand-600" type="checkbox" name="purge_sections[]" value="operational">
              <span class="text-sm text-ink-700">Operacional</span>
            </label>
            <label class="flex cursor-pointer items-center gap-2.5">
              <input class="purge-section h-4 w-4 accent-brand-600" type="checkbox" name="purge_sections[]" value="clients">
              <span class="text-sm text-ink-700">Clientes</span>
            </label>
            <label class="flex cursor-pointer items-center gap-2.5">
              <input class="purge-section h-4 w-4 accent-brand-600" type="checkbox" name="purge_sections[]" value="suppliers">
              <span class="text-sm text-ink-700">Fornecedores</span>
            </label>
            <label class="flex cursor-pointer items-center gap-2.5">
              <input class="purge-section h-4 w-4 accent-brand-600" type="checkbox" name="purge_sections[]" value="users">
              <span class="text-sm text-ink-700">Usuários</span>
            </label>
            <label class="flex cursor-pointer items-center gap-2.5">
              <input class="purge-section h-4 w-4 accent-brand-600" type="checkbox" name="purge_sections[]" value="agencies">
              <span class="text-sm text-ink-700">Empresas</span>
            </label>
          </div>
        </div>
      </div>

      <label class="mb-4 flex cursor-pointer items-center gap-2.5">
        <input class="h-4 w-4 accent-brand-600" type="checkbox" name="warn_backup" id="warnBackup" value="1">
        <span class="text-sm text-ink-700">Entendo que devo gerar um backup antes de continuar.</span>
      </label>

      <button class="btn-soft border-red-300 bg-white text-red-700 hover:bg-red-50 hover:text-red-800" type="submit" id="purgeSubmitBtn" disabled>
        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
        Apagar dados selecionados
      </button>
    </form>
  </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('purgeOperationalDataForm');
  if (!form) return;

  const warnBackup = document.getElementById('warnBackup');
  const submitBtn = document.getElementById('purgeSubmitBtn');
  const modeInputs = Array.from(form.querySelectorAll('input[name="purge_mode"]'));
  const sectionInputs = Array.from(form.querySelectorAll('.purge-section'));

  function syncPurgeButton() {
    const mode = modeInputs.find((input) => input.checked)?.value || 'operational';
    const customOk = mode !== 'custom' || sectionInputs.some((input) => input.checked);
    const ready = !!warnBackup?.checked && customOk;
    submitBtn.disabled = !ready;
  }

  warnBackup?.addEventListener('change', syncPurgeButton);
  modeInputs.forEach((input) => input.addEventListener('change', syncPurgeButton));
  sectionInputs.forEach((input) => input.addEventListener('change', syncPurgeButton));

  form.addEventListener('submit', function (event) {
    const mode = modeInputs.find((input) => input.checked)?.value || 'operational';
    const message = mode === 'all'
      ? 'Apagar tudo agora? Isso inclui empresas, usuários, clientes, fornecedores e dados operacionais.'
      : mode === 'custom'
        ? 'Apagar as seções selecionadas agora?'
        : 'Apagar apenas os dados operacionais agora?';

    if (!confirm(message)) {
      event.preventDefault();
    }
  });

  syncPurgeButton();
});
</script>
<?php
$body = ob_get_clean();
require __DIR__ . '/../inc/layout.php';
