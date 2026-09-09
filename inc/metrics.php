<?php
// metrics.php — النسخة النهائية والمستقرة
require_once __DIR__ . '/helpers.php';

/**
 * ملاحظة هامة: 
 * تم إزالة الدوال التالية من هذا الملف لأنها معرفة مسبقاً في (auth.php و helpers.php):
 * 1. agency_id()
 * 2. month_window()
 * وذلك لتجنب خطأ "Cannot redeclare"
 */

/**
 * إحصائيات عامة (KPIs) لجميع الأوقات
 */
function totals_overall(PDO $pdo): array {
    $out = [
        'tot_clients' => 0,
        'tot_suppliers_active' => 0,
        'tot_invoices' => 0,
        'tot_billed' => 0.0,
        'tot_paid' => 0.0,
        'tot_unpaid' => 0.0,
        'tot_profit' => 0.0
    ];

    try {
        $aid = (int)agency_id();

        // مجموع العملاء
        $st = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE agency_id = ?");
        $st->execute([$aid]);
        $out['tot_clients'] = (int)$st->fetchColumn();

        // الموردين النشطين
        $st = $pdo->prepare("SELECT COUNT(*) FROM suppliers WHERE is_active=1 AND agency_id = ?");
        $st->execute([$aid]);
        $out['tot_suppliers_active'] = (int)$st->fetchColumn();

        // عدد الفواتير (غير الملغاة)
        $st = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE agency_id = ? AND LOWER(status) <> 'cancelado'");
        $st->execute([$aid]);
        $out['tot_invoices'] = (int)$st->fetchColumn();

        // إجمالي المبيعات
        $st = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE agency_id = ? AND LOWER(status) <> 'cancelado'");
        $st->execute([$aid]);
        $out['tot_billed'] = (float)$st->fetchColumn();

        // إجمالي المحصل (المدفوع)
        $st = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE agency_id = ? AND LOWER(status) <> 'cancelado' AND LOWER(status) IN ('pago','paid')");
        $st->execute([$aid]);
        $out['tot_paid'] = (float)$st->fetchColumn();

        // المبالغ المستحقة
        $out['tot_unpaid'] = max(0, $out['tot_billed'] - $out['tot_paid']);

        // إجمالي الربح (الهامش)
        $st = $pdo->prepare("SELECT COALESCE(SUM(margin_value),0) FROM invoices WHERE agency_id = ? AND LOWER(status) <> 'cancelado'");
        $st->execute([$aid]);
        $out['tot_profit'] = (float)$st->fetchColumn();

    } catch (Exception $e) {
        error_log("Error in totals_overall: " . $e->getMessage());
    }

    return $out;
}

/**
 * إحصائيات المرتجعات (Refunds)
 */
function refunds_stats(PDO $pdo): array {
    $out = ['total' => 0, 'processed' => 0, 'pending' => 0];

    try {
        $aid = (int)agency_id();
        $st = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN LOWER(status) IN ('pago','processed','finalizado','paid') THEN 1 ELSE 0 END) as processed
            FROM refunds 
            WHERE agency_id = ?
        ");
        $st->execute([$aid]);
        $res = $st->fetch(PDO::FETCH_ASSOC);
        
        $out['total'] = (int)($res['total'] ?? 0);
        $out['processed'] = (int)($res['processed'] ?? 0);
        $out['pending'] = max(0, $out['total'] - $out['processed']);
    } catch (Exception $e) {
        error_log("Error in refunds_stats: " . $e->getMessage());
    }

    return $out;
}

/**
 * مبيعات الشهر الحالي
 */
function sales_month(PDO $pdo, string $from, string $to): float {
    try {
        $st = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE agency_id = ? AND issue_date BETWEEN ? AND ? AND LOWER(status) <> 'cancelado'");
        $st->execute([agency_id(), $from, $to]);
        return (float)$st->fetchColumn();
    } catch (Exception $e) { return 0.0; }
}

/**
 * التحصيل خلال الشهر الحالي
 */
function paid_month(PDO $pdo, string $from, string $to): float {
    try {
        $st = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE agency_id = ? AND issue_date BETWEEN ? AND ? AND LOWER(status) IN ('pago','paid')");
        $st->execute([agency_id(), $from, $to]);
        return (float)$st->fetchColumn();
    } catch (Exception $e) { return 0.0; }
}

/**
 * أرباح الشهر الحالي
 */
function profit_month(PDO $pdo, string $from, string $to): float {
    try {
        $st = $pdo->prepare("SELECT COALESCE(SUM(margin_value),0) FROM invoices WHERE agency_id = ? AND issue_date BETWEEN ? AND ? AND LOWER(status) <> 'cancelado'");
        $st->execute([agency_id(), $from, $to]);
        return (float)$st->fetchColumn();
    } catch (Exception $e) { return 0.0; }
}

/**
 * حساب المبالغ المتأخرة لهذا الشهر
 */
function due_month($sales, $paid): float {
    return max(0, (float)$sales - (float)$paid);
}
