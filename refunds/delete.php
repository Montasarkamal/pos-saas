<?php
// refunds/delete.php
require_once '../inc/auth.php';
require_login();
require_once '../inc/db.php';
require_once '../inc/audit.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método não permitido');
}

if (!csrf_check($_POST['csrf'] ?? '')) {
    http_response_code(400);
    exit('CSRF inválido');
}

try {
    ensure_audit_table($pdo);
    $pdo->beginTransaction();

    // apaga logs primeiro (se quiser manter integridade)
    $stL = $pdo->prepare("DELETE rl FROM refund_logs rl INNER JOIN refunds r ON r.id = rl.refund_id WHERE rl.refund_id = :id AND r.agency_id = :agency_id");
    $stL->execute([':id' => $id, ':agency_id' => agency_id()]);

    // apaga o reembolso
    $stR = $pdo->prepare("DELETE FROM refunds WHERE id = :id AND agency_id = :agency_id LIMIT 1");
    $stR->execute([':id' => $id, ':agency_id' => agency_id()]);

    if ($stR->rowCount() > 0) audit_log($pdo, 'delete', 'refund', $id);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    // pode logar o erro se quiser
}

header('Location: index.php?deleted=1');
exit;
