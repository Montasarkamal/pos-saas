<?php
// sales/update_status.php — تحديث حالة الفاتورة عبر AJAX (JSON)

declare(strict_types=1);

require __DIR__ . '/../inc/auth.php';
require_login();

require __DIR__ . '/../inc/db.php';          // مهم: لتعريف $pdo
require_once __DIR__ . '/../inc/invoices_lib.php';
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/audit.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false, 'error'=>'Method not allowed']);
  exit;
}

// لا نسمح إلا بـ JSON
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>'Bad JSON']);
  exit;
}

// تحقق من CSRF
$csrf = (string)($data['csrf'] ?? '');

if (!csrf_check($csrf)) {
  http_response_code(403);
  echo json_encode(['ok'=>false, 'error'=>'CSRF inválido']);
  exit;
}

// تحقق من المعطيات
$id = (int)($data['id'] ?? 0);
$status = mb_strtolower(trim((string)($data['status'] ?? '')), 'UTF-8');
if ($status === 'não pago') $status = 'nao pago';

$allowed = ['nao pago','pago parcial','pago'];
if ($id <= 0 || !in_array($status, $allowed, true)) {
  http_response_code(422);
  echo json_encode(['ok'=>false, 'error'=>'Parâmetros inválidos']);
  exit;
}

try {
  [$invoiceAgencyCondition, $invoiceAgencyParams] = agency_scope_sql('agency_id');
  $find = $pdo->prepare("SELECT agency_id, status, invoice_number FROM invoices WHERE id=? AND $invoiceAgencyCondition LIMIT 1");
  $find->execute(array_merge([$id], $invoiceAgencyParams));
  $invoice = $find->fetch(PDO::FETCH_ASSOC);
  if (!$invoice) {
    http_response_code(404);
    echo json_encode(['ok'=>false, 'error'=>'Venda não encontrada']);
    exit;
  }

  // تحديث بسيط — لا يلزم Transaction إلا إذا لديك عمليات إضافية
  $st = $pdo->prepare("UPDATE invoices SET status=? WHERE id=? AND agency_id=? LIMIT 1");
  $st->execute([$status, $id, (int)$invoice['agency_id']]);

  audit_log($pdo, 'status_change', 'invoice', $id, [
    'invoice_number' => (string)($invoice['invoice_number'] ?? ''),
    'from' => normalize_status($invoice['status'] ?? ''),
    'to' => $status,
  ]);

  $check = $pdo->prepare("SELECT 1 FROM invoices WHERE id=? AND agency_id=? LIMIT 1");
  $check->execute([$id, (int)$invoice['agency_id']]);
  echo json_encode(['ok'=>(bool)$check->fetchColumn(), 'status'=>$status]);
} catch (Throwable $e) {
  error_log('update_status.php DB error: '.$e->getMessage());
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>'DB error']);
}
