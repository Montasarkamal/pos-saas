<?php
// refunds/state.php — Relatório de Estado Atual do Reembolso (A4 imprimível)

require_once '../inc/db.php';
require_once '../inc/auth.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    echo 'ID inválido.';
    exit;
}

// Carrega reembolso + relações
$stmt = $pdo->prepare("
    SELECT r.*, 
           c.name AS cliente_nome,
           c.document AS cliente_documento,
           s.name AS fornecedor_nome,
           i.invoice_number AS numero_fatura,
           i.id AS invoice_id,
           p.name AS passageiro_nome
      FROM refunds r
 LEFT JOIN clients    c ON c.id = r.client_id AND c.agency_id = r.agency_id
 LEFT JOIN suppliers  s ON s.id = r.supplier_id AND s.agency_id = r.agency_id
 LEFT JOIN invoices   i ON i.id = r.invoice_id AND i.agency_id = r.agency_id
 LEFT JOIN passengers p ON p.id = r.passenger_id AND p.agency_id = r.agency_id
     WHERE r.id = :id AND r.agency_id = :agency_id
");
$stmt->execute([':id' => $id, ':agency_id' => agency_id()]);
$refund = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$refund) {
    http_response_code(404);
    echo 'Reembolso não encontrado.';
    exit;
}

// Carrega logs para exibir histórico resumido
$stmtLogs = $pdo->prepare("
    SELECT rl.*, u.name AS usuario_nome
      FROM refund_logs rl
 LEFT JOIN users u ON u.id = rl.user_id
     WHERE rl.refund_id = :rid
  ORDER BY rl.created_at ASC
");
$stmtLogs->execute([':rid' => $id]);
$logs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);

// Helpers locais
function money_br($n){ return 'R$ ' . number_format((float)$n, 2, ',', '.'); }
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function dt_br($d){
    if (!$d) return '—';
    if ($d === '0000-00-00' || $d === '0000-00-00 00:00:00') return '—';
    return date('d/m/Y', strtotime($d));
}
function dt_br_h($d){
    if (!$d) return '—';
    if ($d === '0000-00-00 00:00:00') return '—';
    return date('d/m/Y H:i', strtotime($d));
}

$statuses = [
    'SOLICITADO'            => 'Solicitado',
    'EM_ANALISE'            => 'Em análise',
    'AGUARDANDO_FORNECEDOR' => 'Aguardando fornecedor',
    'APROVADO'              => 'Aprovado',
    'NEGADO'                => 'Negado',
    'REEMBOLSADO'           => 'Reembolsado',
];

$badgeClass = 'bg-blue';
if ($refund['status'] === 'REEMBOLSADO') {
    $badgeClass = 'bg-success';
} elseif ($refund['status'] === 'NEGADO') {
    $badgeClass = 'bg-danger';
}

// Dados de cabeçalho
$today = date('d/m/Y H:i');
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Estado do Reembolso #<?= (int)$refund['id'] ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Tabler CSS via CDN -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.4.0/dist/css/tabler.min.css">
  <style>
    body {
      background: #f3f4f6;
    }
    .a4-sheet {
      width: 210mm;
      min-height: 297mm;
      margin: 10mm auto;
      background: #ffffff;
      box-shadow: 0 0 0.5cm rgba(15, 23, 42, 0.2);
      padding: 16mm 18mm;
      box-sizing: border-box;
      position: relative;
    }
    .a4-header {
      border-bottom: 1px solid #e5e7eb;
      padding-bottom: 8px;
      margin-bottom: 16px;
    }
    .a4-footer {
      border-top: 1px solid #e5e7eb;
      padding-top: 4px;
      margin-top: 16px;
      font-size: 11px;
      color: #6b7280;
      display: flex;
      justify-content: space-between;
    }
    .print-actions {
      position: fixed;
      top: 10px;
      right: 10px;
      z-index: 50;
    }
    @media print {
      body {
        background: #ffffff;
      }
      .a4-sheet {
        margin: 0;
        box-shadow: none;
        width: 210mm;
        min-height: 297mm;
      }
      .print-actions {
        display: none !important;
      }
    }
  </style>
</head>
<body class="antialiased">

<!-- Botões de ação (não aparecem na impressão) -->
<div class="print-actions">
  <div class="btn-list">
    <a href="show.php?id=<?= (int)$refund['id'] ?>" class="btn btn-secondary btn-sm">
      Voltar
    </a>
    <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
      Imprimir
    </button>
  </div>
</div>

