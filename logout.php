<?php
// logout.php
require __DIR__ . '/inc/session.php';
require __DIR__ . '/inc/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_check($_POST['csrf'] ?? '')) {
  http_response_code(405);
  header('Allow: POST');
  exit('Método não permitido.');
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
  $params = session_get_cookie_params();
  setcookie(session_name(), '', [
    'expires' => time() - 42000,
    'path' => $params['path'],
    'domain' => $params['domain'] ?? '',
    'secure' => (bool)$params['secure'],
    'httponly' => (bool)$params['httponly'],
    'samesite' => $params['samesite'] ?? 'Lax',
  ]);
}
session_destroy();
header('Location: /login.php');
exit;
