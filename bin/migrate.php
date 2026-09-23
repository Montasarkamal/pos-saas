#!/usr/bin/env php
<?php
/**
 * KAMALTUR POS — migration runner (Phase 0)
 *
 * Applies pending SQL files from database/migrations/ in order and records
 * them in the `schema_migrations` table. Framework-free so it also works on
 * Hostinger shared hosting.
 *
 * Usage:
 *   php bin/migrate.php          # apply pending migrations
 *   php bin/migrate.php status   # list applied/pending
 */

declare(strict_types=1);

// Reuse the existing app bootstrap so the same .env + PDO handle is used.
require __DIR__ . '/../inc/db.php';

const MIGRATIONS_DIR = __DIR__ . '/../database/migrations';

function migrate_list(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $files = glob($dir . '/*.sql') ?: [];
    sort($files, SORT_STRING);
    return array_map('basename', $files);
}

$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    version    VARCHAR(255) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$applied = array_map('strval', $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
$all     = migrate_list(MIGRATIONS_DIR);
$pending = array_values(array_diff($all, $applied));

if (($argv[1] ?? '') === 'status') {
    echo "Migrations directory: " . MIGRATIONS_DIR . PHP_EOL;
    echo "Applied : " . count($applied) . PHP_EOL;
    echo "Pending : " . count($pending) . PHP_EOL;
    foreach ($pending as $p) {
        echo "  pending: {$p}" . PHP_EOL;
    }
    exit(0);
}

if ($pending === []) {
    echo "All migrations applied (up to date)." . PHP_EOL;
    exit(0);
}

$failed = false;
foreach ($pending as $version) {
    $sql = file_get_contents(MIGRATIONS_DIR . '/' . $version);
    if ($sql === false) {
        fwrite(STDERR, "FAILED {$version}: cannot read file" . PHP_EOL);
        $failed = true;
        break;
    }
    try {
        $pdo->exec($sql);
        $st = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (?)');
        $st->execute([$version]);
        echo "applied: {$version}" . PHP_EOL;
    } catch (Throwable $e) {
        fwrite(STDERR, "FAILED {$version}: " . $e->getMessage() . PHP_EOL);
        $failed = true;
        break;
    }
}

exit($failed ? 1 : 0);