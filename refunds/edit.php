<?php
// refunds/edit.php

require_once '../inc/db.php';
require_once '../inc/auth.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

$errors  = [];
$success = false;

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

$statuses = [
    'SOLICITADO'            => 'Solicitado',
    'EM_ANALISE'            => 'Em análise',
    'AGUARDANDO_FORNECEDOR' => 'Aguardando fornecedor',
    'APROVADO'              => 'Aprovado',
    'NEGADO'                => 'Negado',
    'REEMBOLSADO'           => 'Reembolsado',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $errors[] = 'Sessão expirada. Atualize e tente novamente.';
    }

    $status_novo        = $_POST['status'] ?? $refund['status'];
    $valor_reembolsavel = str_replace(['.', ','], ['', '.'], $_POST['valor_reembolsavel'] ?? $refund['valor_reembolsavel']);
    $valor_recebido     = str_replace(['.', ','], ['', '.'], $_POST['valor_recebido'] ?? $refund['valor_recebido']);
    $data_pagamento     = $_POST['data_pagamento'] ?: null;
    $observacoes        = trim($_POST['observacoes'] ?? '');
    $nota_log           = trim($_POST['nota_log'] ?? '');

    if (!isset($statuses[$status_novo])) {
        $errors[] = 'Status inválido.';
    }
    if ($valor_reembolsavel < 0) {
        $errors[] = 'Valor reembolsável não pode ser negativo.';
    }
    if ($valor_recebido < 0) {
        $errors[] = 'Valor recebido não pode ser negativo.';
    }

    // Upload adicional de comprovante
    $documento_comprovante = $refund['documento_comprovante'];
    if (!empty($_FILES['comprovante']['name'])) {
        $uploadDir = '../uploads/refunds/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }
        $ext      = pathinfo($_FILES['comprovante']['name'], PATHINFO_EXTENSION);
        $fileName = 'refund_' . $id . '_' . time() . '.' . $ext;
        $destPath = $uploadDir . $fileName;
        if (move_uploaded_file($_FILES['comprovante']['tmp_name'], $destPath)) {
            $documento_comprovante = $fileName;
        } else {
            $errors[] = 'Falha ao fazer upload do comprovante.';
        }
    }

    if (empty($errors)) {
        $pdo->beginTransaction();
        try {
            // Atualiza reembolso
            $stmtUp = $pdo->prepare("
                UPDATE refunds
                   SET status               = :status,
                       valor_reembolsavel   = :valor_reembolsavel,
                       valor_recebido       = :valor_recebido,
                       data_pagamento       = :data_pagamento,
                       observacoes          = :observacoes,
                       documento_comprovante= :documento_comprovante
                 WHERE id = :id AND agency_id = :agency_id
            ");
            $stmtUp->execute([
                ':status'               => $status_novo,
                ':valor_reembolsavel'   => $valor_reembolsavel,
                ':valor_recebido'       => $valor_recebido,
                ':data_pagamento'       => $data_pagamento,
                ':observacoes'          => $observacoes ?: null,
                ':documento_comprovante'=> $documento_comprovante,
                ':id'                   => $id,
                ':agency_id'            => agency_id(),
            ]);

            $userId = function_exists('getCurrentUserId') ? getCurrentUserId() : null;

            // Define tipo de log
            $acao = 'ATUALIZACAO';
            if ($refund['status'] !== $status_novo) {
                $acao = 'STATUS';
            }

            // Registra log
            $stmtLog = $pdo->prepare("
                INSERT INTO refund_logs (
                    refund_id, user_id, acao,
                    status_anterior, status_novo,
                    valor_anterior, valor_novo,
                    nota, agency_id
                ) VALUES (
                    :refund_id, :user_id, :acao,
                    :status_anterior, :status_novo,
                    :valor_anterior, :valor_novo,
                    :nota, :agency_id
                )
            ");
            $stmtLog->execute([
                ':refund_id'       => $id,
                ':user_id'         => $userId,
                ':acao'            => $acao,
                ':status_anterior' => $refund['status'],
                ':status_novo'     => $status_novo,
                ':valor_anterior'  => $refund['valor_reembolsavel'],
                ':valor_novo'      => $valor_reembolsavel,
                ':nota'            => $nota_log ?: 'Atualização do reembolso.',
                ':agency_id'       => agency_id(),
            ]);

            $pdo->commit();
            $success = true;

            // بعد الحفظ يذهب لصفحة العرض
            header("Location: show.php?id=" . $id);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('refunds/edit.php: ' . $e->getMessage());
            $errors[] = 'Erro ao atualizar.';
        }
    }
}

// Recarrega dados atualizados do reembolso
$stmt->execute([':id' => $id, ':agency_id' => agency_id()]);
$refund = $stmt->fetch(PDO::FETCH_ASSOC);

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

ob_start();
?>
<div class="mx-auto max-w-6xl">

  <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
      <p class="text-xs font-bold uppercase tracking-wider text-brand-600">Reembolsos</p>
      <h2 class="text-xl font-bold text-ink-950">Editar Reembolso #<?= (int)$refund['id'] ?> – <?= htmlspecialchars($refund['cliente_nome'] ?? '') ?></h2>
      <p class="mt-1 text-sm text-ink-500">
        Venda: <?= htmlspecialchars($refund['numero_fatura'] ?? '-') ?> ·
        Passageiro: <?= htmlspecialchars($refund['passageiro_nome'] ?? '-') ?>
      </p>
    </div>
    <a href="index.php" class="btn-ghost">
      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"></path><path d="M12 19l-7-7 7-7"></path></svg>
      Voltar
    </a>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-4 text-sm text-red-800" role="alert">
      <ul class="list-disc space-y-1 pl-4">
        <?php foreach ($errors as $e): ?>
          <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($refund['status'] === 'REEMBOLSADO' && !empty($refund['data_pagamento'])): ?>
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-5 py-4">
      <p class="text-sm text-emerald-800">
        Reembolso concluído em
        <strong><?= htmlspecialchars(date('d/m/Y', strtotime($refund['data_pagamento']))) ?></strong>.
        Você pode gerar o recibo de reembolso.
      </p>
      <a href="/refunds/receipt.php?id=<?= (int)$refund['id'] ?>" target="_blank" class="btn-soft border-emerald-200 bg-emerald-100 text-emerald-800 hover:bg-emerald-200 hover:text-emerald-900">
        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
        Gerar Recibo de Reembolso
      </a>
    </div>
  <?php endif; ?>

  <div class="grid gap-5 lg:grid-cols-12">

    <div class="space-y-5 lg:col-span-7">
      <form class="card overflow-hidden" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <div class="border-b border-ink-100 px-5 py-4">
          <h3 class="text-sm font-bold text-ink-950">Dados financeiros e status</h3>
        </div>
        <div class="p-5">
          <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div>
              <label class="label-field" for="status">Status</label>
              <select name="status" id="status" class="select-field">
                <?php foreach ($statuses as $key => $label): ?>
                  <option value="<?= htmlspecialchars($key) ?>" <?= $refund['status'] === $key ? 'selected' : '' ?>>
                    <?= htmlspecialchars($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="label-field" for="valor_pago_d">Valor pago (R$)</label>
              <input type="text" id="valor_pago_d" class="input-field cursor-not-allowed bg-ink-50 text-ink-500"
                     value="<?= number_format($refund['valor_pago'], 2, ',', '.') ?>" disabled>
            </div>
            <div>
              <label class="label-field" for="valor_reembolsavel">Valor reembolsável (R$)</label>
              <input type="text" name="valor_reembolsavel" id="valor_reembolsavel" class="input-field"
                     value="<?= number_format($refund['valor_reembolsavel'], 2, ',', '.') ?>">
            </div>
            <div>
              <label class="label-field" for="valor_recebido">Valor recebido do fornecedor (R$)</label>
              <input type="text" name="valor_recebido" id="valor_recebido" class="input-field"
                     value="<?= number_format($refund['valor_recebido'], 2, ',', '.') ?>">
            </div>
            <div>
              <label class="label-field" for="data_pagamento">Data de pagamento ao cliente</label>
              <input type="date" name="data_pagamento" id="data_pagamento"
                     value="<?= $refund['data_pagamento'] ? htmlspecialchars($refund['data_pagamento']) : '' ?>"
                     class="input-field">
            </div>
            <div>
              <label class="label-field" for="comprovante">Comprovante (substituir)</label>
              <input type="file" name="comprovante" id="comprovante" class="input-field file:mr-3 file:rounded-lg file:border-0 file:bg-ink-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-ink-700">
              <?php if ($refund['documento_comprovante']): ?>
                <p class="mt-1.5 truncate text-xs text-ink-500" title="<?= htmlspecialchars($refund['documento_comprovante']) ?>">
                  Atual: <?= htmlspecialchars($refund['documento_comprovante']) ?>
                </p>
              <?php endif; ?>
            </div>
            <div class="sm:col-span-2 lg:col-span-3">
              <label class="label-field" for="observacoes">Observações internas</label>
              <textarea name="observacoes" id="observacoes" class="input-field" rows="3"><?= htmlspecialchars($refund['observacoes'] ?? '') ?></textarea>
            </div>
            <div class="sm:col-span-2 lg:col-span-3">
              <label class="label-field" for="nota_log">Nota do log desta atualização</label>
              <textarea name="nota_log" id="nota_log" class="input-field" rows="2"
                        placeholder="Ex.: Fornecedor confirmou reembolso parcial."></textarea>
            </div>
          </div>
        </div>
        <div class="flex items-center justify-end gap-3 border-t border-ink-100 bg-ink-50/50 px-5 py-4">
          <button type="submit" class="btn-primary">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
            Salvar alterações
          </button>
        </div>
      </form>

      <div class="card overflow-hidden">
        <div class="border-b border-ink-100 px-5 py-4">
          <h3 class="text-sm font-bold text-ink-950">Resumo</h3>
        </div>
        <div class="p-5">
          <dl class="divide-y divide-ink-100 text-sm">
            <div class="grid grid-cols-3 gap-3 py-2.5">
              <dt class="font-medium text-ink-500">Tipo</dt>
              <dd class="col-span-2 text-ink-900"><?= htmlspecialchars($refund['type']) ?></dd>
            </div>
            <div class="grid grid-cols-3 gap-3 py-2.5">
              <dt class="font-medium text-ink-500">Motivo</dt>
              <dd class="col-span-2 text-ink-900"><?= htmlspecialchars($refund['motivo']) ?></dd>
            </div>
            <div class="grid grid-cols-3 gap-3 py-2.5">
              <dt class="font-medium text-ink-500">Descrição</dt>
              <dd class="col-span-2 text-ink-900"><?= nl2br(htmlspecialchars($refund['descricao'] ?? '-')) ?></dd>
            </div>
            <div class="grid grid-cols-3 gap-3 py-2.5">
              <dt class="font-medium text-ink-500">Cliente</dt>
              <dd class="col-span-2 text-ink-900"><?= htmlspecialchars($refund['cliente_nome'] ?? '-') ?></dd>
            </div>
            <div class="grid grid-cols-3 gap-3 py-2.5">
              <dt class="font-medium text-ink-500">Fornecedor</dt>
              <dd class="col-span-2 text-ink-900"><?= htmlspecialchars($refund['fornecedor_nome'] ?? '-') ?></dd>
            </div>
            <div class="grid grid-cols-3 gap-3 py-2.5">
              <dt class="font-medium text-ink-500">Data solicitação</dt>
              <dd class="col-span-2 text-ink-900"><?= htmlspecialchars(date('d/m/Y', strtotime($refund['data_solicitacao']))) ?></dd>
            </div>
          </dl>
        </div>
      </div>
    </div>

    <div class="lg:col-span-5">
      <div class="card overflow-hidden">
        <div class="border-b border-ink-100 px-5 py-4">
          <h3 class="text-sm font-bold text-ink-950">Linha do tempo do reembolso</h3>
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
                    <strong class="text-sm text-ink-950"><?= htmlspecialchars($log['acao']) ?></strong>
                    <span class="shrink-0 text-xs text-ink-400"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($log['created_at']))) ?></span>
                  </div>
                  <?php if ($log['status_novo'] || $log['status_anterior']): ?>
                    <p class="mt-0.5 text-xs text-ink-500">
                      Status: <span class="font-medium"><?= htmlspecialchars($log['status_anterior'] ?: '-') ?></span> →
                      <span class="font-medium"><?= htmlspecialchars($log['status_novo'] ?: '-') ?></span>
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
                    <p class="mt-0.5 text-sm text-ink-700"><?= nl2br(htmlspecialchars($log['nota'])) ?></p>
                  <?php endif; ?>
                  <p class="mt-0.5 text-xs text-ink-400">Usuário: <?= htmlspecialchars($log['usuario_nome'] ?? 'Sistema') ?></p>
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
