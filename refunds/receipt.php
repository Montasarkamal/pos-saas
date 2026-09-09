<?php
require __DIR__ . '/../inc/db.php';
require __DIR__ . '/../inc/auth.php'; require_login();
header('Content-Type: text/html; charset=UTF-8');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { die('ID inválido.'); }

$stmt = $pdo->prepare("
    SELECT r.*, c.name AS client_name
      FROM refunds r
 LEFT JOIN clients c ON c.id = r.client_id AND c.agency_id = r.agency_id
     WHERE r.id = :id AND r.agency_id = :agency_id
");
$stmt->execute([':id' => $id, ':agency_id' => agency_id()]);
$refund = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$refund) { die('Reembolso não encontrado.'); }

// aqui você pode impedir se não estiver REEMBOLSADO, se quiser:
if ($refund['status'] !== 'REEMBOLSADO') {
    // opcional: die('Reembolso ainda não concluído.');
}

function money_br($n){ return 'R$ ' . number_format((float)$n, 2, ',', '.'); }
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Recibo de Reembolso #<?= (int)$refund['id'] ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; margin: 40px; }
    h1 { font-size: 20px; margin-bottom: 20px; }
    .linha { margin-bottom: 8px; }
    .negrito { font-weight: bold; }
    hr { margin: 20px 0; }
  </style>
</head>
<body>
  <h1>Recibo de Reembolso</h1>

  <div class="linha"><span class="negrito">Nº Reembolso:</span> #<?= (int)$refund['id'] ?></div>
  <div class="linha"><span class="negrito">Cliente:</span> <?= e($refund['client_name'] ?? '—') ?></div>
  <div class="linha"><span class="negrito">Motivo:</span> <?= e($refund['motivo']) ?></div>
  <div class="linha"><span class="negrito">Valor reembolsado:</span> <?= money_br($refund['valor_recebido']) ?></div>
  <div class="linha">
    <span class="negrito">Data de pagamento:</span>
    <?= $refund['data_pagamento'] ? date('d/m/Y', strtotime($refund['data_pagamento'])) : '—' ?>
  </div>

  <hr>

  <p>
    Declaramos que o valor acima foi reembolsado ao cliente referente aos serviços
    contratados junto à KamalTur.
  </p>

  <br><br><br>

  <div class="linha">
    _______________________________________________<br>
    Assinatura e carimbo
  </div>
</body>
</html>
