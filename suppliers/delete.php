<?php
// suppliers/delete.php — Versão Segura
require __DIR__ . '/../inc/auth.php';
require_login();
require_once __DIR__ . '/../inc/audit.php';

// 2. التحقق من طريقة الإرسال والتوكن
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { 
    http_response_code(405); 
    exit('Método não permitido'); 
}

if (!csrf_check($_POST['csrf'] ?? '')) { 
    header('Location: /suppliers/index.php?msg=csrf');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
[$agencyCondition, $agencyParams] = agency_scope_sql('agency_id');

if ($id > 0) {
    try {
        // 3. محاولة الحذف
        $st = $pdo->prepare("DELETE FROM suppliers WHERE id = ? AND $agencyCondition");
        $st->execute(array_merge([$id], $agencyParams));
        if ($st->rowCount() > 0) audit_log($pdo, 'delete', 'supplier', $id);
        
        // لو الحذف نجح
        header('Location: /suppliers/index.php?msg=deleted');
        exit;

    } catch (PDOException $e) {
        // 4. التعامل مع قيود قاعدة البيانات (لو المورد مربوط بفواتير مثلاً)
        // الخطأ رقم 23000 هو أشهر خطأ للـ Foreign Key Constraint
        if ($e->getCode() === '23000') {
            header('Location: /suppliers/index.php?msg=error_fk');
        } else {
            header('Location: /suppliers/index.php?msg=error');
        }
        exit;
    }
}

header('Location: /suppliers/index.php');
exit;
