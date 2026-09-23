<?php
declare(strict_types=1);
// Read-only: show users logins (no password hashes in full).
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
echo "== production users ==\n";
foreach ($pdo->query("SELECT id, login, name, role, is_active, agency_id FROM users ORDER BY id") as $r) {
    echo json_encode($r) . "\n";
}
echo "== agencies ==\n";
foreach ($pdo->query("SELECT id, name FROM agencies ORDER BY id") as $r) {
    echo json_encode($r) . "\n";
}
echo "== invoice counters ==\n";
foreach ($pdo->query("SELECT * FROM invoice_counters ORDER BY id") as $r) {
    echo json_encode($r) . "\n";
}
echo "DONE\n";