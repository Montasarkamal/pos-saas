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

require_once '../inc/header.php';
?>
<div class="page-header d-print-none">
  <div class="container-xl">
    <div class="row g-2 align-items-center">
      <div class="col">
        <h2 class="page-title">
          Editar Reembolso #<?= (int)$refund['id'] ?> – <?= htmlspecialchars($refund['cliente_nome'] ?? '') ?>
        </h2>
        <div class="text-muted">
          Venda: <?= htmlspecialchars($refund['numero_fatura'] ?? '-') ?> · 
          Passageiro: <?= htmlspecialchars($refund['passageiro_nome'] ?? '-') ?>
        </div>
      </div>
      <div class="col-auto ms-auto d-print-none">
        <a href="index.php" class="btn btn-secondary">Voltar</a>
      </div>
    </div>
  </div>
</div>

<div class="page-body">
  <div class="container-xl">

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger">
        <ul class="mb-0">
          <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if ($refund['status'] === 'REEMBOLSADO' && !empty($refund['data_pagamento'])): ?>
      <div class="card mb-3">
        <div class="card-body d-flex justify-content-between align-items-center">
          <div>
            Reembolso concluído em
            <strong><?= htmlspecialchars(date('d/m/Y', strtotime($refund['data_pagamento']))) ?></strong>.
            Você pode gerar o recibo de reembolso.
          </div>
          <div class="btn-list">
            <a href="/refunds/receipt.php?id=<?= (int)$refund['id'] ?>" target="_blank" class="btn btn-outline-success">
              Gerar Recibo de Reembolso
            </a>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="row row-cards">
      <div class="col-md-7">
        <form class="card" method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
          <div class="card-header">
            <h3 class="card-title">Dados financeiros e status</h3>
          </div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                  <?php foreach ($statuses as $key => $label): ?>
                    <option value="<?= htmlspecialchars($key) ?>" <?= $refund['status'] === $key ? 'selected' : '' ?>>
                      <?= htmlspecialchars($label) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="col-md-4">
                <label class="form-label">Valor pago (R$)</label>
                <input type="text" class="form-control"
                       value="<?= number_format($refund['valor_pago'], 2, ',', '.') ?>" disabled>
              </div>

              <div class="col-md-4">
                <label class="form-label">Valor reembolsável (R$)</label>
                <input type="text" name="valor_reembolsavel" class="form-control"
                       value="<?= number_format($refund['valor_reembolsavel'], 2, ',', '.') ?>">
              </div>

              <div class="col-md-4">
                <label class="form-label">Valor recebido do fornecedor (R$)</label>
                <input type="text" name="valor_recebido" class="form-control"
                       value="<?= number_format($refund['valor_recebido'], 2, ',', '.') ?>">
              </div>

              <div class="col-md-4">
                <label class="form-label">Data de pagamento ao cliente</label>
                <input type="date" name="data_pagamento"
                       value="<?= $refund['data_pagamento'] ? htmlspecialchars($refund['data_pagamento']) : '' ?>"
                       class="form-control">
              </div>

              <div class="col-md-4">
                <label class="form-label">Comprovante (substituir)</label>
                <input type="file" name="comprovante" class="form-control">
                <?php if ($refund['documento_comprovante']): ?>
                  <small class="form-hint">
                    Atual: <?= htmlspecialchars($refund['documento_comprovante']) ?>
                  </small>
                <?php endif; ?>
              </div>

              <div class="col-12">
                <label class="form-label">Observações internas</label>
                <textarea name="observacoes" class="form-control" rows="3"><?= htmlspecialchars($refund['observacoes'] ?? '') ?></textarea>
              </div>

              <div class="col-12">
                <label class="form-label">Nota do log desta atualização</label>
                <textarea name="nota_log" class="form-control" rows="2"
                          placeholder="Ex.: Fornecedor confirmou reembolso parcial."></textarea>
              </div>
            </div>
          </div>
          <div class="card-footer text-end">
            <button type="submit" class="btn btn-primary">Salvar alterações</button>
          </div>
        </form>

        <div class="card mt-3">
          <div class="card-header">
            <h3 class="card-title">Resumo</h3>
          </div>
          <div class="card-body">
            <dl class="row">
              <dt class="col-sm-3">Tipo</dt>
              <dd class="col-sm-9"><?= htmlspecialchars($refund['type']) ?></dd>

              <dt class="col-sm-3">Motivo</dt>
              <dd class="col-sm-9"><?= htmlspecialchars($refund['motivo']) ?></dd>

              <dt class="col-sm-3">Descrição</dt>
              <dd class="col-sm-9"><?= nl2br(htmlspecialchars($refund['descricao'] ?? '-')) ?></dd>

              <dt class="col-sm-3">Cliente</dt>
              <dd class="col-sm-9"><?= htmlspecialchars($refund['cliente_nome'] ?? '-') ?></dd>

              <dt class="col-sm-3">Fornecedor</dt>
              <dd class="col-sm-9"><?= htmlspecialchars($refund['fornecedor_nome'] ?? '-') ?></dd>

              <dt class="col-sm-3">Data solicitação</dt>
              <dd class="col-sm-9">
                <?= htmlspecialchars(date('d/m/Y', strtotime($refund['data_solicitacao']))) ?>
              </dd>
            </dl>
          </div>
        </div>
      </div>

      <!-- TIMELINE / RASTREIO -->
      <div class="col-md-5">
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Linha do tempo do reembolso</h3>
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
                        <strong><?= htmlspecialchars($log['acao']) ?></strong>
                        <span class="text-muted">
                          <?= htmlspecialchars(date('d/m/Y H:i', strtotime($log['created_at']))) ?>
                        </span>
                      </div>

                      <?php if ($log['status_novo'] || $log['status_anterior']): ?>
                        <div class="text-muted">
                          Status:
                          <?= htmlspecialchars($log['status_anterior'] ?: '-') ?>
                          →
                          <?= htmlspecialchars($log['status_novo'] ?: '-') ?>
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
                        <div><?= nl2br(htmlspecialchars($log['nota'])) ?></div>
                      <?php endif; ?>

                      <div class="small text-muted mt-1">
                        Usuário: <?= htmlspecialchars($log['usuario_nome'] ?? 'Sistema') ?>
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
