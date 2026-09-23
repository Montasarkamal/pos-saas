<?php 

// سجل الأخطاء في ملف داخل reports
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/reports_error.log');

// reports.php — Relatórios (KPIs + Gráficos + Tabelas) — modern layout (Phase 2)
// Requisitos esperados no projeto:
// - /inc/auth.php (require_login())
// - /inc/db.php   ($pdo)
// - /inc/helpers.php (brl(), ymd_to_br(), etc.)

require __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/ui.php';
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

// [the remaining PHP between here and header include computes bottom 3 sections]

$topClients = [];
$topSuppliers = [];
$topInvoices = [];

// clientes / fornecedores / faturas top-10
$cliNameSql = $cliName ? "COALESCE(c.`$cliName`,'—')" : "'—'";
$supNameSql = $supName ? "COALESCE(s.`$supName`,'—')" : "'—'";
$invNoCol   = pick_col($invCols, ['invoice_number','numero','n','invoice_no']);
$invNumSql  = $invNoCol ? "COALESCE(i.`$invNoCol`,'—')" : "'#' || i.id";

if ($cliName && $invTotal) {
  try {
    $st = $pdo->prepare("
      SELECT $cliNameSql AS nome, CAST(SUM($exprTotal) AS DECIMAL(14,2)) AS total
        FROM invoices i
        LEFT JOIN clients c ON c.id = i.client_id AND c.agency_id = i.agency_id
       WHERE i.agency_id = :agency_id AND DATE(i.`$invDate`) BETWEEN :from AND :to
       GROUP BY c.id
       ORDER BY total DESC LIMIT 10
    ");
    $st->execute($periodParams);
    $topClients = $st->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { error_log('reports topClients: '.$e->getMessage()); }
}

if ($supName && $invSupplier) {
  try {
    $st = $pdo->prepare("
      SELECT $supNameSql AS nome, CAST(SUM($exprSupplier) AS DECIMAL(14,2)) AS custo
        FROM invoices i
        LEFT JOIN suppliers s ON s.id = i.supplier_id AND s.agency_id = i.agency_id
       WHERE i.agency_id = :agency_id AND DATE(i.`$invDate`) BETWEEN :from AND :to
       GROUP BY s.id
       ORDER BY custo DESC LIMIT 10
    ");
    $st->execute($periodParams);
    $topSuppliers = $st->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { error_log('reports topSuppliers: '.$e->getMessage()); }
}

if ($invTotal) {
  try {
    $st = $pdo->prepare("
      SELECT $invNumSql AS numero, CAST(SUM($exprTotal) AS DECIMAL(14,2)) AS total,
             CAST(SUM($exprProfit) AS DECIMAL(14,2)) AS lucro
        FROM invoices i
       WHERE i.agency_id = :agency_id AND DATE(i.`$invDate`) BETWEEN :from AND :to
       GROUP BY i.id
       ORDER BY total DESC LIMIT 10
    ");
    $st->execute($periodParams);
    $topInvoices = $st->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { error_log('reports topInvoices: '.$e->getMessage()); }
}

// série mensal
if ($invDate && $invTotal) {
  try {
    $stM = $pdo->prepare("
      SELECT MONTH(i.`$invDate`) AS m,
             SUM($exprTotal) AS s_total,
             SUM($exprPaid)  AS s_paid,
             SUM($exprProfit) AS s_profit
        FROM invoices i
       WHERE i.agency_id = :agency_id
         AND DATE(i.`$invDate`) BETWEEN :yfrom AND :yto
       GROUP BY MONTH(i.`$invDate`)
    ");
    $stM->execute([':agency_id'=>agency_id(), ':yfrom'=>$yearFrom, ':yto'=>$yearTo]);
    foreach ($stM->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $m = (int)$row['m'];
      if ($m >= 1 && $m <= 12) {
        $seriesSales[$m]  = (float)($row['s_total'] ?? 0);
        $seriesPaid[$m]   = (float)($row['s_paid'] ?? 0);
        $seriesProfit[$m] = (float)($row['s_profit'] ?? 0);
      }
    }
  } catch (Throwable $e) { error_log('reports monthly series: '.$e->getMessage()); }
}

$reportsBackHref = $role === 'superadmin' ? '/master/dashboard.php' : '/dashboard.php';

ob_start();
?>
<!-- Header -->
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-xl font-bold text-ink-950">Relatórios do Sistema</h2>
        <p class="mt-0.5 text-sm text-ink-500">
            Período: <span class="font-mono"><?= htmlspecialchars($from) ?></span> até <span class="font-mono"><?= htmlspecialchars($to) ?></span>
        </p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <button class="btn-ghost" onclick="window.print()">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
            Imprimir
        </button>
        <a class="btn-ghost" href="?preset=this_month"><span class="text-xs">Este mês</span></a>
        <a class="btn-ghost" href="?preset=this_year"><span class="text-xs">Este ano</span></a>
        <a class="btn-ghost" href="?preset=last_12m"><span class="text-xs">Últimos 12 meses</span></a>
    </div>
</div>

<!-- Filtros -->
<form method="get" class="card mb-5 p-5">
    <div class="grid grid-cols-1 items-end gap-4 md:grid-cols-2 lg:grid-cols-4">
        <div>
            <label class="label-field" for="r-from">De</label>
            <input id="r-from" class="select-field" type="date" name="from" value="<?= htmlspecialchars($from) ?>">
        </div>
        <div>
            <label class="label-field" for="r-to">Até</label>
            <input id="r-to" class="select-field" type="date" name="to" value="<?= htmlspecialchars($to) ?>">
        </div>
        <div>
            <label class="label-field" for="r-year">Ano (gráficos)</label>
            <input id="r-year" class="select-field" type="number" name="year" value="<?= (int)$year ?>" min="2000" max="2100">
        </div>
        <div class="flex gap-2 md:col-span-2 lg:col-span-1">
            <button class="btn-primary flex-1 sm:flex-none sm:px-8" type="submit">Aplicar</button>
            <a class="btn-ghost" href="<?= htmlspecialchars($reportsBackHref) ?>">Voltar</a>
        </div>
    </div>
</form>

<!-- KPIs gerais -->
<div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <div class="card p-5">
        <p class="stat-label">Clientes</p>
        <p class="stat-value"><?= number_format($totClients, 0, ',', '.') ?></p>
    </div>
    <div class="card p-5">
        <p class="stat-label">Fornecedores</p>
        <p class="stat-value"><?= number_format($totSuppliers, 0, ',', '.') ?></p>
    </div>
    <div class="card p-5">
        <p class="stat-label">Faturas</p>
        <p class="stat-value"><?= number_format($totInvoices, 0, ',', '.') ?></p>
    </div>
    <div class="card p-5">
        <p class="stat-label">Reembolsos</p>
        <p class="stat-value"><?= number_format($totRefunds, 0, ',', '.') ?></p>
    </div>
</div>

<!-- KPIs financeiros (período) -->
<div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <div class="card p-5">
        <p class="stat-label">Faturas no período</p>
        <p class="stat-value"><?= number_format($periodInvoices, 0, ',', '.') ?></p>
        <p class="mt-1 text-xs text-ink-400">Intervalo aplicado</p>
    </div>
    <div class="card p-5">
        <p class="stat-label">Vendas (Sales)</p>
        <p class="stat-value text-brand-600"><?= brl($periodSales) ?></p>
        <p class="mt-1 text-xs text-ink-400">Soma do total</p>
    </div>
    <div class="card p-5">
        <p class="stat-label">Recebido (Paid)</p>
        <p class="stat-value text-emerald-600"><?= brl($periodPaid) ?></p>
        <p class="mt-1 text-xs text-ink-400">Total pago</p>
    </div>
    <div class="card p-5">
        <p class="stat-label">Em aberto (Unpaid)</p>
        <p class="stat-value text-red-500"><?= brl($periodUnpaid) ?></p>
        <p class="mt-1 text-xs text-ink-400">Total pendente</p>
    </div>
</div>

<?php if ($canSeeCash): ?>
<div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
    <div class="card p-5">
        <p class="stat-label">Lucro (Profit) — período</p>
        <p class="stat-value"><?= brl($periodProfit) ?></p>
        <p class="mt-1 text-xs text-ink-400">Cálculo automático conforme colunas disponíveis</p>
        <p class="mt-2 font-mono text-[11px] text-ink-400"><?= htmlspecialchars($exprProfit) ?></p>
    </div>
    <div class="card p-5">
        <p class="stat-label">Total Reembolsos — período</p>
        <p class="stat-value"><?= brl($refundTotalAmt) ?></p>
        <p class="mt-1 text-xs text-ink-400">Soma dos reembolsos</p>
    </div>
    <div class="card p-5">
        <p class="stat-label">Taxa de recebimento</p>
        <?php $rate = ($periodSales > 0) ? ($periodPaid / $periodSales) * 100 : 0; ?>
        <p class="stat-value"><?= number_format($rate, 2, ',', '.') ?>%</p>
        <p class="mt-1 text-xs text-ink-400">Paid / Sales</p>
    </div>
</div>
<?php endif; ?>

<!-- Gráficos -->
<div class="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-12">
    <div class="card p-5 lg:col-span-8">
        <h3 class="mb-4 text-sm font-bold text-ink-950">Vendas e Recebidos — <?= (int)$year ?></h3>
        <canvas id="chartSales" height="110"></canvas>
    </div>
    <div class="card p-5 lg:col-span-4">
        <h3 class="mb-4 text-sm font-bold text-ink-950">Lucro — <?= (int)$year ?></h3>
        <?php if ($canSeeCash): ?>
            <canvas id="chartProfit" height="170"></canvas>
        <?php else: ?>
            <p class="text-sm text-ink-400">Sem permissão para visualizar lucro.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Top lists -->
<div class="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-3">
    <div class="card overflow-hidden">
        <div class="border-b border-ink-100 px-5 py-4"><h3 class="text-sm font-bold text-ink-950">Top 10 Clientes (Vendas)</h3></div>
        <div class="overflow-x-auto">
            <table class="table-modern">
                <thead><tr><th>Cliente</th><th class="text-right">Total</th></tr></thead>
                <tbody>
                    <?php if (!$topClients): ?>
                        <tr><td colspan="2" class="text-center text-ink-400">Sem dados (verifique client_id / total).</td></tr>
                    <?php else: foreach ($topClients as $r): ?>
                        <tr>
                            <td class="font-medium text-ink-950"><?= htmlspecialchars($r['nome'] ?? '—') ?></td>
                            <td class="text-right tabular-nums"><?= brl((float)$r['total']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card overflow-hidden">
        <div class="border-b border-ink-100 px-5 py-4"><h3 class="text-sm font-bold text-ink-950">Top 10 Fornecedores (Custo)</h3></div>
        <div class="overflow-x-auto">
            <table class="table-modern">
                <thead><tr><th>Fornecedor</th><th class="text-right">Custo</th></tr></thead>
                <tbody>
                    <?php if (!$topSuppliers): ?>
                        <tr><td colspan="2" class="text-center text-ink-400">Sem dados (verifique supplier_id / supplier_total).</td></tr>
                    <?php else: foreach ($topSuppliers as $r): ?>
                        <tr>
                            <td class="font-medium text-ink-950"><?= htmlspecialchars($r['nome'] ?? '—') ?></td>
                            <td class="text-right tabular-nums"><?= brl((float)$r['custo']) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card overflow-hidden">
        <div class="border-b border-ink-100 px-5 py-4"><h3 class="text-sm font-bold text-ink-950">Top 10 Faturas (Lucro)</h3></div>
        <div class="overflow-x-auto">
            <table class="table-modern">
                <thead><tr><th>Fatura</th><th class="text-right">Total</th><?php if ($canSeeCash): ?><th class="text-right">Lucro</th><?php endif; ?></tr></thead>
                <tbody>
                    <?php if (!$topInvoices): ?>
                        <tr><td colspan="<?= $canSeeCash?3:2 ?>" class="text-center text-ink-400">Sem dados (verifique invoice_number).</td></tr>
                    <?php else: foreach ($topInvoices as $r): ?>
                        <tr>
                            <td class="font-mono text-xs"><?= htmlspecialchars($r['numero'] ?? '—') ?></td>
                            <td class="text-right tabular-nums"><?= brl((float)$r['total']) ?></td>
                            <?php if ($canSeeCash): ?>
                                <td class="text-right tabular-nums"><?= brl((float)$r['lucro']) ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Reembolsos por status -->
<div class="card overflow-hidden">
    <div class="border-b border-ink-100 px-5 py-4"><h3 class="text-sm font-bold text-ink-950">Reembolsos por Status (período)</h3></div>
    <div class="overflow-x-auto">
        <table class="table-modern">
            <thead><tr><th>Status</th><th class="text-right">Total</th></tr></thead>
            <tbody>
                <?php if (!$refAmount): ?>
                    <tr><td colspan="2" class="text-center text-ink-400">Tabela refunds não tem coluna de valor (amount/valor/total).</td></tr>
                <?php elseif (!$refundByStatus): ?>
                    <tr><td colspan="2" class="text-center text-ink-400">Sem reembolsos no período.</td></tr>
                <?php else: foreach ($refundByStatus as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['status'] ?? '—') ?></td>
                        <td class="text-right tabular-nums"><?= brl((float)$r['total']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($role === 'superadmin'): ?>
<!-- Debug: visível apenas para superadmin -->
<details class="card mt-4">
    <summary class="cursor-pointer border-b border-ink-100 px-5 py-4 text-sm font-bold text-ink-950">Debug — colunas detectadas</summary>
    <div class="grid grid-cols-1 gap-6 p-5 md:grid-cols-2">
        <div>
            <p class="mb-2 text-xs font-bold uppercase tracking-wider text-ink-400">Invoices</p>
            <div class="space-y-1 font-mono text-xs text-ink-600">
                <div>date: <?= htmlspecialchars($invDate ?? '—') ?></div>
                <div>total: <?= htmlspecialchars($invTotal ?? '—') ?></div>
                <div>paid: <?= htmlspecialchars($invPaid ?? '—') ?></div>
                <div>due: <?= htmlspecialchars($invDue ?? '—') ?></div>
                <div>profit: <?= htmlspecialchars($invProfit ?? '—') ?></div>
                <div>supplier: <?= htmlspecialchars($invSupplier ?? '—') ?></div>
                <div>fees: <?= htmlspecialchars($invFees ?? '—') ?></div>
                <div>status: <?= htmlspecialchars($invStatus ?? '—') ?></div>
            </div>
        </div>
        <div>
            <p class="mb-2 text-xs font-bold uppercase tracking-wider text-ink-400">Refunds</p>
            <div class="space-y-1 font-mono text-xs text-ink-600">
                <div>date: <?= htmlspecialchars($refDate ?? '—') ?></div>
                <div>status: <?= htmlspecialchars($refStatus ?? '—') ?></div>
                <div>amount: <?= htmlspecialchars($refAmount ?? '—') ?></div>
            </div>
        </div>
    </div>
</details>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
  const labels = <?= json_encode($months, JSON_UNESCAPED_UNICODE) ?>;

  const sales  = <?= json_encode(array_values($seriesSales), JSON_UNESCAPED_UNICODE) ?>;
  const paid   = <?= json_encode(array_values($seriesPaid), JSON_UNESCAPED_UNICODE) ?>;
  const profit = <?= json_encode(array_values($seriesProfit), JSON_UNESCAPED_UNICODE) ?>;

  const BRAND = '#6273f2', EMERALD = '#10b981', CYAN = '#06b6d4', INK = '#525a73';

  // Vendas x Recebidos
  const ctxSales = document.getElementById('chartSales');
  if (window.Chart && ctxSales) {
    new Chart(ctxSales, {
      type: 'line',
      data: {
        labels,
        datasets: [
          { label: 'Vendas', data: sales, tension: 0.25, borderColor: BRAND, backgroundColor: BRAND + '1a', fill: true },
          { label: 'Recebido', data: paid, tension: 0.25, borderColor: EMERALD, backgroundColor: EMERALD + '1a', fill: true }
        ]
      },
      options: {
        responsive: true,
        plugins: { legend: { position: 'bottom', labels: { usePointStyle: true } } },
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
        datasets: [{ label: 'Lucro', data: profit, backgroundColor: CYAN + '99', borderColor: CYAN, borderWidth: 1, borderRadius: 6 }]
      },
      options: {
        responsive: true,
        plugins: { legend: { position: 'bottom', labels: { usePointStyle: true } } },
        scales: {
          y: { ticks: { callback: v => (Number(v)||0).toLocaleString('pt-BR', { style:'currency', currency:'BRL' }) } }
        }
      }
    });
  }
</script>
<?php
$body = ob_get_clean();
require __DIR__ . '/../inc/layout.php';