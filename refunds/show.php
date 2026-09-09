<?php
// refunds/show.php — Visualização somente leitura de um reembolso

require_once '../inc/db.php';
require_once '../inc/auth.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

// Carrega reembolso + relações
$stmt = $pdo->prepare("
    SELECT r.*, 
           c.name AS cliente_nome,
           s.name AS fornecedor_nome,
           i.invoice_number AS numero_fatura,
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
    die('Reembolso não encontrado.');
}

// Carrega logs para timeline
$stmtLogs = $pdo->prepare("
    SELECT rl.*, u.name AS usuario_nome
      FROM refund_logs rl
 LEFT JOIN users u ON u.id = rl.user_id
     WHERE rl.refund_id = :rid
  ORDER BY rl.created_at DESC
");
$stmtLogs->execute([':rid' => $id]);
$logs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);

// Helpers simples
function money_br($n){ return 'R$ ' . number_format((float)$n, 2, ',', '.'); }
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$statuses = [
    'SOLICITADO'            => 'Solicitado',
    'EM_ANALISE'            => 'Em análise',
    'AGUARDANDO_FORNECEDOR' => 'Aguardando fornecedor',
    'APROVADO'              => 'Aprovado',
    'NEGADO'                => 'Negado',
    'REEMBOLSADO'           => 'Reembolsado',
];

// CSRF (se existir no sistema)
$token = function_exists('csrf_token') ? csrf_token() : '';

require_once '../inc/header.php';
?>
<div class="page-header d-print-none">
  <div class="container-xl">
    <div class="row g-2 align-items-center">
      <div class="col">
        <h2 class="page-title">
          Reembolso #<?= (int)$refund['id'] ?> – <?= e($refund['cliente_nome'] ?? '') ?>
        </h2>
        <div class="text-muted">
          Venda: <?= e($refund['numero_fatura'] ?? '-') ?> ·
          Passageiro: <?= e($refund['passageiro_nome'] ?? '-') ?>
        </div>
      </div>
      <div class="col-auto ms-auto d-print-none">
        <div class="btn-list">
          <a href="index.php" class="btn btn-secondary btn-sm">Voltar</a>

          <a href="edit.php?id=<?= (int)$refund['id'] ?>" class="btn btn-primary btn-sm">
            Editar
          </a>


<a href="state.php?id=<?= (int)$refund['id'] ?>" 
   target="_blank"
   class="btn btn-outline-info btn-sm">
  <i class="ti ti-report-money me-1"></i>
  Estado Atual
