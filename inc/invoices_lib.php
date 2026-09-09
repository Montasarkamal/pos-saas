<?php
// inc/invoices_lib.php
// منطق الفواتير فقط (بدون دوال تنسيق عامة مثل brl/ymd_to_br)

if (!isset($pdo)) { require __DIR__ . '/db.php'; } // لو لم تُستدع سابقًا

if (!function_exists('inv_to_upper')) {
  function inv_to_upper($s) { return mb_strtoupper(trim((string)$s), 'UTF-8'); }
}

/**
 * توليد رقم فاتورة فريد بصيغة YY#### (مثال: 250001)
 * يجب استدعاؤها داخل Transaction لتجنّب السباق.
 */
if (!function_exists('next_invoice_number')) {
  function next_invoice_number(PDO $pdo): string {
    $year = (int)date('Y');
    $yy   = date('y'); // آخر رقمين من السنة

    // اقفل صف السنة الحالية
    $counterAgencyId = 0; // invoice_number is globally unique in the current schema.
    $ins = $pdo->prepare("INSERT IGNORE INTO invoice_counters (year, agency_id, last) VALUES (?, ?, 0)");
    $ins->execute([$year, $counterAgencyId]);
    $st = $pdo->prepare("SELECT last FROM invoice_counters WHERE year=? AND agency_id=? FOR UPDATE");
    $st->execute([$year, $counterAgencyId]);
    $row  = $st->fetch(PDO::FETCH_ASSOC);
    $last = $row ? (int)$row['last'] : 0;

    $prefix = $yy . '%';
    $mx = $pdo->prepare("
      SELECT MAX(CAST(SUBSTRING(invoice_number, 3) AS UNSIGNED))
      FROM invoices
      WHERE invoice_number LIKE ?
        AND invoice_number REGEXP '^[0-9]{6}$'
    ");
    $mx->execute([$prefix]);
    $maxExisting = (int)($mx->fetchColumn() ?: 0);

    $next = max($last, $maxExisting) + 1;

    $up = $pdo->prepare("UPDATE invoice_counters SET last=? WHERE year=? AND agency_id=?");
    $up->execute([$next, $year, $counterAgencyId]);

    // صيغة: YY + 4 أرقام (مثال: 250001)
    return sprintf("%s%04d", $yy, $next);
  }
}

/**
 * ملاحظة مهمة:
 * - تم حذف brl() من هنا لتجنب التعارض.
 * - استخدم brl() من inc/helpers.php فقط.
 */
