<?php
declare(strict_types=1);
// Read-only snapshot of table row counts + sample rows. Safe to run anywhere.
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

$tables = [
    'agencies', 'users', 'clients', 'suppliers', 'invoices', 'invoice_trips',
    'passengers', 'segments', 'aux_services', 'invoice_counters', 'service_sales',
    'service_hotels', 'service_hotel_rooms', 'service_guests', 'service_cars',
    'service_insurance', 'service_details', 'refunds', 'refund_logs', 'audit_logs',
];

echo "== ROW COUNTS ==\n";
foreach ($tables as $t) {
    $c = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    echo str_pad($t, 22) . " = $c\n";
}

echo "== latest invoices ==\n";
foreach ($pdo->query("SELECT invoice_number, agency_id, total, created_at FROM invoices ORDER BY id DESC LIMIT 3") as $r) {
    echo json_encode($r) . "\n";
}

echo "== latest clients ==\n";
foreach ($pdo->query("SELECT id, name, client_type FROM clients ORDER BY id DESC LIMIT 3") as $r) {
    echo json_encode($r) . "\n";
}

echo "== suppliers ==\n";
foreach ($pdo->query("SELECT id, name FROM suppliers ORDER BY id DESC LIMIT 3") as $r) {
    echo json_encode($r) . "\n";
}

echo "== users (usernames only) ==\n";
foreach ($pdo->query("SELECT id, username, role FROM users ORDER BY id LIMIT 10") as $r) {
    echo json_encode($r) . "\n";
}

echo "== sales tables legacy check ==\n";
$extra = ['sales', 'sale_items', 'sale_payments', 'sale_item_flights', 'sale_item_hotels', 'client_attachments'];
foreach ($extra as $t) {
    $c = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    echo str_pad($t, 24) . " = $c\n";
}

echo "DONE\n";