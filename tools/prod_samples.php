<?php
declare(strict_types=1);
// Read-only production data samples for post-deploy verification.
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
    exit(1);
}

echo "== invoices by agency ==\n";
foreach ($pdo->query("SELECT agency_id, COUNT(*) n FROM invoices GROUP BY agency_id") as $r) {
    echo json_encode($r) . "\n";
}
echo "== master agency invoices (agency_id=1) ==\n";
foreach ($pdo->query("SELECT id, invoice_number, client_id, total_amount, status FROM invoices WHERE agency_id=1 ORDER BY id DESC LIMIT 5") as $r) {
    echo json_encode($r) . "\n";
}
echo "== agency6 invoices (agency_id=6) ==\n";
foreach ($pdo->query("SELECT id, invoice_number, client_id, total_amount, status FROM invoices WHERE agency_id=6 ORDER BY id DESC LIMIT 5") as $r) {
    echo json_encode($r) . "\n";
}
echo "== clients column sample ==\n";
foreach ($pdo->query("SELECT id, name FROM clients ORDER BY id DESC LIMIT 5") as $r) {
    echo json_encode($r) . "\n";
}
echo "== suppliers sample ==\n";
foreach ($pdo->query("SELECT id, name FROM suppliers ORDER BY id LIMIT 5") as $r) {
    echo json_encode($r) . "\n";
}
echo "== passengers sample ==\n";
foreach ($pdo->query("SELECT id, full_name FROM passengers ORDER BY id DESC LIMIT 3") as $r) {
    echo json_encode($r) . "\n";
}
echo "== segments sample ==\n";
foreach ($pdo->query("SELECT id, segment_number FROM segments ORDER BY id DESC LIMIT 3") as $r) {
    echo json_encode($r) . "\n";
}
echo "DONE\n";