</a>

          <!-- Botão Recibo (quando houver pagamento ou status REEMBOLSADO) -->
          <?php if (!empty($refund['data_pagamento']) || $refund['status'] === 'REEMBOLSADO'): ?>
            <a href="receipt.php?id=<?= (int)$refund['id'] ?>" target="_blank"
               class="btn btn-outline-success btn-sm">
              <i class="ti ti-file-dollar me-1"></i>
              Recibo
            </a>
          <?php endif; ?>

          <!-- Botão Excluir -->
          <form action="delete.php" method="post" class="d-inline"
                onsubmit="return confirm('Tem certeza que deseja excluir este reembolso? Esta ação não pode ser desfeita.');">
            <?php if ($token): ?>
              <input type="hidden" name="csrf" value="<?= e($token) ?>">
            <?php endif; ?>
            <input type="hidden" name="id" value="<?= (int)$refund['id'] ?>">
            <button class="btn btn-outline-red btn-sm">
              <i class="ti ti-trash me-1"></i>
              Excluir
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="page-body">
  <div class="container-xl">
    <div class="row row-cards">
      <div class="col-md-7">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Dados do reembolso</h3>
          </div>
          <div class="card-body">
            <dl class="row">
              <dt class="col-sm-3">Status</dt>
              <dd class="col-sm-9">
                <?php
                  $badgeClass = 'bg-OF';
                  if ($refund['status'] === 'REEMBOLSADO') $badgeClass = 'bg-green';
                  elseif ($refund['status'] === 'NEGADO') $badgeClass = 'bg-red';
                ?>
                <span class="badge <?= $badgeClass ?>">
                  <?= e($statuses[$refund['status']] ?? $refund['status']) ?>
                </span>
              </dd>

              <dt class="col-sm-3">Tipo</dt>
              <dd class="col-sm-9"><?= e($refund['type']) ?></dd>

              <dt class="col-sm-3">Cliente</dt>
              <dd class="col-sm-9"><?= e($refund['cliente_nome'] ?? '-') ?></dd>

              <dt class="col-sm-3">Fornecedor</dt>
              <dd class="col-sm-9"><?= e($refund['fornecedor_nome'] ?? '-') ?></dd>

              <dt class="col-sm-3">Passageiro</dt>
              <dd class="col-sm-9"><?= e($refund['passageiro_nome'] ?? '-') ?></dd>

              <dt class="col-sm-3">Venda</dt>
              <dd class="col-sm-9">
                <?php if (!empty($refund['invoice_id'])): ?>
                  <a href="/sales/show.php?id=<?= (int)$refund['invoice_id'] ?>">
                    #<?= (int)$refund['invoice_id'] ?> – <?= e($refund['numero_fatura'] ?? '-') ?>
                  </a>
                <?php else: ?>
                  —
                <?php endif; ?>
              </dd>

              <dt class="col-sm-3">Motivo</dt>
              <dd class="col-sm-9"><?= e($refund['motivo']) ?></dd>

              <dt class="col-sm-3">Descrição</dt>
              <dd class="col-sm-9"><?= nl2br(e($refund['descricao'] ?? '-')) ?></dd>

              <dt class="col-sm-3">Valor pago</dt>
              <dd class="col-sm-9"><?= money_br($refund['valor_pago']) ?></dd>

              <dt class="col-sm-3">Valor reembolsável</dt>
              <dd class="col-sm-9"><?= money_br($refund['valor_reembolsavel']) ?></dd>

              <dt class="col-sm-3">Valor recebido do fornecedor</dt>
              <dd class="col-sm-9"><?= money_br($refund['valor_recebido']) ?></dd>

              <dt class="col-sm-3">Data solicitação</dt>
              <dd class="col-sm-9">
                <?= $refund['data_solicitacao']
                      ? date('d/m/Y', strtotime($refund['data_solicitacao']))
                      : '—' ?>
              </dd>

              <dt class="col-sm-3">Data pagamento cliente</dt>
              <dd class="col-sm-9">
                <?= $refund['data_pagamento']
                      ? date('d/m/Y', strtotime($refund['data_pagamento']))
                      : '—' ?>
              </dd>

              <dt class="col-sm-3">Observações internas</dt>
              <dd class="col-sm-9"><?= nl2br(e($refund['observacoes'] ?? '-')) ?></dd>

              <dt class="col-sm-3">Comprovante</dt>
              <dd class="col-sm-9">
                <?php if (!empty($refund['documento_comprovante'])): ?>
                  <a href="../uploads/refunds/<?= e($refund['documento_comprovante']) ?>" target="_blank">
                    Ver comprovante
                  </a>
                <?php else: ?>
                  —
                <?php endif; ?>
              </dd>
            </dl>
          </div>
        </div>
      </div>

      <!-- Timeline -->
      <div class="col-md-5">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Linha do tempo</h3>
          </div>
          <div class="card-body">
            <?php if (empty($logs)): ?>
              <div class="text-muted">Nenhum evento registrado ainda.</div>
            <?php else: ?>
              <div class="timeline">
                <?php foreach ($logs as $log): ?>
                  <div class="timeline-item">
                    <div class="timeline-item-marker"></div>
                    <div class="timeline-item-content">
                      <div class="d-flex justify-content-between">
                        <strong><?= e($log['acao']) ?></strong>
                        <span class="text-muted">
                          <?= e(date('d/m/Y H:i', strtotime($log['created_at']))) ?>
                        </span>
                      </div>

                      <?php if ($log['status_novo'] || $log['status_anterior']): ?>
                        <div class="text-muted">
                          Status:
                          <?= e($log['status_anterior'] ?: '-') ?> →
                          <?= e($log['status_novo'] ?: '-') ?>
                        </div>
                      <?php endif; ?>

                      <?php if ($log['valor_novo'] !== null): ?>
                        <div class="text-muted">
                          Valor reembolsável:
                          <?= $log['valor_anterior'] !== null
                                ? 'R$ ' . number_format($log['valor_anterior'], 2, ',', '.') . ' → '
                                : '' ?>
                          R$ <?= number_format($log['valor_novo'], 2, ',', '.') ?>
                        </div>
                      <?php endif; ?>

                      <?php if ($log['nota']): ?>
                        <div><?= nl2br(e($log['nota'])) ?></div>
                      <?php endif; ?>

                      <div class="small text-muted mt-1">
                        Usuário: <?= e($log['usuario_nome'] ?? 'Sistema') ?>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<?php require_once '../inc/footer.php'; ?>
