<?php
// refunds/index.php

require_once '../inc/db.php';
require_once '../inc/auth.php';
require_once '../inc/helpers.php';
require_login();

$statusFilter   = $_GET['status']  ?? '';
$clienteSearch  = $_GET['cliente'] ?? '';

$statuses = [
    'SOLICITADO'            => 'Solicitado',
    'EM_ANALISE'            => 'Em análise',
    'AGUARDANDO_FORNECEDOR' => 'Aguardando fornecedor',
    'APROVADO'              => 'Aprovado',
    'NEGADO'                => 'Negado',
    'REEMBOLSADO'           => 'Reembolsado',
];

if (!has_table($pdo, 'refunds')) {
    $refunds = [];
    require_once '../inc/header.php';
    ?>
    <div class="page-body">
      <div class="container-xl">
        <div class="alert alert-warning mt-3">Tabela de reembolsos não está disponível na base atual.</div>
      </div>
    </div>
    <?php require_once '../inc/footer.php'; exit; ?>
<?php }

$selects = ['r.*'];
$joins = [];

if (has_table($pdo, 'clients')) {
    $selects[] = 'c.name AS client_name';
    $joins[] = 'LEFT JOIN clients c ON c.id = r.client_id AND c.agency_id = r.agency_id';
} else {
    $selects[] = 'NULL AS client_name';
}

if (has_table($pdo, 'suppliers')) {
    $selects[] = 's.name AS supplier_name';
    $joins[] = 'LEFT JOIN suppliers s ON s.id = r.supplier_id AND s.agency_id = r.agency_id';
} else {
    $selects[] = 'NULL AS supplier_name';
}

if (has_table($pdo, 'invoices')) {
    $selects[] = 'i.invoice_number';
    $joins[] = 'LEFT JOIN invoices i ON i.id = r.invoice_id AND i.agency_id = r.agency_id';
} else {
    $selects[] = 'NULL AS invoice_number';
}

if (has_table($pdo, 'passengers')) {
    $selects[] = 'p.name AS passenger_name';
    $joins[] = 'LEFT JOIN passengers p ON p.id = r.passenger_id AND p.agency_id = r.agency_id';
} else {
    $selects[] = 'NULL AS passenger_name';
}

$sql = "
    SELECT " . implode(",\n           ", $selects) . "
      FROM refunds r
      " . implode("\n ", $joins) . "
     WHERE r.agency_id = :agency_id
";

$params = [':agency_id' => agency_id()];

if ($statusFilter !== '') {
    $sql .= " AND r.status = :status ";
    $params[':status'] = $statusFilter;
}

if ($clienteSearch !== '' && has_table($pdo, 'clients')) {
    $sql .= " AND c.name LIKE :cliente ";
    $params[':cliente'] = '%' . $clienteSearch . '%';
}

$sql .= " ORDER BY r.data_atualizacao DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$refunds = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../inc/header.php';
?>
<div class="page-header d-print-none">
  <div class="container-xl">
    <div class="row g-2 align-items-center">
      <div class="col">
        <h2 class="page-title">
          Gestão de Reembolsos
        </h2>
      </div>
      <div class="col-auto ms-auto d-print-none">
        <a href="create.php" class="btn btn-primary">
          + Nova Solicitação de Reembolso
        </a>
      </div>
    </div>
  </div>
</div>

<div class="page-body">
  <div class="container-xl">
    <form class="card mb-3" method="get">
      <div class="card-body">
        <div class="row g-2">
          <div class="col-md-4">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
              <option value="">Todos</option>
              <?php foreach ($statuses as $key => $label): ?>
                <option value="<?= htmlspecialchars($key) ?>" <?= $statusFilter === $key ? 'selected' : '' ?>>
                  <?= htmlspecialchars($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Cliente</label>
            <input type="text" name="cliente" value="<?= htmlspecialchars($clienteSearch) ?>" class="form-control" placeholder="Buscar por cliente">
          </div>
          <div class="col-md-4 d-flex align-items-end">
            <button type="submit" class="btn btn-primary me-2">Filtrar</button>
            <a href="index.php" class="btn btn-secondary">Limpar</a>
          </div>
        </div>
      </div>
    </form>

    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Solicitações de Reembolso</h3>
      </div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead>
            <tr>
              <th>#</th>
              <th>Cliente</th>
              <th>Passageiro</th>
              <th>Tipo</th>
              <th>Venda</th>
              <th class="text-end">Valor Pago</th>
              <th class="text-end">Valor Reembolsável</th>
              <th>Status</th>
              <th>Atualizado em</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($refunds)): ?>
            <tr>
              <td colspan="10" class="text-center text-muted">Nenhuma solicitação encontrada.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($refunds as $r): ?>
              <?php
                $badgeClass = 'bg-blue';
                if ($r['status'] === 'REEMBOLSADO') $badgeClass = 'bg-green';
                elseif ($r['status'] === 'NEGADO') $badgeClass = 'bg-red';
              ?>
              <tr>
                <td><?= (int)$r['id'] ?></td>
                <td><?= htmlspecialchars($r['client_name'] ?? '-') ?></td>
                <td><?= htmlspecialchars($r['passenger_name'] ?? '-') ?></td>
                <td><?= htmlspecialchars($r['type']) ?></td>
                <td><?= htmlspecialchars($r['invoice_number'] ?? '-') ?></td>
                <td class="text-end">R$ <?= number_format($r['valor_pago'], 2, ',', '.') ?></td>
                <td class="text-end">R$ <?= number_format($r['valor_reembolsavel'], 2, ',', '.') ?></td>
                <td>
                  <span class="badge <?= $badgeClass ?>">
                    <?= htmlspecialchars($statuses[$r['status']] ?? $r['status']) ?>
                  </span>
                </td>
                <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($r['data_atualizacao']))) ?></td>
                <td class="text-end">
                  <a href="show.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary">Detalhes</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php require_once '../inc/footer.php'; ?>
