<?php
// refunds/create.php

require_once '../inc/db.php';
require_once '../inc/auth.php';
require_login();

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $errors[] = 'Sessão expirada. Atualize e tente novamente.';
    }

    $client_id         = $_POST['client_id'] ?? null;
    $invoice_id        = $_POST['invoice_id'] ?: null;
    $passenger_id      = $_POST['passenger_id'] ?: null;
    $supplier_id       = $_POST['supplier_id'] ?: null;
    $type              = $_POST['type'] ?? null;
    $motivo            = trim($_POST['motivo'] ?? '');
    $descricao         = trim($_POST['descricao'] ?? '');
    $valor_pago        = str_replace(['.', ','], ['', '.'], $_POST['valor_pago'] ?? '0');
    $valor_reembolsavel= str_replace(['.', ','], ['', '.'], $_POST['valor_reembolsavel'] ?? '0');
    $observacoes       = trim($_POST['observacoes'] ?? '');
    $data_solicitacao  = $_POST['data_solicitacao'] ?? date('Y-m-d');

    if (!$client_id)          $errors[] = 'Cliente é obrigatório.';
    if (!$type)               $errors[] = 'Tipo é obrigatório.';
    if ($motivo === '')       $errors[] = 'Motivo é obrigatório.';
    if ($valor_pago <= 0)     $errors[] = 'Valor pago deve ser maior que zero.';
    if ($valor_reembolsavel < 0) $errors[] = 'Valor reembolsável não pode ser negativo.';

    if (empty($errors)) {
        $vc = $pdo->prepare("SELECT id FROM clients WHERE id=? AND agency_id=? LIMIT 1");
        $vc->execute([(int)$client_id, agency_id()]);
        if (!$vc->fetch()) $errors[] = 'Cliente inválido.';
    }

    // Upload de comprovante
    $documento_comprovante = null;
    if (!empty($_FILES['comprovante']['name'])) {
        $uploadDir = '../uploads/refunds/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }
        $ext      = pathinfo($_FILES['comprovante']['name'], PATHINFO_EXTENSION);
        $fileName = 'refund_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
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
            $stmt = $pdo->prepare("
                INSERT INTO refunds (
                    invoice_id, client_id, passenger_id, supplier_id,
                    type, motivo, descricao,
                    valor_pago, valor_reembolsavel, valor_recebido,
                    status, data_solicitacao, documento_comprovante, observacoes, agency_id
                ) VALUES (
                    :invoice_id, :client_id, :passenger_id, :supplier_id,
                    :type, :motivo, :descricao,
                    :valor_pago, :valor_reembolsavel, 0,
                    'SOLICITADO', :data_solicitacao, :documento_comprovante, :observacoes, :agency_id
                )
            ");
            $stmt->execute([
                ':invoice_id'           => $invoice_id ?: null,
                ':client_id'            => $client_id,
                ':passenger_id'         => $passenger_id ?: null,
                ':supplier_id'          => $supplier_id ?: null,
                ':type'                 => $type,
                ':motivo'               => $motivo,
                ':descricao'            => $descricao ?: null,
                ':valor_pago'           => $valor_pago,
                ':valor_reembolsavel'   => $valor_reembolsavel,
                ':data_solicitacao'     => $data_solicitacao,
                ':documento_comprovante'=> $documento_comprovante,
                ':observacoes'          => $observacoes ?: null,
                ':agency_id'             => agency_id(),
            ]);

            $refundId = $pdo->lastInsertId();
            $userId   = function_exists('getCurrentUserId') ? getCurrentUserId() : null;

            $stmtLog = $pdo->prepare("
                INSERT INTO refund_logs (
                    refund_id, user_id, acao, status_anterior, status_novo, nota, agency_id
                ) VALUES (
                    :refund_id, :user_id, 'CRIACAO', NULL, 'SOLICITADO', :nota, :agency_id
                )
            ");
            $stmtLog->execute([
                ':refund_id' => $refundId,
                ':user_id'   => $userId,
                ':nota'      => 'Solicitação de reembolso criada.',
                ':agency_id' => agency_id(),
            ]);

            $pdo->commit();
header("Location: show.php?id=" . $refundId);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log('refunds/create.php: ' . $e->getMessage());
            $errors[] = 'Erro ao salvar.';
        }
    }
}

