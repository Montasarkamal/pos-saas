<?php
// inc/lists.php — central JSON lists for Airlines / Classes / Baggage.
declare(strict_types=1);

require __DIR__ . '/auth.php';
require_login();
require_once __DIR__ . '/list_store.php';

header('Content-Type: application/json; charset=UTF-8');

echo json_encode(list_store_all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
