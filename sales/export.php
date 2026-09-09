<?php
declare(strict_types=1);

require __DIR__ . '/../inc/auth.php';
require_login();

$pageTitle = 'Exportar Vendas';
require __DIR__ . '/../inc/header.php';

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
?>

<div class="page-header d-print-none mb-3">
  <div class="row align-items-center">
    <div class="col">
      <h2 class="page-title">Exportar Vendas</h2>
      <div class="text-muted small">Arquivo neutro para importar em outro sistema, sem depender das mesmas tabelas.</div>
    </div>
    <div class="col-auto">
      <a href="/sales/index.php" class="btn"><i class="ti ti-arrow-left me-1"></i> Voltar</a>
    </div>
  </div>
</div>

<form class="card" method="get" action="/sales/export_download.php">
  <div class="card-header"><h3 class="card-title">Configuração do arquivo</h3></div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label">De</label>
        <input class="form-control" type="date" name="from" value="<?= h($monthStart) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Até</label>
        <input class="form-control" type="date" name="to" value="<?= h($today) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Status</label>
        <select class="form-select" name="status">
          <option value="">Todos</option>
          <option value="nao pago">Não pago</option>
          <option value="pago parcial">Pago parcial</option>
          <option value="pago">Pago</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Formato</label>
        <select class="form-select" name="format">
          <option value="zip">ZIP: CSV + JSON</option>
          <option value="json">JSON único</option>
          <option value="csv">CSV resumido</option>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label">Buscar</label>
        <input class="form-control" name="q" placeholder="Número, cliente ou PNR">
      </div>
      <div class="col-md-6">
        <label class="form-label">Conteúdo</label>
        <label class="form-check mt-2">
          <input class="form-check-input" type="checkbox" name="include_details" value="1" checked>
          <span class="form-check-label">Incluir passageiros, trechos e serviços em arquivos separados</span>
        </label>
      </div>
    </div>
  </div>
  <div class="card-footer d-flex justify-content-end">
    <button class="btn btn-primary" type="submit">
      <i class="ti ti-download me-1"></i> Exportar
    </button>
  </div>
</form>

<div class="alert alert-info mt-3">
  <strong>Formato neutro:</strong> o ZIP inclui <code>sales.csv</code>, <code>passengers.csv</code>, <code>segments.csv</code>, <code>services.csv</code>,
  <code>sales_export.json</code> e <code>README.txt</code>. Outro sistema pode ler por <code>external_sale_id</code> e <code>sale_number</code>.
</div>

<?php require __DIR__ . '/../inc/footer.php'; ?>
