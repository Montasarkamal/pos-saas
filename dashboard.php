<?php
// dashboard.php — Versão Profissional Evoluída (PT-BR)
require_once __DIR__ . '/inc/db.php'; 
require_once __DIR__ . '/inc/auth.php';
require_login();
if (is_superadmin()) {
    header('Location: /master/dashboard.php');
    exit;
}

require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/metrics.php';

$pageTitle = 'Visão Geral';
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

require __DIR__ . '/inc/header.php';
?>

<div class="page-header d-print-none mb-4">
    <div class="row g-2 align-items-center">
        <div class="col">
            <div class="page-pretitle">Sistema de Gestão</div>
            <h2 class="page-title">Painel de Controle</h2>
        </div>
        <div class="col-auto ms-auto">
            <div class="btn-list">
                <a href="/sales/create.php" class="btn btn-primary d-none d-sm-inline-block">
                    <i class="ti ti-plus me-2"></i> Nova Venda
                </a>
            </div>
        </div>
    </div>
</div>

<div class="page-body">
    <div class="container-xl">
        
        <div class="row row-cards mb-4">
            <div class="col-sm-6 col-lg-3">
                <div class="card card-sm">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-auto"><span class="bg-blue text-white avatar"><i class="ti ti-currency-dollar"></i></span></div>
                            <div class="col">
                                <div class="font-weight-medium">Vendas do Mês</div>
                                <div class="text-muted"><?= brl($month_sales) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card card-sm">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-auto"><span class="bg-green text-white avatar"><i class="ti ti-check"></i></span></div>
                            <div class="col">
                                <div class="font-weight-medium">Total Recebido</div>
                                <div class="text-muted text-success"><?= brl($month_paid) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card card-sm">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-auto"><span class="bg-red text-white avatar"><i class="ti ti-clock"></i></span></div>
                            <div class="col">
                                <div class="font-weight-medium">Pendente</div>
                                <div class="text-muted text-danger"><?= brl($month_unpaid) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card card-sm">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-auto"><span class="bg-purple text-white avatar"><i class="ti ti-trending-up"></i></span></div>
                            <div class="col">
                                <div class="font-weight-medium">Lucro Líquido</div>
                                <div class="text-muted"><?= brl($month_profit) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row row-cards">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-body">
                        <h3 class="card-title">Análise Financeira Mensal</h3>
                        <div id="chart-sales" style="min-height: 250px;"></div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card">
                    <div class="card-body">
                        <h3 class="card-title">Status de Reembolsos</h3>
                        <div id="chart-refunds" style="min-height: 250px;"></div>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Últimas Operações</h3></div>
                    <div class="table-responsive">
                        <table class="table card-table table-vcenter">
                            <thead><tr><th>Cliente</th><th>Valor</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php foreach($recent as $r): ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['client_name']) ?></td>
                                    <td class="text-muted"><?= brl((float)$r['total_amount']) ?></td>
                                    <td><span class="badge <?= status_badge_class($r['status']) ?>"><?= htmlspecialchars(status_label($r['status'])) ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if(empty($recent)): ?>
                                    <tr><td colspan="3" class="text-center">Nenhum registro encontrado.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Viagens Próximas</h3></div>
                    <div class="list-group list-group-flush">
                        <?php foreach($upcoming as $u): ?>
                        <div class="list-group-item">
                            <div class="row align-items-center">
                                <div class="col-auto"><span class="badge bg-azure"></span></div>
                                <div class="col text-truncate">
                                    <a href="#" class="text-body d-block"><?= htmlspecialchars($u['client_name']) ?></a>
                                    <small class="d-block text-muted text-truncate mt-n1">Embarque: <?= date('d/m/Y', strtotime($u['travel_date'])) ?></small>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if(empty($upcoming)): ?>
                            <div class="list-group-item text-center">Nenhuma viagem agendada.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script>
    document.addEventListener("DOMContentLoaded", function () {
        // Gráfico de Barras: Vendas vs Recebido vs Pendente
        window.ApexCharts && (new ApexCharts(document.getElementById('chart-sales'), {
            chart: { type: "bar", height: 250, toolbar: {show: false} },
            series: [{ 
                name: "Valor (R$)", 
                data: [<?= (float)$month_sales ?>, <?= (float)$month_paid ?>, <?= (float)$month_unpaid ?>] 
            }],
            xaxis: { categories: ["Vendas Totais", "Total Pago", "Pendente"] },
            colors: ['#206bc4', '#2fb344', '#d63939'],
            plotOptions: { bar: { borderRadius: 4, columnWidth: '50%' } }
        })).render();

        // Gráfico de Pizza: Reembolsos
        window.ApexCharts && (new ApexCharts(document.getElementById('chart-refunds'), {
            chart: { type: "donut", height: 250 },
            series: [<?= (int)$refunds['processed'] ?>, <?= (int)$refunds['pending'] ?>],
            labels: ["Finalizados", "Pendentes"],
            colors: ['#2fb344', '#f1a417'],
            legend: { position: 'bottom' }
        })).render();
    });
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
