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

require_once '../inc/header.php';
?>
<div class="page-header d-print-none">
  <div class="container-xl">
    <div class="row g-2 align-items-center">
      <div class="col">
        <h2 class="page-title">
          Nova Solicitação de Reembolso
        </h2>
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

    <form class="card" method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
      <div class="card-header">
        <h3 class="card-title">Dados do Reembolso</h3>
      </div>
      <div class="card-body">
        <div class="row g-3">

          <div class="col-md-4">
            <label class="form-label">Cliente *</label>
            <select name="client_id" class="form-select" required>
              <option value="">Selecione...</option>
              <?php foreach ($clients as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Venda (opcional)</label>
            <select name="invoice_id" class="form-select">
              <option value="">Nenhuma</option>
              <?php foreach ($invoices as $i): ?>
                <option value="<?= (int)$i['id'] ?>">
                  #<?= (int)$i['id'] ?> - <?= htmlspecialchars($i['invoice_number']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Passageiro (opcional)</label>
            <select name="passenger_id" class="form-select">
              <option value="">Nenhum</option>
              <?php foreach ($passengers as $p): ?>
                <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Fornecedor (opcional)</label>
            <select name="supplier_id" class="form-select">
              <option value="">Nenhum</option>
              <?php foreach ($suppliers as $s): ?>
                <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Tipo *</label>
            <select name="type" class="form-select" required>
              <option value="">Selecione...</option>
              <option value="AEREO">Aéreo</option>
              <option value="HOTEL">Hotel</option>
              <option value="CARRO">Carro</option>
              <option value="OUTRO">Outro</option>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Data da Solicitação *</label>
            <input type="date" name="data_solicitacao" value="<?= date('Y-m-d') ?>" class="form-control" required>
          </div>

          <div class="col-md-6">
            <label class="form-label">Motivo *</label>
            <input type="text" name="motivo" class="form-control" required>
          </div>

          <div class="col-md-6">
            <label class="form-label">Comprovante (PDF/JPG/PNG)</label>
            <input type="file" name="comprovante" class="form-control">
          </div>

          <div class="col-md-6">
            <label class="form-label">Valor pago (R$) *</label>
            <input type="text" name="valor_pago" class="form-control" placeholder="1.234,56" required>
          </div>

          <div class="col-md-6">
            <label class="form-label">Valor reembolsável (R$) *</label>
            <input type="text" name="valor_reembolsavel" class="form-control" placeholder="1.234,56" required>
          </div>

          <div class="col-12">
            <label class="form-label">Descrição</label>
            <textarea name="descricao" class="form-control" rows="3"></textarea>
          </div>

          <div class="col-12">
            <label class="form-label">Observações internas</label>
            <textarea name="observacoes" class="form-control" rows="3"></textarea>
          </div>

        </div>
      </div>
      <div class="card-footer text-end">
        <a href="index.php" class="btn btn-link">Cancelar</a>
        <button type="submit" class="btn btn-primary">Salvar e continuar</button>
      </div>
    </form>
  </div>
</div>
<?php require_once '../inc/footer.php'; ?>
