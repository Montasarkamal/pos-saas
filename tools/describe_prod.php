<?php
declare(strict_types=1);
// Read-only: describe key tables on the connected database.
$candidates = [__DIR__ . '/inc/db.php', __DIR__ . '/../inc/db.php', 'inc/db.php'];
$loaded = false;
foreach ($candidates as $c) {
    if (is_file($c)) {
        require $c;
        $loaded = true;
        break;
    }
}
if (!$loaded) {
    fwrite(STDERR, "could not locate inc/db.php\n");
    exit(1);
}

$tables = ['invoices', 'clients', 'users', 'suppliers', 'invoice_trips',
           'invoice_counters', 'passengers', 'segments', 'agencies', 'refunds'];
foreach ($tables as $t) {
    echo "=== $t ===\n";
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM `$t`") as $c) {
            echo "  " . $c['Field'] . ' | ' . $c['Type'] . "\n";
        }
    } catch (Throwable $e) {
        echo "  ERROR: " . $e->getMessage() . "\n";
    }
}
echo "DONE\n";