<?php
// sales/delete.php — حذف فاتورة بأمان (POST + CSRF + Transaction)
require __DIR__ . '/../inc/auth.php'; 
require_login();
require_once __DIR__ . '/../inc/audit.php';

/* =========================================================
   1) السماح بـ POST فقط + التحقق من CSRF
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: /sales/index.php?msg=method'); 
  exit;
}

$id   = (int)($_POST['id']   ?? 0);
$csrf =        $_POST['csrf'] ?? '';

if (!csrf_check($csrf)) {
  header('Location: /sales/index.php?msg=csrf'); 
  exit;
}

if ($id <= 0) {
  header('Location: /sales/index.php?msg=badid'); 
  exit;
}

/* =========================================================
   2) التأكد من وجود الفاتورة
   ========================================================= */
try {
  [$invoiceAgencyCondition, $invoiceAgencyParams] = agency_scope_sql('agency_id');
  $st = $pdo->prepare("SELECT invoice_number, agency_id FROM invoices WHERE id=? AND $invoiceAgencyCondition");
  $st->execute(array_merge([$id], $invoiceAgencyParams));
  $inv = $st->fetch(PDO::FETCH_ASSOC);
  if (!$inv) {
    header('Location: /sales/index.php?msg=notfound'); 
    exit;
  }
  $invoiceAgencyId = (int)$inv['agency_id'];

  ensure_audit_table($pdo);

  /* =========================================================
     3) الحذف داخل معاملة (Transaction)
        - نحذف التوابع أولاً (passengers/segments)
        - ثم نحذف الفاتورة
     ========================================================= */
  $pdo->beginTransaction();

  $pdo->prepare("DELETE FROM passengers WHERE invoice_id=? AND agency_id=?")->execute([$id, $invoiceAgencyId]);
  $pdo->prepare("DELETE FROM segments   WHERE invoice_id=? AND agency_id=?")->execute([$id, $invoiceAgencyId]);
  $pdo->prepare("DELETE FROM invoices   WHERE id=? AND agency_id=?")->execute([$id, $invoiceAgencyId]);

  audit_log($pdo, 'delete', 'invoice', $id, [
    'invoice_number' => (string)($inv['invoice_number'] ?? ''),
  ]);

  $pdo->commit();

  header('Location: /sales/index.php?msg=deleted'); 
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  // يمكنك تسجيل الخطأ لو رغبت:
  // error_log('Delete invoice '.$id.' failed: '.$e->getMessage());
  header('Location: /sales/index.php?msg=error'); 
  exit;
}
