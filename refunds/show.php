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

ob_start();
$refundStatusBadge = match ($refund['status']) {
    'REEMBOLSADO'            => 'bg-emerald-100 text-emerald-700',
    'NEGADO'                 => 'bg-red-100 text-red-700',
    'APROVADO'               => 'bg-teal-100 text-teal-700',
    'AGUARDANDO_FORNECEDOR'  => 'bg-amber-100 text-amber-700',
    'EM_ANALISE'             => 'bg-blue-100 text-blue-700',
    default                  => 'bg-ink-100 text-ink-600',
};
?>
<div class="mx-auto max-w-6xl">

  <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
      <p class="text-xs font-bold uppercase tracking-wider text-brand-600">Reembolsos</p>
      <h2 class="text-xl font-bold text-ink-950">Reembolso #<?= (int)$refund['id'] ?> – <?= e($refund['cliente_nome'] ?? '') ?></h2>
      <p class="mt-1 text-sm text-ink-500">
        Venda: <?= e($refund['numero_fatura'] ?? '-') ?> ·
        Passageiro: <?= e($refund['passageiro_nome'] ?? '-') ?>
      </p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
      <a href="index.php" class="btn-ghost">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"></path><path d="M12 19l-7-7 7-7"></path></svg>
        Voltar
      </a>
      <a href="edit.php?id=<?= (int)$refund['id'] ?>" class="btn-soft">
        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"></path></svg>
        Editar
      </a>
      <a href="state.php?id=<?= (int)$refund['id'] ?>" target="_blank" class="btn-soft">
        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><path d="M12 18v-6"></path><path d="M9 15h6"></path></svg>
        Estado Atual
      </a>
      <?php if (!empty($refund['data_pagamento']) || $refund['status'] === 'REEMBOLSADO'): ?>
        <a href="receipt.php?id=<?= (int)$refund['id'] ?>" target="_blank" class="btn-soft">
          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
          Recibo
        </a>
      <?php endif; ?>
      <form action="delete.php" method="post" class="inline"
            onsubmit="return confirm('Tem certeza que deseja excluir este reembolso? Esta ação não pode ser desfeita.');">
        <?php if ($token): ?>
          <input type="hidden" name="csrf" value="<?= e($token) ?>">
        <?php endif; ?>
        <input type="hidden" name="id" value="<?= (int)$refund['id'] ?>">
        <button class="btn-soft border-red-200 text-red-700 hover:bg-red-50 hover:text-red-800">
          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
          Excluir
        </button>
      </form>
    </div>
  </div>

  <div class="grid gap-5 lg:grid-cols-12">

    <div class="lg:col-span-7">
      <div class="card overflow-hidden">
        <div class="border-b border-ink-100 px-5 py-4">
          <h3 class="text-sm font-bold text-ink-950">Dados do reembolso</h3>
        </div>
        <div class="p-5">
          <dl class="divide-y divide-ink-100 text-sm">
            <div class="grid grid-cols-3 gap-3 py-2.5">
              <dt class="font-medium text-ink-500">Status</dt>
              <dd class="col-span-2">
                <span class="badge-soft <?= $refundStatusBadge ?>">
                  <?= e($statuses[$refund['status']] ?? $refund['status']) ?>
                </span>
              </dd>
            </div>
            <div class="grid grid-cols-3 gap-3 py-2.5">
              <dt class="font-medium text-ink-500">Tipo</dt>
              <dd class="col-span-2 text-ink-900"><?= e($refund['type']) ?></dd>
            </div>
            <div class="grid grid-cols-3 gap-3 py-2.5">
              <dt class="font-medium text-ink-500">Cliente</dt>
              <dd class="col-span-2 text-ink-900"><?= e($refund['cliente_nome'] ?? '-') ?></dd>
            </div>
            <div class="grid grid-cols-3 gap-3 py-2.5">
              <dt class="font-medium text-ink-500">Fornecedor</dt>
              <dd class="col-span-2 text-ink-900"><?= e($refund['fornecedor_nome'] ?? '-') ?></dd>
            </div>
            <div class="grid grid-cols-3 gap-3 py-2.5">
              <dt class="font-medium text-ink-500">Passageiro</dt>
              <dd class="col-span-2 text-ink-900"><?= e($refund['passageiro_nome'] ?? '-') ?></dd>
            </div>
            <div class="grid grid-cols-3 gap-3 py-2.5">
              <dt class="font-medium text-ink-500">Venda</dt>
              <dd class="col-span-2 text-ink-900">
                <?php if (!empty($refund['invoice_id'])): ?>
                  <a href="/sales/show.php?id=<?= (int)$refund['invoice_id'] ?>" class="font-medium text-brand-600 hover:text-brand-700 hover:underline">
                    #<?= (int)$refund['invoice_id'] ?> – <?= e($refund['numero_fatura'] ?? '-') ?>
                  </a>
                <?php else: ?>
                  —
                <?php endif; ?>
              </dd>
            </div>
            <div class="grid grid-cols-3 gap-3 py-2.5">
              <dt class="font-medium text-ink-500">Motivo</dt>
              <dd class="col-span-2 text-ink-900"><?= e($refund['motivo']) ?></dd>
            </div>
            <div class="grid grid-cols-3 gap-3 py-2.5">
              <dt class="font-medium text-ink-500">Descrição</dt>
              <dd class="col-span-2 text-ink-900"><?= nl2br(e($refund['descricao'] ?? '-')) ?></dd>
            </div>
            <div class="grid grid-cols-3 gap-3 py-2.5">
              <dt class="font-medium text-ink-500">Valor pago</dt>
              <dd class="col-span-2 text-ink-900"><?= money_br($refund['valor_pago']) ?></dd>
            </div>
            <div class="grid grid-cols-3 gap-3 py-2.5">
              <dt class="font-medium text-ink-500">Valor reembolsável</dt>
              <dd class="col-span-2 text-ink-900"><?= money_br($refund['valor_reembolsavel']) ?></dd>
            </div>
            <div class="grid grid-cols-3 gap-3 py-2.5">
              <dt class="font-medium text-ink-500">Valor recebido do fornecedor</dt>
              <dd class="col-span-2 text-ink-900"><?= money_br($refund['valor_recebido']) ?></dd>
            </div>
            <div class="grid grid-cols-3 gap-3 py-2.5">
              <dt class="font-medium text-ink-500">Data solicitação</dt>
              <dd class="col-span-2 text-ink-900"><?= $refund['data_solicitacao'] ? date('d/m/Y', strtotime($refund['data_solicitacao'])) : '—' ?></dd>
            </div>
            <div class="grid grid-cols-3 gap-3 py-2.5">
              <dt class="font-medium text-ink-500">Data pagamento cliente</dt>
              <dd class="col-span-2 text-ink-900"><?= $refund['data_pagamento'] ? date('d/m/Y', strtotime($refund['data_pagamento'])) : '—' ?></dd>
            </div>
            <div class="grid grid-cols-3 gap-3 py-2.5">
              <dt class="font-medium text-ink-500">Observações internas</dt>
              <dd class="col-span-2 text-ink-900"><?= nl2br(e($refund['observacoes'] ?? '-')) ?></dd>
            </div>
            <div class="grid grid-cols-3 gap-3 py-2.5">
              <dt class="font-medium text-ink-500">Comprovante</dt>
              <dd class="col-span-2 text-ink-900">
                <?php if (!empty($refund['documento_comprovante'])): ?>
                  <a href="../uploads/refunds/<?= e($refund['documento_comprovante']) ?>" target="_blank" class="font-medium text-brand-600 hover:text-brand-700 hover:underline">
                    Ver comprovante
                  </a>
                <?php else: ?>
                  —
                <?php endif; ?>
              </dd>
            </div>
          </dl>
        </div>
      </div>
    </div>

    <div class="lg:col-span-5">
      <div class="card overflow-hidden">
        <div class="border-b border-ink-100 px-5 py-4">
          <h3 class="text-sm font-bold text-ink-950">Linha do tempo</h3>
        </div>
        <div class="p-5">
          <?php if (empty($logs)): ?>
            <p class="text-sm text-ink-500">Nenhum evento registrado ainda.</p>
          <?php else: ?>
            <ol class="relative space-y-5 border-l border-ink-200 pl-5">
              <?php foreach ($logs as $log): ?>
                <li class="relative">
                  <span class="absolute -left-[26.5px] top-1.5 h-2.5 w-2.5 rounded-full border-2 border-white bg-brand-500 ring-1 ring-brand-200" aria-hidden="true"></span>
                  <div class="flex items-center justify-between gap-2">
                    <strong class="text-sm text-ink-950"><?= e($log['acao']) ?></strong>
                    <span class="shrink-0 text-xs text-ink-400"><?= e(date('d/m/Y H:i', strtotime($log['created_at']))) ?></span>
                  </div>
                  <?php if ($log['status_novo'] || $log['status_anterior']): ?>
                    <p class="mt-0.5 text-xs text-ink-500">
                      Status: <span class="font-medium"><?= e($log['status_anterior'] ?: '-') ?></span> →
                      <span class="font-medium"><?= e($log['status_novo'] ?: '-') ?></span>
                    </p>
                  <?php endif; ?>
                  <?php if ($log['valor_novo'] !== null): ?>
                    <p class="mt-0.5 text-xs text-ink-500">
                      Valor reembolsável:
                      <?= $log['valor_anterior'] !== null ? 'R$ ' . number_format($log['valor_anterior'], 2, ',', '.') . ' → ' : '' ?>
                      R$ <?= number_format($log['valor_novo'], 2, ',', '.') ?>
                    </p>
                  <?php endif; ?>
                  <?php if ($log['nota']): ?>
                    <p class="mt-0.5 text-sm text-ink-700"><?= nl2br(e($log['nota'])) ?></p>
                  <?php endif; ?>
                  <p class="mt-0.5 text-xs text-ink-400">Usuário: <?= e($log['usuario_nome'] ?? 'Sistema') ?></p>
                </li>
              <?php endforeach; ?>
            </ol>
          <?php endif; ?>
        </div>
      </div>
    </div>

  </div>
</div>
<?php
$body = ob_get_clean();
require __DIR__ . '/../inc/layout.php';