// Dados auxiliares (ajustados aos nomes reais das colunas)
$clientsStmt = $pdo->prepare("SELECT id, name FROM clients WHERE agency_id=? ORDER BY name ASC");
$clientsStmt->execute([agency_id()]);
$clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);
$suppliersStmt = $pdo->prepare("SELECT id, name FROM suppliers WHERE agency_id=? ORDER BY name ASC");
$suppliersStmt->execute([agency_id()]);
$suppliers = $suppliersStmt->fetchAll(PDO::FETCH_ASSOC);
$invoicesStmt = $pdo->prepare("SELECT id, invoice_number FROM invoices WHERE agency_id=? ORDER BY id DESC LIMIT 100");
$invoicesStmt->execute([agency_id()]);
$invoices = $invoicesStmt->fetchAll(PDO::FETCH_ASSOC);
$passengersStmt = $pdo->prepare("SELECT id, name FROM passengers WHERE agency_id=? ORDER BY name ASC");
$passengersStmt->execute([agency_id()]);
$passengers = $passengersStmt->fetchAll(PDO::FETCH_ASSOC);

ob_start();
?>
<div class="mx-auto max-w-4xl">

  <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
      <p class="text-xs font-bold uppercase tracking-wider text-brand-600">Reembolsos</p>
      <h2 class="text-xl font-bold text-ink-950">Nova Solicitação de Reembolso</h2>
      <p class="mt-1 text-sm text-ink-500">Registre a solicitação, valores e o comprovante da operação.</p>
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

  <form class="card overflow-hidden" method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <div class="border-b border-ink-100 px-5 py-4">
      <h3 class="text-sm font-bold text-ink-950">Dados do Reembolso</h3>
    </div>
    <div class="p-5">
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">

        <div>
          <label class="label-field" for="client_id">Cliente *</label>
          <select name="client_id" id="client_id" class="select-field" required>
            <option value="">Selecione...</option>
            <?php foreach ($clients as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="label-field" for="invoice_id">Venda (opcional)</label>
          <select name="invoice_id" id="invoice_id" class="select-field">
            <option value="">Nenhuma</option>
            <?php foreach ($invoices as $i): ?>
              <option value="<?= (int)$i['id'] ?>">
                #<?= (int)$i['id'] ?> - <?= htmlspecialchars($i['invoice_number']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="label-field" for="passenger_id">Passageiro (opcional)</label>
          <select name="passenger_id" id="passenger_id" class="select-field">
            <option value="">Nenhum</option>
            <?php foreach ($passengers as $p): ?>
              <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="label-field" for="supplier_id">Fornecedor (opcional)</label>
          <select name="supplier_id" id="supplier_id" class="select-field">
            <option value="">Nenhum</option>
            <?php foreach ($suppliers as $s): ?>
              <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="label-field" for="type">Tipo *</label>
          <select name="type" id="type" class="select-field" required>
            <option value="">Selecione...</option>
            <option value="AEREO">Aéreo</option>
            <option value="HOTEL">Hotel</option>
            <option value="CARRO">Carro</option>
            <option value="OUTRO">Outro</option>
          </select>
        </div>

        <div>
          <label class="label-field" for="data_solicitacao">Data da Solicitação *</label>
          <input type="date" name="data_solicitacao" id="data_solicitacao" value="<?= date('Y-m-d') ?>" class="input-field" required>
        </div>

        <div class="sm:col-span-2 lg:col-span-2">
          <label class="label-field" for="motivo">Motivo *</label>
          <input type="text" name="motivo" id="motivo" class="input-field" required>
        </div>

        <div>
          <label class="label-field" for="comprovante">Comprovante (PDF/JPG/PNG)</label>
          <input type="file" name="comprovante" id="comprovante" class="input-field file:mr-3 file:rounded-lg file:border-0 file:bg-ink-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-ink-700">
        </div>

        <div>
          <label class="label-field" for="valor_pago">Valor pago (R$) *</label>
          <input type="text" name="valor_pago" id="valor_pago" class="input-field" placeholder="1.234,56" required>
        </div>

        <div>
          <label class="label-field" for="valor_reembolsavel">Valor reembolsável (R$) *</label>
          <input type="text" name="valor_reembolsavel" id="valor_reembolsavel" class="input-field" placeholder="1.234,56" required>
        </div>

        <div class="sm:col-span-2 lg:col-span-3">
          <label class="label-field" for="descricao">Descrição</label>
          <textarea name="descricao" id="descricao" class="input-field" rows="3"></textarea>
        </div>

        <div class="sm:col-span-2 lg:col-span-3">
          <label class="label-field" for="observacoes">Observações internas</label>
          <textarea name="observacoes" id="observacoes" class="input-field" rows="3"></textarea>
        </div>

      </div>
    </div>
    <div class="flex items-center justify-end gap-3 border-t border-ink-100 bg-ink-50/50 px-5 py-4">
      <a href="index.php" class="btn-ghost">Cancelar</a>
      <button type="submit" class="btn-primary">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
        Salvar e continuar
      </button>
    </div>
  </form>

</div>
<?php
$body = ob_get_clean();
require __DIR__ . '/../inc/layout.php';