<div class="a4-sheet">
  <!-- Cabeçalho -->
  <div class="a4-header d-flex justify-content-between align-items-start">
    <div>
      <h2 class="mb-0">Relatório de Estado do Reembolso</h2>
      <div class="text-muted">
        Reembolso #<?= (int)$refund['id'] ?> ·
        <?= e($refund['cliente_nome'] ?? '-') ?>
      </div>
      <div class="mt-1">
        Emitido em: <?= e($today) ?>
      </div>
    </div>
    <div class="text-end">
      <!-- Se tiver logo em /assets, pode trocar aqui -->
      <div class="fw-bold">KAMALTUR</div>
      <div class="text-muted small">Gestão de Reembolsos</div>
      <div class="mt-2">
        <span class="badge <?= $badgeClass ?>">
          <?= e($statuses[$refund['status']] ?? $refund['status']) ?>
        </span>
      </div>
    </div>
  </div>

  <!-- Bloco Cliente / Venda -->
  <div class="row mb-3">
    <div class="col-6">
      <div class="card card-sm mb-2">
        <div class="card-body">
          <div class="subheader mb-1">Cliente</div>
          <div class="fw-bold"><?= e($refund['cliente_nome'] ?? '-') ?></div>
          <?php if (!empty($refund['cliente_documento'])): ?>
            <div class="text-muted small">Doc: <?= e($refund['cliente_documento']) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <div class="card card-sm">
        <div class="card-body">
          <div class="subheader mb-1">Fornecedor</div>
          <div class="fw-bold"><?= e($refund['fornecedor_nome'] ?? '-') ?></div>
        </div>
      </div>
    </div>
    <div class="col-6">
      <div class="card card-sm">
        <div class="card-body">
          <div class="subheader mb-1">Dados da venda e passageiro</div>
          <div><span class="text-muted">Venda:</span>
            <?php if (!empty($refund['invoice_id'])): ?>
              #<?= (int)$refund['invoice_id'] ?> – <?= e($refund['numero_fatura'] ?? '-') ?>
            <?php else: ?>
              —
            <?php endif; ?>
          </div>
          <div><span class="text-muted">Passageiro:</span>
            <?= e($refund['passageiro_nome'] ?? '-') ?>
          </div>
          <div><span class="text-muted">Tipo:</span>
            <?= e($refund['type']) ?>
          </div>
          <div><span class="text-muted">Data solicitação:</span>
            <?= dt_br($refund['data_solicitacao']) ?>
          </div>
          <div><span class="text-muted">Data pagamento cliente:</span>
            <?= dt_br($refund['data_pagamento']) ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Bloco financeiro -->
  <div class="card card-sm mb-3">
    <div class="card-header">
      <h3 class="card-title">Resumo financeiro</h3>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-6 col-md-3">
          <div class="subheader">Valor pago</div>
          <div class="fw-bold"><?= money_br($refund['valor_pago']) ?></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="subheader">Valor reembolsável</div>
          <div class="fw-bold"><?= money_br($refund['valor_reembolsavel']) ?></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="subheader">Valor recebido do fornecedor</div>
          <div class="fw-bold"><?= money_br($refund['valor_recebido']) ?></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="subheader">Situação</div>
          <div><?= e($statuses[$refund['status']] ?? $refund['status']) ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Motivo / descrição / observações -->
  <div class="row mb-3">
    <div class="col-12">
      <div class="card card-sm mb-2">
        <div class="card-header">
          <h3 class="card-title mb-0">Motivo do reembolso</h3>
        </div>
        <div class="card-body">
          <?= e($refund['motivo']) ?>
        </div>
      </div>

      <div class="card card-sm mb-2">
        <div class="card-header">
          <h3 class="card-title mb-0">Descrição detalhada</h3>
        </div>
        <div class="card-body">
          <?= nl2br(e($refund['descricao'] ?: '—')) ?>
        </div>
      </div>

      <div class="card card-sm">
        <div class="card-header">
          <h3 class="card-title mb-0">Observações internas</h3>
        </div>
        <div class="card-body">
          <?= nl2br(e($refund['observacoes'] ?: '—')) ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Histórico / timeline resumida -->
  <div class="card card-sm mb-3">
    <div class="card-header">
      <h3 class="card-title mb-0">Histórico de alterações</h3>
    </div>
    <div class="card-body">
      <?php if (empty($logs)): ?>
        <div class="text-muted">Nenhum evento registrado.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm">
            <thead>
              <tr>
                <th>Data/Hora</th>
                <th>Ação</th>
                <th>Status</th>
                <th>Valor reembolsável</th>
                <th>Usuário</th>
                <th>Nota</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
              <tr>
                <td><?= e(dt_br_h($log['created_at'])) ?></td>
                <td><?= e($log['acao']) ?></td>
                <td>
                  <?php if ($log['status_novo'] || $log['status_anterior']): ?>
                    <?= e($log['status_anterior'] ?: '-') ?> → <?= e($log['status_novo'] ?: '-') ?>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($log['valor_novo'] !== null): ?>
                    <?php
                      $ant = $log['valor_anterior'];
                      $novo = $log['valor_novo'];
                    ?>
                    <?= $ant !== null ? money_br($ant) . ' → ' : '' ?><?= money_br($novo) ?>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
                <td><?= e($log['usuario_nome'] ?? 'Sistema') ?></td>
                <td><?= nl2br(e($log['nota'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Comprovante -->
  <div class="card card-sm">
    <div class="card-header">
      <h3 class="card-title mb-0">Comprovante anexado</h3>
    </div>
    <div class="card-body">
      <?php if (!empty($refund['documento_comprovante'])): ?>
        <div>
          Arquivo: <?= e($refund['documento_comprovante']) ?><br>
          <span class="text-muted small">
            O arquivo pode ser acessado pelo sistema interno em:
            <code>/uploads/refunds/<?= e($refund['documento_comprovante']) ?></code>
          </span>
        </div>
      <?php else: ?>
        <div class="text-muted">Nenhum comprovante anexado.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Rodapé -->
  <div class="a4-footer">
    <div>KAMALTUR • Relatório de Reembolso</div>
    <div>Gerado em <?= e($today) ?></div>
  </div>
</div>

</body>
</html>
