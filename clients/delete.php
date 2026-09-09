<?php
require __DIR__ . '/../inc/auth.php';
require_login();
require_once __DIR__ . '/../inc/audit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Método não permitido'); }
if (!csrf_check($_POST['csrf'] ?? '')) { http_response_code(400); exit('CSRF inválido'); }

$id = (int)($_POST['id'] ?? 0);
[$agencyCondition, $agencyParams] = agency_scope_sql('agency_id');

// ملاحظة: يمكن وضع تحقق لاحقًا لمنع حذف عميل لديه فواتير (بسبب FK RESTRICT)
$st = $pdo->prepare("DELETE FROM clients WHERE id=? AND $agencyCondition");
try {
  $st->execute(array_merge([$id], $agencyParams));
  if ($st->rowCount() > 0) audit_log($pdo, 'delete', 'client', $id);
} catch (Throwable $e) {
  // في حال وجود فواتير مرتبطة سيمنع الحذف (RESTRICT)
  // أعد توجيه مع رسالة (يمكنك تحسينها لاحقًا)
}

header('Location: /clients/index.php');
exit;
