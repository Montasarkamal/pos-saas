<?php
// dashboard.php — modern layout (Phase 2)
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/auth.php';
require_login();
if (is_superadmin()) {
    header('Location: /master/dashboard.php');
    exit;
}

require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/metrics.php';

$pageTitle = 'Painel de Controle';
$agency_id = (int)agency_id();

// 1. Busca de dados das Métricas
$tot = totals_overall($pdo);
$refunds = refunds_stats($pdo);
[$from, $to] = month_window();

$month_sales  = sales_month($pdo, $from, $to);
$month_paid   = paid_month($pdo, $from, $to);
$month_profit = profit_month($pdo, $from, $to);
$month_unpaid = due_month($month_sales, $month_paid);

// 2. Busca das últimas 5 vendas
$recent = [];
if (has_table($pdo, 'invoices')) {
    try {
        $clientSelect = has_table($pdo, 'clients') ? 'c.name as client_name' : 'NULL as client_name';
        $clientJoin = has_table($pdo, 'clients') ? 'LEFT JOIN clients c ON c.id = i.client_id' : '';
        $st = $pdo->prepare("SELECT i.*, {$clientSelect} FROM invoices i {$clientJoin} WHERE i.agency_id = ? ORDER BY i.id DESC LIMIT 5");
        $st->execute([$agency_id]);
        $recent = $st->fetchAll();
    } catch (Throwable $e) {
        error_log('[DASHBOARD_RECENT] ' . $e->getMessage());
    }
}

// 3. Busca das próximas viagens
$upcoming = [];
if (has_table($pdo, 'invoices')) {
    try {
        $clientSelect = has_table($pdo, 'clients') ? 'c.name as client_name' : 'NULL as client_name';
        $clientJoin = has_table($pdo, 'clients') ? 'LEFT JOIN clients c ON c.id = i.client_id' : '';
        $stU = $pdo->prepare("SELECT i.*, {$clientSelect} FROM invoices i {$clientJoin} WHERE i.agency_id = ? AND i.travel_date >= CURDATE() ORDER BY i.travel_date ASC LIMIT 5");
        $stU->execute([$agency_id]);
        $upcoming = $stU->fetchAll();
    } catch (Throwable $e) {
        error_log('[DASHBOARD_UPCOMING] ' . $e->getMessage());
    }
}

/* local helpers for the modern UI */
function stat_status_badge(string $status): string
{
    $s = mb_strtolower($status);
    if (str_contains($s, 'pago') && !str_contains($s, 'nao')) return 'bg-emerald-100 text-emerald-700';
    if (str_contains($s, 'nao pago')) return 'bg-red-100 text-red-700';
    if (str_contains($s, 'parcial')) return 'bg-amber-100 text-amber-700';
    if (str_contains($s, 'cancel')) return 'bg-ink-100 text-ink-600';
    return 'bg-ink-100 text-ink-600';
}

function stat_status_label(string $status): string
{
    $s = mb_strtolower(trim($status));
    $map = [
        'pago' => 'Pago',
        'nao pago' => 'Não pago',
        'parcial' => 'Parcial',
        'cancelado' => 'Cancelado',
    ];
    return $map[$s] ?? (trim($status) !== '' ? $status : '—');
}

/* values for the monthly financial bars (Vendas / Pago / Pendente) */
$barMax = max(1.0, (float)$month_sales, (float)$month_paid, (float)$month_unpaid);
$bars = [
    ['label' => 'Vendas',    'value' => (float)$month_sales,  'cls' => 'bg-brand-500'],
    ['label' => 'Recebido',  'value' => (float)$month_paid,   'cls' => 'bg-emerald-500'],
    ['label' => 'Pendente',  'value' => (float)$month_unpaid, 'cls' => 'bg-red-400'],
];

/* refund donut */
$refProcessed = (int)$refunds['processed'];
$refPending   = (int)$refunds['pending'];
$refTotal     = max(1, $refProcessed + $refPending);
$refDonePct   = 100 * $refProcessed / $refTotal;
$circumference = 2 * M_PI * 40; // r=40
$processDash   = ($refDonePct / 100) * $circumference;
$pendingDash   = $circumference - $processDash;

ob_start();
?>
<!-- Page header -->
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-xl font-bold text-ink-950">Painel de Controle</h2>
        <p class="mt-0.5 text-sm text-ink-500">Visão geral da operação — mês de <?= date('m/Y') ?></p>
    </div>
    <a href="/sales/create.php" class="btn-primary">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"></path><path d="M5 12h14"></path></svg>
        <span>Nova Venda</span>
    </a>
</div>

<!-- KPI cards -->
<div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <div class="card p-5">
        <div class="flex items-center gap-4">
            <span class="stat-chip bg-brand-500">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><path d="M14 2v6h6"></path><path d="M8 13h8"></path><path d="M8 17h8"></path></svg>
            </span>
            <div>
                <p class="stat-label">Vendas do Mês</p>
                <p class="stat-value"><?= brl($month_sales) ?></p>
            </div>
        </div>
    </div>
    <div class="card p-5">
        <div class="flex items-center gap-4">
            <span class="stat-chip bg-emerald-500">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path></svg>
            </span>
            <div>
                <p class="stat-label">Total Recebido</p>
                <p class="stat-value text-emerald-600"><?= brl($month_paid) ?></p>
            </div>
        </div>
    </div>
    <div class="card p-5">
        <div class="flex items-center gap-4">
            <span class="stat-chip bg-red-400">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M12 6v6l4 2"></path></svg>
            </span>
            <div>
                <p class="stat-label">Pendente</p>
                <p class="stat-value text-red-500"><?= brl($month_unpaid) ?></p>
            </div>
        </div>
    </div>
    <div class="card p-5">
        <div class="flex items-center gap-4">
            <span class="stat-chip bg-cyan-500">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 7 13.5 15.5 8.5 10.5 2 17"></path><path d="M16 7h6v6"></path></svg>
            </span>
            <div>
                <p class="stat-label">Lucro Líquido</p>
                <p class="stat-value"><?= brl($month_profit) ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Charts + lists -->
