<?php 

// سجل الأخطاء في ملف داخل reports
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/reports_error.log');

// reports.php — Relatórios (KPIs + Gráficos + Tabelas)
// Requisitos esperados no projeto:
// - /inc/auth.php (require_login())
// - /inc/db.php   ($pdo)
// - /inc/helpers.php (brl(), ymd_to_br(), etc.)

require __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/helpers.php';
require_login();


$pageTitle = 'Relatórios';

// ======================
// Permissões
// ======================
$role = $_SESSION['role'] ?? 'user';
$canSeeCash = in_array($role, ['accountant','admin','superadmin'], true);

// ======================
// Util: detectar colunas reais no DB (para funcionar مع اختلاف أسماء الأعمدة)
// ======================
function table_cols(PDO $pdo, string $table): array {
  static $cache = [];
  if (isset($cache[$table])) return $cache[$table];
  $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
  $st = $pdo->prepare("
    SELECT COLUMN_NAME
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = :db
       AND TABLE_NAME   = :t
  ");
  $st->execute([':db'=>$db, ':t'=>$table]);
  $cols = array_map('strtolower', $st->fetchAll(PDO::FETCH_COLUMN));
  return $cache[$table] = $cols;
}
function pick_col(array $cols, array $candidates, ?string $fallback=null): ?string {
  foreach ($candidates as $c) {
    if (in_array(strtolower($c), $cols, true)) return $c;
  }
  return $fallback;
}
function has_col(array $cols, string $col): bool {
  return in_array(strtolower($col), $cols, true);
}

// ======================
// اكتشاف أعمدة invoices/refunds (قدر الإمكان)
// ======================
$invCols = table_cols($pdo, 'invoices');
$refCols = table_cols($pdo, 'refunds');
$cliCols = table_cols($pdo, 'clients');
$supCols = table_cols($pdo, 'suppliers');

$invDate = pick_col($invCols, ['issue_date','date','created_at','emitted_at','data_emissao','invoice_date']);
$invStatus = pick_col($invCols, ['status','payment_status','paid_status']);

$invTotal = pick_col($invCols, ['total','total_amount','grand_total','valor_total','amount','valor','total_brl']);
$invPaid  = pick_col($invCols, ['paid','paid_amount','amount_paid','total_paid','valor_pago','pago']);
$invDue   = pick_col($invCols, ['due','due_amount','amount_due','valor_aberto','aberto']);

$invSupplier = pick_col($invCols, ['supplier_total','supplier_amount','cost_total','custo_total','supplier_liquid','supplier_net','fornecedor_total']);
$invFees     = pick_col($invCols, ['fees_total','tax_total','service_fee','fee_total','taxas','rava_fee','agency_fee']);

$invProfit   = pick_col($invCols, ['profit','lucro','profit_total','gross_profit']);

// refunds
$refDate   = pick_col($refCols, ['created_at','date','refund_date','data']);
$refStatus = pick_col($refCols, ['status','state']);
$refAmount = pick_col($refCols, ['amount','valor','total','refund_amount']);

// clients/suppliers names
$cliName = pick_col($cliCols, ['name','nome','client_name'], 'name');
$supName = pick_col($supCols, ['name','nome','supplier_name'], 'name');

// ======================
// فلاتر الفترة
// ======================
$today = new DateTime('now', tz_sp());
$fromDefault = (clone $today)->modify('first day of this month')->format('Y-m-d');
$toDefault   = (clone $today)->format('Y-m-d');

$from = $_GET['from'] ?? $fromDefault;
$to   = $_GET['to']   ?? $toDefault;

// Quick presets
$preset = $_GET['preset'] ?? 'this_month';
if ($preset === 'today') {
  $from = $today->format('Y-m-d');
  $to   = $today->format('Y-m-d');
} elseif ($preset === 'this_year') {
  $from = $today->format('Y-01-01');
  $to   = $today->format('Y-m-d');
} elseif ($preset === 'last_12m') {
  $from = (clone $today)->modify('-11 months')->modify('first day of this month')->format('Y-m-d');
  $to   = $today->format('Y-m-d');
}

// Sanitizar datas
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = $fromDefault;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = $toDefault;

// ======================
// بناء تعابير مالية حسب الأعمدة المتوفرة
// ======================
$exprTotal = $invTotal ? "COALESCE(i.`$invTotal`,0)" : "0";
$exprPaid  = $invPaid  ? "COALESCE(i.`$invPaid`,0)"  : ($invStatus ? "CASE WHEN LOWER(i.`$invStatus`) IN ('paid','pago','pago_total','paid_total') THEN $exprTotal ELSE 0 END" : "0");
$exprDue   = $invDue   ? "COALESCE(i.`$invDue`,0)"   : "GREATEST($exprTotal - $exprPaid, 0)";

if ($invProfit) {
  $exprProfit = "COALESCE(i.`$invProfit`,0)";
} else {
  $exprSupplier = $invSupplier ? "COALESCE(i.`$invSupplier`,0)" : "0";
  $exprFees     = $invFees     ? "COALESCE(i.`$invFees`,0)"     : "0";
  $exprProfit   = "($exprTotal - $exprSupplier - $exprFees)";
}

if (!$invDate) {
  // إذا لم يوجد تاريخ، نستخدم created_at إذا كان موجودًا، وإلا نجعل التقارير الزمنية 0
  $invDate = pick_col($invCols, ['created_at']);
}

// شرط التاريخ (شامل الطرفين)
$whereDateInv = ($invDate ? "DATE(i.`$invDate`) BETWEEN :from AND :to" : "1=1") . " AND i.agency_id = :agency_id";
$whereDateRef = ($refDate ? "DATE(r.`$refDate`) BETWEEN :from AND :to" : "1=1") . " AND r.agency_id = :agency_id";
$periodParams = [':from'=>$from, ':to'=>$to, ':agency_id'=>agency_id()];

// ======================
// KPIs عامة
// ======================
$countByAgency = function(string $table) use ($pdo): int {
  if (function_exists('has_table') && !has_table($pdo, $table)) return 0;
  $st = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE agency_id = ?");
  $st->execute([agency_id()]);
  return (int)$st->fetchColumn();
};
$totClients   = $countByAgency('clients');
$totSuppliers = $countByAgency('suppliers');
$totInvoices  = $countByAgency('invoices');
$totRefunds   = $countByAgency('refunds');

// KPIs للفترة
$st = $pdo->prepare("
  SELECT
    COUNT(*) AS invoices_count,
    SUM($exprTotal) AS sales_total,
    SUM($exprPaid)  AS paid_total,
    SUM($exprDue)   AS unpaid_total,
    SUM($exprProfit) AS profit_total
  FROM invoices i
  WHERE $whereDateInv
");
$st->execute($periodParams);
$kpi = $st->fetch(PDO::FETCH_ASSOC) ?: [];

$periodInvoices = (int)($kpi['invoices_count'] ?? 0);
$periodSales    = (float)($kpi['sales_total'] ?? 0);
$periodPaid     = (float)($kpi['paid_total'] ?? 0);
$periodUnpaid   = (float)($kpi['unpaid_total'] ?? 0);
$periodProfit   = (float)($kpi['profit_total'] ?? 0);

// Refunds للفترة
$refundByStatus = [];
$refundTotalAmt = 0.0;
if ($refAmount) {
  $sqlRef = "
    SELECT " . ($refStatus ? "COALESCE(r.`$refStatus`,'—')" : "'—'") . " AS status,
           SUM(COALESCE(r.`$refAmount`,0)) AS total
      FROM refunds r
     WHERE $whereDateRef
  GROUP BY status
  ORDER BY total DESC
  ";
  $st = $pdo->prepare($sqlRef);
  $st->execute($periodParams);
  $refundByStatus = $st->fetchAll(PDO::FETCH_ASSOC);
  foreach ($refundByStatus as $row) $refundTotalAmt += (float)$row['total'];
}

// ======================
// Dados para Gráficos (Mensal في السنة الحالية)
// ======================
$year = (int)($_GET['year'] ?? (new DateTime($from, tz_sp()))->format('Y'));
$yearFrom = sprintf('%04d-01-01', $year);
$yearTo   = sprintf('%04d-12-31', $year);

$months = [];
for ($m=1;$m<=12;$m++) $months[] = sprintf('%02d', $m);

// Default arrays
$seriesSales  = array_fill(1, 12, 0.0);
$seriesProfit = array_fill(1, 12, 0.0);
$seriesPaid   = array_fill(1, 12, 0.0);

if ($invDate) {
  $st = $pdo->prepare("
    SELECT MONTH(i.`$invDate`) AS mm,
           SUM($exprTotal)  AS sales,
           SUM($exprPaid)   AS paid,
           SUM($exprProfit) AS profit
      FROM invoices i
	     WHERE DATE(i.`$invDate`) BETWEEN :yf AND :yt AND i.agency_id = :agency_id
  GROUP BY mm
  ");
	  $st->execute([':yf'=>$yearFrom, ':yt'=>$yearTo, ':agency_id'=>agency_id()]);
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $mm = (int)$r['mm'];
    if ($mm>=1 && $mm<=12) {
      $seriesSales[$mm]  = (float)$r['sales'];
      $seriesPaid[$mm]   = (float)$r['paid'];
      $seriesProfit[$mm] = (float)$r['profit'];
    }
  }
}

// ======================
// Top lists (Clientes / Fornecedores / Faturas)
// ======================
$topClients = [];
$topSuppliers = [];
$topInvoices = [];

if ($invTotal && $invCols && has_col($invCols,'client_id') && in_array('id',$cliCols,true)) {
  $st = $pdo->prepare("
    SELECT c.`$cliName` AS nome, SUM($exprTotal) AS total
      FROM invoices i
	      JOIN clients c ON c.id = i.client_id AND c.agency_id = i.agency_id
     WHERE $whereDateInv
  GROUP BY c.id
  ORDER BY total DESC
     LIMIT 10
  ");
	  $st->execute($periodParams);
  $topClients = $st->fetchAll(PDO::FETCH_ASSOC);
}

if ($invCols && has_col($invCols,'supplier_id') && in_array('id',$supCols,true)) {
  $st = $pdo->prepare("
    SELECT s.`$supName` AS nome, SUM(" . ($invSupplier ? "COALESCE(i.`$invSupplier`,0)" : "0") . ") AS custo
      FROM invoices i
	      JOIN suppliers s ON s.id = i.supplier_id AND s.agency_id = i.agency_id
     WHERE $whereDateInv
  GROUP BY s.id
  ORDER BY custo DESC
     LIMIT 10
  ");
	  $st->execute($periodParams);
  $topSuppliers = $st->fetchAll(PDO::FETCH_ASSOC);
}

if ($invCols && has_col($invCols,'invoice_number')) {
  $st = $pdo->prepare("
    SELECT i.invoice_number AS numero, $exprTotal AS total, $exprProfit AS lucro
      FROM invoices i
     WHERE $whereDateInv
  ORDER BY lucro DESC
     LIMIT 10
  ");
	  $st->execute($periodParams);
  $topInvoices = $st->fetchAll(PDO::FETCH_ASSOC);
}

// ======================
// UI داخل layout الرئيسي للنظام
// ======================
$reportsBackHref = $role === 'superadmin' ? '/master/dashboard.php' : '/dashboard.php';
require __DIR__ . '/../inc/header.php';
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
  .kpi-card .h1{ margin:0; }
  .muted{ color:#64748b; }
  .mono{ font-variant-numeric: tabular-nums; }
  .reports-filter{
    background: rgba(255,255,255,.72);
    border: 1px solid rgba(148,163,184,.24);
    border-radius: 18px;
    padding: 1rem;
    box-shadow: 0 16px 40px rgba(15,23,42,.06);
  }
  @media print{
    .no-print{ display:none !important; }
    .card{ break-inside: avoid; }
  }
</style>

<div class="page-header no-print">
  <div class="row g-2 align-items-center">
    <div class="col">
      <div class="page-pretitle">KAMALTUR POS</div>
      <h2 class="page-title">Relatórios do Sistema</h2>
      <div class="text-secondary">
        Período: <span class="mono"><?= htmlspecialchars($from) ?></span> até <span class="mono"><?= htmlspecialchars($to) ?></span>
      </div>
    </div>
    <div class="col-auto ms-auto d-print-none">
      <div class="btn-list">
        <button class="btn btn-outline-secondary" onclick="window.print()">Imprimir</button>
        <a class="btn btn-outline-secondary" href="?preset=this_month">Este mês</a>
        <a class="btn btn-outline-secondary" href="?preset=this_year">Este ano</a>
        <a class="btn btn-outline-secondary" href="?preset=last_12m">Últimos 12 meses</a>
      </div>
    </div>
  </div>

  <form class="reports-filter row g-2 mt-3" method="get">
    <div class="col-12 col-md-3">
      <label class="form-label">De</label>
      <input type="date" name="from" class="form-control" value="<?= htmlspecialchars($from) ?>">
    </div>
    <div class="col-12 col-md-3">
      <label class="form-label">Até</label>
      <input type="date" name="to" class="form-control" value="<?= htmlspecialchars($to) ?>">
    </div>
    <div class="col-12 col-md-2">
      <label class="form-label">Ano (gráficos)</label>
      <input type="number" name="year" class="form-control" value="<?= (int)$year ?>" min="2000" max="2100">
    </div>
    <div class="col-12 col-md-4 d-flex align-items-end gap-2">
      <button class="btn btn-primary" type="submit">Aplicar</button>
      <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($reportsBackHref) ?>">Voltar</a>
    </div>
  </form>
</div>

        <!-- KPIs gerais -->
        <div class="row row-cards">
          <div class="col-6 col-lg-3">
            <div class="card kpi-card">
              <div class="card-body">
                <div class="subheader">Clientes</div>
                <div class="h1 mono"><?= number_format($totClients, 0, ',', '.') ?></div>
              </div>
            </div>
          </div>
          <div class="col-6 col-lg-3">
            <div class="card kpi-card">
              <div class="card-body">
                <div class="subheader">Fornecedores</div>
                <div class="h1 mono"><?= number_format($totSuppliers, 0, ',', '.') ?></div>
              </div>
            </div>
          </div>
          <div class="col-6 col-lg-3">
            <div class="card kpi-card">
              <div class="card-body">
                <div class="subheader">Faturas</div>
                <div class="h1 mono"><?= number_format($totInvoices, 0, ',', '.') ?></div>
              </div>
            </div>
          </div>
          <div class="col-6 col-lg-3">
            <div class="card kpi-card">
              <div class="card-body">
                <div class="subheader">Reembolsos</div>
                <div class="h1 mono"><?= number_format($totRefunds, 0, ',', '.') ?></div>
              </div>
            </div>
          </div>
        </div>

        <!-- KPIs financeiros (período) -->
        <div class="row row-cards mt-2">
          <div class="col-12 col-lg-3">
            <div class="card">
              <div class="card-body">
                <div class="subheader">Faturas no período</div>
                <div class="h1 mono"><?= number_format($periodInvoices, 0, ',', '.') ?></div>
                <div class="text-secondary">Intervalo aplicado</div>
              </div>
            </div>
          </div>

          <div class="col-12 col-lg-3">
            <div class="card">
              <div class="card-body">
                <div class="subheader">Vendas (Sales)</div>
                <div class="h1 mono"><?= brl($periodSales) ?></div>
                <div class="text-secondary">Soma do total</div>
              </div>
            </div>
          </div>

          <div class="col-12 col-lg-3">
            <div class="card">
              <div class="card-body">
                <div class="subheader">Recebido (Paid)</div>
                <div class="h1 mono"><?= brl($periodPaid) ?></div>
                <div class="text-secondary">Total pago</div>
              </div>
            </div>
          </div>

          <div class="col-12 col-lg-3">
            <div class="card">
              <div class="card-body">
                <div class="subheader">Em aberto (Unpaid)</div>
                <div class="h1 mono"><?= brl($periodUnpaid) ?></div>
                <div class="text-secondary">Total pendente</div>
              </div>
            </div>
          </div>
        </div>

        <?php if ($canSeeCash): ?>
        <div class="row row-cards mt-2">
          <div class="col-12 col-lg-4">
            <div class="card">
              <div class="card-body">
                <div class="subheader">Lucro (Profit) — período</div>
                <div class="h1 mono"><?= brl($periodProfit) ?></div>
                <div class="text-secondary">Cálculo automático conforme colunas disponíveis</div>
                <div class="mt-2 muted">
                  <small>
                    Expressão: <span class="mono"><?= htmlspecialchars($exprProfit) ?></span>
                  </small>
                </div>
              </div>
            </div>
          </div>

          <div class="col-12 col-lg-4">
            <div class="card">
              <div class="card-body">
                <div class="subheader">Total Reembolsos — período</div>
                <div class="h1 mono"><?= brl($refundTotalAmt) ?></div>
                <div class="text-secondary">Soma dos reembolsos</div>
              </div>
            </div>
          </div>

          <div class="col-12 col-lg-4">
            <div class="card">
              <div class="card-body">
                <div class="subheader">Taxa de recebimento</div>
                <?php $rate = ($periodSales > 0) ? ($periodPaid / $periodSales) * 100 : 0; ?>
                <div class="h1 mono"><?= number_format($rate, 2, ',', '.') ?>%</div>
                <div class="text-secondary">Paid / Sales</div>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- Gráficos -->
        <div class="row row-cards mt-2">
          <div class="col-12 col-lg-8">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title">Vendas e Recebidos — <?= (int)$year ?></h3>
              </div>
              <div class="card-body">
                <canvas id="chartSales" height="110"></canvas>
              </div>
            </div>
          </div>

          <div class="col-12 col-lg-4">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title">Lucro — <?= (int)$year ?></h3>
              </div>
              <div class="card-body">
                <?php if ($canSeeCash): ?>
                  <canvas id="chartProfit" height="170"></canvas>
                <?php else: ?>
                  <div class="text-secondary">Sem permissão para visualizar lucro.</div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

        <!-- Top lists -->
        <div class="row row-cards mt-2">
          <div class="col-12 col-lg-4">
            <div class="card">
              <div class="card-header"><h3 class="card-title">Top 10 Clientes (Vendas)</h3></div>
              <div class="table-responsive">
                <table class="table table-vcenter card-table">
                  <thead><tr><th>Cliente</th><th class="text-end">Total</th></tr></thead>
                  <tbody>
                  <?php if (!$topClients): ?>
                    <tr><td colspan="2" class="text-secondary">Sem dados (verifique client_id / total).</td></tr>
                  <?php else: foreach ($topClients as $r): ?>
                    <tr>
                      <td><?= htmlspecialchars($r['nome'] ?? '—') ?></td>
                      <td class="text-end mono"><?= brl((float)$r['total']) ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="col-12 col-lg-4">
            <div class="card">
              <div class="card-header"><h3 class="card-title">Top 10 Fornecedores (Custo)</h3></div>
              <div class="table-responsive">
                <table class="table table-vcenter card-table">
                  <thead><tr><th>Fornecedor</th><th class="text-end">Custo</th></tr></thead>
                  <tbody>
                  <?php if (!$topSuppliers): ?>
                    <tr><td colspan="2" class="text-secondary">Sem dados (verifique supplier_id / supplier_total).</td></tr>
                  <?php else: foreach ($topSuppliers as $r): ?>
                    <tr>
                      <td><?= htmlspecialchars($r['nome'] ?? '—') ?></td>
                      <td class="text-end mono"><?= brl((float)$r['custo']) ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="col-12 col-lg-4">
            <div class="card">
              <div class="card-header"><h3 class="card-title">Top 10 Faturas (Lucro)</h3></div>
              <div class="table-responsive">
                <table class="table table-vcenter card-table">
                  <thead><tr><th>Fatura</th><th class="text-end">Total</th><?php if ($canSeeCash): ?><th class="text-end">Lucro</th><?php endif; ?></tr></thead>
                  <tbody>
                  <?php if (!$topInvoices): ?>
                    <tr><td colspan="<?= $canSeeCash?3:2 ?>" class="text-secondary">Sem dados (verifique invoice_number).</td></tr>
                  <?php else: foreach ($topInvoices as $r): ?>
                    <tr>
                      <td class="mono"><?= htmlspecialchars($r['numero'] ?? '—') ?></td>
                      <td class="text-end mono"><?= brl((float)$r['total']) ?></td>
                      <?php if ($canSeeCash): ?>
                        <td class="text-end mono"><?= brl((float)$r['lucro']) ?></td>
                      <?php endif; ?>
                    </tr>
                  <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <!-- Refunds por status -->
        <div class="row row-cards mt-2">
          <div class="col-12">
            <div class="card">
              <div class="card-header"><h3 class="card-title">Reembolsos por Status (período)</h3></div>
              <div class="table-responsive">
                <table class="table table-vcenter card-table">
                  <thead><tr><th>Status</th><th class="text-end">Total</th></tr></thead>
                  <tbody>
                  <?php if (!$refAmount): ?>
                    <tr><td colspan="2" class="text-secondary">Tabela refunds não tem coluna de valor (amount/valor/total).</td></tr>
                  <?php elseif (!$refundByStatus): ?>
                    <tr><td colspan="2" class="text-secondary">Sem reembolsos no período.</td></tr>
                  <?php else: foreach ($refundByStatus as $r): ?>
                    <tr>
                      <td><?= htmlspecialchars($r['status'] ?? '—') ?></td>
                      <td class="text-end mono"><?= brl((float)$r['total']) ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <?php if ($role === 'superadmin'): ?>
        <!-- Debug: visível apenas para superadmin -->
        <div class="row row-cards mt-2 no-print">
          <div class="col-12">
            <details class="card">
              <summary class="card-header"><strong>Debug — الأعمدة المكتشفة</strong></summary>
              <div class="card-body">
                <div class="row g-3">
                  <div class="col-12 col-lg-6">
                    <div class="text-secondary mb-2"><strong>Invoices</strong></div>
                    <div class="mono">date: <?= htmlspecialchars($invDate ?? '—') ?></div>
                    <div class="mono">total: <?= htmlspecialchars($invTotal ?? '—') ?></div>
                    <div class="mono">paid: <?= htmlspecialchars($invPaid ?? '—') ?></div>
                    <div class="mono">due: <?= htmlspecialchars($invDue ?? '—') ?></div>
                    <div class="mono">profit: <?= htmlspecialchars($invProfit ?? '—') ?></div>
                    <div class="mono">supplier: <?= htmlspecialchars($invSupplier ?? '—') ?></div>
                    <div class="mono">fees: <?= htmlspecialchars($invFees ?? '—') ?></div>
                    <div class="mono">status: <?= htmlspecialchars($invStatus ?? '—') ?></div>
                  </div>
                  <div class="col-12 col-lg-6">
                    <div class="text-secondary mb-2"><strong>Refunds</strong></div>
                    <div class="mono">date: <?= htmlspecialchars($refDate ?? '—') ?></div>
                    <div class="mono">status: <?= htmlspecialchars($refStatus ?? '—') ?></div>
                    <div class="mono">amount: <?= htmlspecialchars($refAmount ?? '—') ?></div>
                  </div>
                </div>
              </div>
            </details>
          </div>
        </div>
        <?php endif; ?>

<script>
  const labels = <?= json_encode($months, JSON_UNESCAPED_UNICODE) ?>;

  const sales  = <?= json_encode(array_values($seriesSales), JSON_UNESCAPED_UNICODE) ?>;
  const paid   = <?= json_encode(array_values($seriesPaid), JSON_UNESCAPED_UNICODE) ?>;
  const profit = <?= json_encode(array_values($seriesProfit), JSON_UNESCAPED_UNICODE) ?>;

  // Vendas x Recebidos
  const ctxSales = document.getElementById('chartSales');
  if (window.Chart && ctxSales) {
    new Chart(ctxSales, {
      type: 'line',
      data: {
        labels,
        datasets: [
          { label: 'Vendas', data: sales, tension: 0.25 },
          { label: 'Recebido', data: paid, tension: 0.25 }
        ]
      },
      options: {
        responsive: true,
        plugins: { legend: { position: 'bottom' } },
        scales: {
          y: { ticks: { callback: v => (Number(v)||0).toLocaleString('pt-BR', { style:'currency', currency:'BRL' }) } }
        }
      }
    });
  }

  // Lucro
  const ctxProfit = document.getElementById('chartProfit');
  if (window.Chart && ctxProfit) {
    new Chart(ctxProfit, {
      type: 'bar',
      data: {
        labels,
        datasets: [{ label: 'Lucro', data: profit }]
      },
      options: {
        responsive: true,
        plugins: { legend: { position: 'bottom' } },
        scales: {
          y: { ticks: { callback: v => (Number(v)||0).toLocaleString('pt-BR', { style:'currency', currency:'BRL' }) } }
        }
      }
    });
  }
</script>
<?php require __DIR__ . '/../inc/footer.php'; ?>
