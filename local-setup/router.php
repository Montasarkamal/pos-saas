<?php
/**
 * Router for the PHP built-in dev server (php -S 127.0.0.1:8080 router.php)
 * Blocks the same sensitive paths the production .htaccess does.
 */

declare(strict_types=1);

$uri = $_SERVER['SCRIPT_NAME'] ?? '/';

if (preg_match('~^/(?:\.env(?:\..*)?|\.git|DB|backups|debug|tools|local-setup)(?:/|$)~i', $uri)
    || preg_match('~\.(?:sql|log|bak|ini|dist)$~i', $uri)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden";
    return true;
}

return false; // let the built-in server serve the file