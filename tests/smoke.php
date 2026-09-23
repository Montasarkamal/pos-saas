<?php
/**
 * KAMALTUR POS — smoke tests (Phase 0)
 *
 * Verifies the application core without a web server:
 *   - database connectivity
 *   - required tables exist
 *   - seeded users present
 *   - core libraries load and key functions exist
 *
 * Usage: php tests/smoke.php   (exit code 0 = ok)
 */

declare(strict_types=1);

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "  ok  {$label}" . PHP_EOL;
    } else {
        $failures++;
        echo "  FAIL {$label}" . ($detail !== '' ? " :: {$detail}" : '') . PHP_EOL;
    }
}

echo "KAMALTUR POS smoke tests" . PHP_EOL;
echo "------------------------" . PHP_EOL;

// ---- 1. Database -------------------------------------------------------
$pdo = null;
try {
    require dirname(__DIR__) . '/inc/db.php';
    check('db connects', $pdo instanceof PDO);
} catch (Throwable $e) {
    check('db connects', false, $e->getMessage());
}

if ($pdo instanceof PDO) {
    check('db query', $pdo->query('SELECT 1') !== false);

    $required = [
        'agencies', 'users', 'clients', 'suppliers', 'invoices',
        'invoice_trips', 'passengers', 'segments', 'aux_services',
        'invoice_counters', 'service_sales', 'service_hotels',
        'service_hotel_rooms', 'service_guests', 'service_cars',
        'service_insurance', 'service_details', 'refunds', 'refund_logs',
        'audit_logs', 'schema_migrations',
    ];
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_values(array_diff($required, $tables));
    check('all required tables', $missing === [], implode(', ', $missing));

    // ---- 2. Seed data ---------------------------------------------------
    $master = $pdo->query("SELECT COUNT(*) FROM users WHERE login = 'master'")->fetchColumn();
    check('superadmin user seeded', (int)$master === 1);
    $agency = $pdo->query('SELECT COUNT(*) FROM agencies')->fetchColumn();
    check('agency seeded', (int)$agency >= 1);

    // ---- 3. Migrations tracked ------------------------------------------
    $records = $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    check('schema_migrations tracked', (int)$records >= 1);

    // ---- 4. Core libraries load -----------------------------------------
    try {
        require_once dirname(__DIR__) . '/inc/helpers.php';
        check('helpers.php loads', true);

        require_once dirname(__DIR__) . '/inc/invoices_lib.php';
        check('next_invoice_number() exists', function_exists('next_invoice_number'));
    } catch (Throwable $e) {
        check('core libs load', false, $e->getMessage());
    }

    // ---- 5. Converted pages capture $body (ob_start/ob_get_clean) --------
    $convertedPages = [
        'dashboard.php', 'profile.php',
        'sales/index.php', 'sales/show.php', 'sales/create.php', 'sales/edit.php', 'sales/export.php',
        'clients/index.php', 'clients/create.php', 'clients/edit.php', 'clients/show.php',
        'suppliers/index.php', 'suppliers/create.php', 'suppliers/edit.php', 'suppliers/show.php',
        'refunds/index.php', 'refunds/create.php', 'refunds/edit.php', 'refunds/show.php',
        'reports/index.php', 'services/create.php',
        'settings/index.php', 'settings/about.php', 'settings/company.php', 'settings/lists.php',
        'settings/backup.php',
        'users/index.php', 'users/create.php', 'users/edit.php',
        'master/dashboard.php',
    ];
    $broken = [];
    foreach ($convertedPages as $rel) {
        $file = dirname(__DIR__) . '/' . $rel;
        if (!is_file($file)) { $broken[] = $rel . ' (missing)'; continue; }
        $src = (string)file_get_contents($file);
        if (!str_contains($src, 'ob_start') || !str_contains($src, 'ob_get_clean')) {
            $broken[] = $rel;
        }
    }
    check('converted pages capture $body', $broken === [], implode(', ', $broken));
}

echo "------------------------" . PHP_EOL;
echo $failures === 0 ? "ALL OK" : "{$failures} FAILURE(S)";
echo PHP_EOL;
exit($failures === 0 ? 0 : 1);