<div class="grid grid-cols-1 gap-4 lg:grid-cols-12">
    <!-- Monthly financial analysis -->
    <div class="card p-5 lg:col-span-8">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-sm font-bold text-ink-950">Análise Financeira Mensal</h3>
            <span class="rounded-full bg-ink-100 px-2.5 py-1 text-[11px] font-medium text-ink-500">R$</span>
        </div>
        <div class="flex h-52 items-end gap-6 px-2">
            <?php foreach ($bars as $b): ?>
            <div class="flex h-full flex-1 flex-col justify-end">
                <p class="mb-2 text-center text-sm font-bold text-ink-900 tabular-nums"><?= brl($b['value']) ?></p>
                <div class="flex h-36 items-end overflow-hidden rounded-xl bg-ink-100/70">
                    <div class="w-full rounded-xl <?= $b['cls'] ?>"
                         style="height: <?= max(2, round(100 * $b['value'] / $barMax)) ?>%"></div>
                </div>
                <p class="mt-2 text-center text-xs font-medium text-ink-500"><?= htmlspecialchars($b['label']) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Refund status donut -->
    <div class="card p-5 lg:col-span-4">
        <h3 class="mb-2 text-sm font-bold text-ink-950">Status de Reembolsos</h3>
        <div class="flex flex-col items-center py-2">
            <svg width="140" height="140" viewBox="0 0 100 100" role="img" aria-label="Reembolsos">
                <circle cx="50" cy="50" r="40" fill="none" stroke="#eceef4" stroke-width="12"></circle>
                <circle cx="50" cy="50" r="40" fill="none" stroke="#10b981" stroke-width="12"
                        stroke-linecap="round" stroke-dasharray="<?= $processDash ?> <?= $pendingDash ?>"
                        stroke-dashoffset="0" transform="rotate(-90 50 50)"></circle>
                <text x="50" y="46" text-anchor="middle" class="fill-ink-950" style="font-size:16px;font-weight:700;"><?= $refProcessed + $refPending ?></text>
                <text x="50" y="62" text-anchor="middle" class="fill-ink-400" style="font-size:8px;">solicitações</text>
            </svg>
            <div class="mt-4 w-full space-y-2">
                <div class="flex items-center justify-between text-sm">
                    <span class="flex items-center gap-2 text-ink-600"><span class="h-2.5 w-2.5 rounded-full bg-emerald-500"></span>Finalizados</span>
                    <span class="font-semibold text-ink-900 tabular-nums"><?= $refProcessed ?></span>
                </div>
                <div class="flex items-center justify-between text-sm">
                    <span class="flex items-center gap-2 text-ink-600"><span class="h-2.5 w-2.5 rounded-full bg-amber-400"></span>Pendentes</span>
                    <span class="font-semibold text-ink-900 tabular-nums"><?= $refPending ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent operations -->
    <div class="card lg:col-span-7">
        <div class="border-b border-ink-100 px-5 py-4">
            <h3 class="text-sm font-bold text-ink-950">Últimas Operações</h3>
        </div>
        <div class="overflow-x-auto p-2">
            <?php if (empty($recent)): ?>
                <p class="px-4 py-8 text-center text-sm text-ink-400">Nenhum registro encontrado.</p>
            <?php else: ?>
            <table class="table-modern">
                <thead>
                    <tr><th>Cliente</th><th>Valor</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($recent as $r): ?>
                    <tr>
                        <td class="font-medium text-ink-950"><?= htmlspecialchars((string)($r['client_name'] ?? '—')) ?></td>
                        <td class="tabular-nums text-ink-700"><?= brl((float)$r['total_amount']) ?></td>
                        <td><span class="inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold <?= stat_status_badge((string)$r['status']) ?>"><?= htmlspecialchars(stat_status_label((string)$r['status'])) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Upcoming trips -->
    <div class="card lg:col-span-5">
        <div class="border-b border-ink-100 px-5 py-4">
            <h3 class="text-sm font-bold text-ink-950">Viagens Próximas</h3>
        </div>
        <div class="p-2">
            <?php if (empty($upcoming)): ?>
                <p class="px-4 py-8 text-center text-sm text-ink-400">Nenhuma viagem agendada.</p>
            <?php else: ?>
            <ul class="divide-y divide-ink-100">
                <?php foreach ($upcoming as $u): ?>
                <li class="flex items-center gap-3 px-3 py-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 17H3l1-4h16l1 4h-2"></path><path d="M3 13l1.5-5h15l1.5 5"></path><path d="M18 9c-1.5 1-2.5 1-4 1s-2.5 0-4-1"></path></svg>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-ink-950"><?= htmlspecialchars((string)($u['client_name'] ?? '—')) ?></p>
                        <p class="truncate text-xs text-ink-500">Embarque: <?= date('d/m/Y', strtotime((string)$u['travel_date'])) ?></p>
                    </div>
                    <?php if (!empty($u['pnr_code'])): ?>
                    <span class="rounded-md bg-ink-100 px-2 py-1 font-mono text-[11px] text-ink-600"><?= htmlspecialchars((string)$u['pnr_code']) ?></span>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php

$body = ob_get_clean();
require __DIR__ . '/inc/layout.php';