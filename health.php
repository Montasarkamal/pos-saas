<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$ok = true;
$database = 'unavailable';

try {
    require __DIR__ . '/inc/db.php';
    $pdo->query('SELECT 1');
    $database = 'ok';
} catch (Throwable $e) {
    $ok = false;
    error_log('health check: ' . $e->getMessage());
}

http_response_code($ok ? 200 : 503);
echo json_encode([
    'status' => $ok ? 'ok' : 'degraded',
    'database' => $database,
    'time' => gmdate('c'),
], JSON_UNESCAPED_SLASHES);
