<?php
declare(strict_types=1);
// Read-only: produce signed public URLs for a real invoice.
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
require_once __DIR__ . '/inc/public_link.php';

$id = 290; // invoice 260084 (agency 6)
$inv = $pdo->query("SELECT invoice_number FROM invoices WHERE id=$id")->fetchColumn();
echo "invoice_id=$id invoice_number=$inv\n";
echo "PRINT_URL=" . build_public_url('invoice', $id) . "\n";
echo "VOUCHER_URL=" . build_public_url('voucher', $id) . "\n";
echo "DONE\n";