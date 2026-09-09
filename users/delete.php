<?php
require __DIR__ . '/../inc/auth.php';
require_role_admin();
require_once __DIR__ . '/../inc/audit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Método não permitido'); }
if (!csrf_check($_POST['csrf'] ?? '')) { http_response_code(400); exit('CSRF inválido'); }

$id = (int)($_POST['id'] ?? 0);
if ($id === (int)($_SESSION['uid'])) { http_response_code(400); exit('Não é permitido excluir a si mesmo.'); }

[$agencyCondition, $agencyParams] = agency_scope_sql('agency_id');
$stUser = $pdo->prepare("SELECT id, email, login, role FROM users WHERE id=? AND $agencyCondition LIMIT 1");
$stUser->execute(array_merge([$id], $agencyParams));
$targetUser = $stUser->fetch(PDO::FETCH_ASSOC);
if (!$targetUser) { http_response_code(404); exit('Usuário não encontrado.'); }
if (is_master_user($targetUser)) { http_response_code(403); exit('A conta master não pode ser excluída.'); }

try {
  $st = $pdo->prepare("DELETE FROM users WHERE id=? AND $agencyCondition");
  $st->execute(array_merge([$id], $agencyParams));
  audit_log($pdo, 'delete', 'user', $id, ['login' => (string)($targetUser['login'] ?? ''), 'role' => (string)($targetUser['role'] ?? '')]);
  header('Location: /users/index.php?msg=deleted');
  exit;
} catch (PDOException $e) {
  if ($e->getCode() !== '23000') {
    error_log('users/delete.php: ' . $e->getMessage());
    header('Location: /users/index.php?msg=error');
    exit;
  }

  $st = $pdo->prepare("UPDATE users SET is_active=0 WHERE id=? AND $agencyCondition");
  $st->execute(array_merge([$id], $agencyParams));
  audit_log($pdo, 'deactivate', 'user', $id, ['login' => (string)($targetUser['login'] ?? ''), 'role' => (string)($targetUser['role'] ?? '')]);
  header('Location: /users/index.php?msg=deactivated');
  exit;
}

header('Location: /users/index.php?msg=ok');
exit;
