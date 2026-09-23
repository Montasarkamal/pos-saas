<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';
require_login();
require_admin();
if (!is_superadmin()) {
    header('Location: /dashboard.php');
    exit;
}

require_once __DIR__ . '/../inc/helpers.php';

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function scalar_count(PDO $pdo, string $table): int {
    if (!has_table($pdo, $table)) {
        return 0;
    }
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    } catch (Throwable $e) {
        error_log('[MASTER_COUNT_' . strtoupper($table) . '] ' . $e->getMessage());
        return 0;
    }
}

function scalar_sum(PDO $pdo, string $table, string $column): float {
    if (!has_table($pdo, $table) || !has_column($pdo, $table, $column)) {
        return 0.0;
    }
    try {
        return (float)$pdo->query("SELECT COALESCE(SUM(`{$column}`),0) FROM `{$table}`")->fetchColumn();
    } catch (Throwable $e) {
        error_log('[MASTER_SUM_' . strtoupper($table) . '_' . strtoupper($column) . '] ' . $e->getMessage());
        return 0.0;
    }
}

function agency_label_sql(PDO $pdo): string {
    $parts = [];
    if (has_column($pdo, 'agencies', 'fantasy_name')) $parts[] = "NULLIF(a.fantasy_name,'')";
    if (has_column($pdo, 'agencies', 'name')) $parts[] = "NULLIF(a.name,'')";
    if (has_column($pdo, 'agencies', 'legal_name')) $parts[] = "NULLIF(a.legal_name,'')";
    return $parts ? 'COALESCE(' . implode(', ', $parts) . ", CONCAT('Empresa #', a.id))" : "CONCAT('Empresa #', a.id)";
}

$systemStats = [
    'agencies' => scalar_count($pdo, 'agencies'),
    'users' => scalar_count($pdo, 'users'),
    'clients' => scalar_count($pdo, 'clients'),
    'suppliers' => scalar_count($pdo, 'suppliers'),
    'sales' => scalar_count($pdo, 'invoices'),
    'refunds' => scalar_count($pdo, 'refunds'),
    'sales_total' => scalar_sum($pdo, 'invoices', 'total_amount'),
];

$agencies = [];
if (has_table($pdo, 'agencies')) {
    try {
        $labelSql = agency_label_sql($pdo);
        $query = "
            SELECT
                a.id,
                {$labelSql} AS agency_name,
                " . (has_column($pdo, 'agencies', 'email') ? "a.email" : "NULL") . " AS email,
                " . (has_column($pdo, 'agencies', 'cnpj') ? "a.cnpj" : "NULL") . " AS cnpj,
                " . (has_column($pdo, 'agencies', 'created_at') ? "a.created_at" : "NULL") . " AS created_at,
                " . (has_table($pdo, 'users') && has_column($pdo, 'users', 'agency_id') ? "(SELECT COUNT(*) FROM users u WHERE u.agency_id = a.id)" : "0") . " AS users_count,
                " . (has_table($pdo, 'clients') && has_column($pdo, 'clients', 'agency_id') ? "(SELECT COUNT(*) FROM clients c WHERE c.agency_id = a.id)" : "0") . " AS clients_count,
                " . (has_table($pdo, 'invoices') && has_column($pdo, 'invoices', 'agency_id') ? "(SELECT COUNT(*) FROM invoices i WHERE i.agency_id = a.id)" : "0") . " AS sales_count,
                " . (has_table($pdo, 'invoices') && has_column($pdo, 'invoices', 'agency_id') && has_column($pdo, 'invoices', 'updated_at') ? "(SELECT MAX(i.updated_at) FROM invoices i WHERE i.agency_id = a.id)" : "NULL") . " AS last_sale_at
            FROM agencies a
            ORDER BY a.id ASC
        ";
        $agencies = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[MASTER_AGENCIES] ' . $e->getMessage());
    }
}

$healthChecks = [
    ['label' => 'Agencies', 'ok' => has_table($pdo, 'agencies')],
    ['label' => 'Users', 'ok' => has_table($pdo, 'users')],
    ['label' => 'Clients', 'ok' => has_table($pdo, 'clients')],
    ['label' => 'Suppliers', 'ok' => has_table($pdo, 'suppliers')],
    ['label' => 'Invoices', 'ok' => has_table($pdo, 'invoices')],
    ['label' => 'Master agency #1', 'ok' => false],
    ['label' => 'Master user', 'ok' => false],
];

try {
    if (has_table($pdo, 'agencies')) {
        $st = $pdo->query("SELECT COUNT(*) FROM agencies WHERE id=1");
        $healthChecks[5]['ok'] = (bool)$st->fetchColumn();
    }
    if (has_table($pdo, 'users')) {
        $st = $pdo->query("SELECT COUNT(*) FROM users WHERE agency_id=1 AND role='superadmin' AND LOWER(login)='master' AND is_active=1");
        $healthChecks[6]['ok'] = (bool)$st->fetchColumn();
    }
} catch (Throwable $e) {
    error_log('[MASTER_HEALTH] ' . $e->getMessage());
}

$alerts = [];
foreach ($healthChecks as $check) {
    if (!$check['ok']) {
        $alerts[] = 'Falha de estrutura: ' . $check['label'];
    }
}
foreach ($agencies as $agency) {
    if ((int)$agency['users_count'] === 0) {
        $alerts[] = 'Empresa sem usuários: ' . (string)$agency['agency_name'];
    }
    if ((int)$agency['sales_count'] === 0 && (int)$agency['id'] !== 1) {
        $alerts[] = 'Empresa sem vendas: ' . (string)$agency['agency_name'];
    }
}

$recentUsers = [];
if (has_table($pdo, 'users')) {
    try {
        $labelSql = has_table($pdo, 'agencies') ? agency_label_sql($pdo) : "NULL";
        $agencyJoin = has_table($pdo, 'agencies') ? "LEFT JOIN agencies a ON a.id = u.agency_id" : "";
        $recentUsers = $pdo->query("
            SELECT
                u.id,
                u.name,
                u.email,
                u.login,
                u.role,
                u.created_at,
                {$labelSql} AS agency_name
            FROM users u
            {$agencyJoin}
            ORDER BY u.id DESC
            LIMIT 8
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[MASTER_RECENT_USERS] ' . $e->getMessage());
    }
}

$recentSales = [];
if (has_table($pdo, 'invoices')) {
    try {
        $agencyJoin = has_table($pdo, 'agencies') ? "LEFT JOIN agencies a ON a.id = i.agency_id" : "";
        $labelSql = has_table($pdo, 'agencies') ? agency_label_sql($pdo) : "NULL";
        $recentSales = $pdo->query("
            SELECT
                i.id,
                i.invoice_number,
                " . (has_column($pdo, 'invoices', 'total_amount') ? "i.total_amount" : "0") . " AS total_amount,
                " . (has_column($pdo, 'invoices', 'status') ? "i.status" : "NULL") . " AS status,
                " . (has_column($pdo, 'invoices', 'created_at') ? "i.created_at" : "NULL") . " AS created_at,
                {$labelSql} AS agency_name
            FROM invoices i
            {$agencyJoin}
            ORDER BY i.id DESC
            LIMIT 8
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[MASTER_RECENT_SALES] ' . $e->getMessage());
    }
}

$activity = [];
if (has_table($pdo, 'audit_logs')) {
    try {
        $activity = $pdo->query("SELECT * FROM audit_logs ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[MASTER_ACTIVITY] ' . $e->getMessage());
    }
}

$pageTitle = 'Master Dashboard';
ob_start();
require_once __DIR__ . '/../inc/ui.php';

function master_icon(string $name): string {
    $icons = [
        'backup'    => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line>',
        'company'   => '<path d="M3 21h18"></path><path d="M5 21V7l8-4v18"></path><path d="M19 21V11l-6-4"></path><line x1="9" y1="9" x2="9.01" y2="9"></line><line x1="9" y1="12" x2="9.01" y2="12"></line><line x1="9" y1="15" x2="9.01" y2="15"></line><line x1="9" y1="18" x2="9.01" y2="18"></line>',
        'list'      => '<line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line>',
        'users'     => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
        'table'     => '<rect x="3" y="3" width="18" height="18" rx="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="3" y1="15" x2="21" y2="15"></line><line x1="9" y1="3" x2="9" y2="21"></line>',
        'dump'      => '<ellipse cx="12" cy="5" rx="9" ry="3"></ellipse><path d="M3 5v14a9 3 0 0 0 18 0V5"></path><path d="M3 12a9 3 0 0 0 18 0"></path>',
        'alert'     => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line>',
        'check'     => '<polyline points="20 6 9 17 4 12"></polyline>',
    ];
    $body = $icons[$name] ?? $icons['alert'];
    return '<svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
}
function master_mini_icon(string $name): string {
    return str_replace('h-5 w-5', 'h-4 w-4', master_icon($name));
}
?>
<!-- Header -->
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <p class="text-xs font-bold uppercase tracking-wider text-brand-600">Superadmin</p>
        <h2 class="text-xl font-bold text-ink-950">Master Dashboard</h2>
        <p class="mt-0.5 text-sm text-ink-500">Visão geral do sistema inteiro, não de uma empresa específica.</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <a class="btn-ghost" href="/settings/index.php">Configurações</a>
        <a class="btn-primary" href="/settings/backup.php">Backup</a>
    </div>
</div>

<!-- KPIs -->
<div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
    <div class="card p-4"><p class="stat-label">Empresas</p><p class="stat-value"><?= (int)$systemStats['agencies'] ?></p></div>
    <div class="card p-4"><p class="stat-label">Usuários</p><p class="stat-value"><?= (int)$systemStats['users'] ?></p></div>
    <div class="card p-4"><p class="stat-label">Clientes</p><p class="stat-value"><?= (int)$systemStats['clients'] ?></p></div>
    <div class="card p-4"><p class="stat-label">Fornecedores</p><p class="stat-value"><?= (int)$systemStats['suppliers'] ?></p></div>
    <div class="card p-4"><p class="stat-label">Vendas</p><p class="stat-value"><?= (int)$systemStats['sales'] ?></p></div>
    <div class="card p-4"><p class="stat-label">Reembolsos</p><p class="stat-value"><?= (int)$systemStats['refunds'] ?></p></div>
    <div class="card p-4"><p class="stat-label">Total vendido</p><p class="stat-value"><?= brl($systemStats['sales_total']) ?></p></div>
    <div class="card p-4">
        <p class="stat-label">Alertas</p>
        <p class="stat-value <?= $alerts ? 'text-red-500' : 'text-emerald-600' ?>"><?= count($alerts) ?></p>
    </div>
</div>

<!-- Layout principal: Empresas + (Saúde/Ferramentas) -->
<div class="mb-5 grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1.3fr)_minmax(320px,0.7fr)]">
    <div class="card overflow-hidden">
        <div class="border-b border-ink-100 px-5 py-4">
            <h3 class="text-sm font-bold text-ink-950">Empresas</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="table-modern min-w-[900px]">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Empresa</th>
                        <th>Contato</th>
                        <th class="text-center">Usuários</th>
                        <th class="text-center">Clientes</th>
                        <th class="text-center">Vendas</th>
                        <th>Última venda</th>
                        <th class="text-right">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($agencies as $agency): ?>
                        <tr>
                            <td class="font-mono text-xs"><?= (int)$agency['id'] ?></td>
                            <td>
                                <p class="font-semibold text-ink-950"><?= h($agency['agency_name']) ?></p>
                                <?php if (!empty($agency['cnpj'])): ?>
                                    <p class="text-xs text-ink-400"><?= h((string)$agency['cnpj']) ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="text-ink-600"><?= h((string)($agency['email'] ?? '—')) ?></td>
                            <td class="text-center tabular-nums"><?= (int)$agency['users_count'] ?></td>
                            <td class="text-center tabular-nums"><?= (int)$agency['clients_count'] ?></td>
                            <td class="text-center tabular-nums"><?= (int)$agency['sales_count'] ?></td>
                            <td class="font-mono text-xs"><?= ymd_to_br((string)($agency['last_sale_at'] ?? '')) ?></td>
                            <td>
                                <div class="flex justify-end gap-1.5">
                                    <a class="btn-xs border border-ink-200 bg-white text-ink-700 hover:bg-ink-50" href="/settings/company.php">Empresa</a>
                                    <a class="btn-xs border border-ink-200 bg-white text-ink-700 hover:bg-ink-50" href="/users/index.php">Usuários</a>
                                    <a class="btn-xs border border-ink-200 bg-white text-ink-700 hover:bg-ink-50" href="/settings/backup.php">Backup</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($agencies === []): ?>
                        <tr><td colspan="8" class="px-4 py-8 text-center text-sm text-ink-400">Nenhuma empresa encontrada.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="grid gap-4">
        <div class="card overflow-hidden">
            <div class="border-b border-ink-100 px-5 py-4">
                <h3 class="text-sm font-bold text-ink-950">Saúde do sistema</h3>
            </div>
            <div class="p-5">
                <?php foreach ($healthChecks as $check): ?>
                    <div class="flex items-center justify-between gap-3 border-b border-dashed border-ink-100 py-2.5 last:border-0">
                        <span class="text-sm text-ink-700"><?= h($check['label']) ?></span>
                        <span class="badge-soft <?= $check['ok'] ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700' ?>">
                            <?= $check['ok'] ? 'OK' : 'Falha' ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card overflow-hidden">
            <div class="border-b border-ink-100 px-5 py-4">
                <h3 class="text-sm font-bold text-ink-950">Ferramentas</h3>
            </div>
            <div class="p-5">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <a class="flex items-start gap-3 rounded-xl border border-ink-200 bg-white p-3.5 transition hover:border-brand-300 hover:shadow-sm" href="/settings/backup.php">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><?= master_mini_icon('backup') ?></span>
                        <span>
                            <span class="block text-sm font-bold text-ink-950">Backup</span>
                            <span class="block text-xs text-ink-500">Exportar, importar e limpar dados.</span>
                        </span>
                    </a>
                    <a class="flex items-start gap-3 rounded-xl border border-ink-200 bg-white p-3.5 transition hover:border-brand-300 hover:shadow-sm" href="/settings/company.php">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><?= master_mini_icon('company') ?></span>
                        <span>
                            <span class="block text-sm font-bold text-ink-950">Empresa</span>
                            <span class="block text-xs text-ink-500">Dados da empresa ativa.</span>
                        </span>
                    </a>
                    <a class="flex items-start gap-3 rounded-xl border border-ink-200 bg-white p-3.5 transition hover:border-brand-300 hover:shadow-sm" href="/settings/lists.php">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><?= master_mini_icon('list') ?></span>
                        <span>
                            <span class="block text-sm font-bold text-ink-950">Listas</span>
                            <span class="block text-xs text-ink-500">Classes, bagagens e companhias.</span>
                        </span>
                    </a>
                    <a class="flex items-start gap-3 rounded-xl border border-ink-200 bg-white p-3.5 transition hover:border-brand-300 hover:shadow-sm" href="/users/index.php">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><?= master_mini_icon('users') ?></span>
                        <span>
                            <span class="block text-sm font-bold text-ink-950">Usuários</span>
                            <span class="block text-xs text-ink-500">Controle de acesso e equipe.</span>
                        </span>
                    </a>
                    <a class="flex items-start gap-3 rounded-xl border border-ink-200 bg-white p-3.5 transition hover:border-brand-300 hover:shadow-sm" href="/debug/db_viewer.php" target="_blank" rel="noopener">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><?= master_mini_icon('table') ?></span>
                        <span>
                            <span class="block text-sm font-bold text-ink-950">DB Viewer</span>
                            <span class="block text-xs text-ink-500">Inspeção rápida das tabelas.</span>
                        </span>
                    </a>
                    <a class="flex items-start gap-3 rounded-xl border border-ink-200 bg-white p-3.5 transition hover:border-brand-300 hover:shadow-sm" href="/debug/db_full_dump.php" target="_blank" rel="noopener">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600"><?= master_mini_icon('dump') ?></span>
                        <span>
                            <span class="block text-sm font-bold text-ink-950">DB Dump</span>
                            <span class="block text-xs text-ink-500">Leitura completa da base atual.</span>
                        </span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Linha: Alertas / Usuários / Vendas -->
<div class="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-3">
    <div class="card overflow-hidden">
        <div class="border-b border-ink-100 px-5 py-4">
            <h3 class="text-sm font-bold text-ink-950">Alertas</h3>
        </div>
        <div class="p-5">
            <?php foreach (array_slice($alerts, 0, 10) as $alert): ?>
                <div class="flex items-start gap-2 border-b border-dashed border-ink-100 py-2.5 text-sm text-red-700 last:border-0">
                    <span class="mt-0.5 text-red-500"><?= master_mini_icon('alert') ?></span>
                    <span><?= h($alert) ?></span>
                </div>
            <?php endforeach; ?>
            <?php if ($alerts === []): ?>
                <div class="flex items-start gap-2 text-sm text-emerald-700">
                    <span class="mt-0.5 text-emerald-500"><?= master_mini_icon('check') ?></span>
                    <span>Nenhum alerta importante agora.</span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card overflow-hidden">
        <div class="border-b border-ink-100 px-5 py-4">
            <h3 class="text-sm font-bold text-ink-950">Últimos usuários</h3>
        </div>
        <div class="p-5">
            <?php foreach ($recentUsers as $user): ?>
                <div class="flex items-start justify-between gap-3 border-b border-dashed border-ink-100 py-2.5 last:border-0">
                    <div class="min-w-0">
                        <p class="text-sm font-bold text-ink-950"><?= h((string)$user['name']) ?></p>
                        <p class="truncate text-xs text-ink-400"><?= h((string)($user['agency_name'] ?? '—')) ?></p>
                    </div>
                    <div class="shrink-0 text-right">
                        <p class="font-mono text-xs text-ink-600"><?= h((string)(($user['login'] ?? '') ?: ($user['email'] ?? ''))) ?></p>
                        <span class="badge-soft bg-sky-100 text-sky-700"><?= h((string)$user['role']) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if ($recentUsers === []): ?>
                <p class="px-4 py-8 text-center text-sm text-ink-400">Sem usuários recentes.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card overflow-hidden">
        <div class="border-b border-ink-100 px-5 py-4">
            <h3 class="text-sm font-bold text-ink-950">Últimas vendas</h3>
        </div>
        <div class="p-5">
            <?php foreach ($recentSales as $sale): ?>
                <div class="flex items-center justify-between gap-3 border-b border-dashed border-ink-100 py-2.5 last:border-0">
                    <div class="min-w-0">
                        <p class="font-semibold text-ink-950"><?= h((string)($sale['invoice_number'] ?: ('#' . $sale['id']))) ?></p>
                        <p class="truncate text-xs text-ink-400"><?= h((string)($sale['agency_name'] ?? '—')) ?></p>
                    </div>
                    <div class="shrink-0 text-right">
                        <p class="text-sm font-semibold tabular-nums text-ink-900"><?= brl((float)$sale['total_amount']) ?></p>
                        <span class="<?= ui_status_badge((string)$sale['status']) ?>"><?= h(ui_status_label((string)$sale['status'])) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if ($recentSales === []): ?>
                <p class="px-4 py-8 text-center text-sm text-ink-400">Sem vendas recentes.</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($activity !== []): ?>
    <div class="card overflow-hidden">
        <div class="border-b border-ink-100 px-5 py-4">
            <h3 class="text-sm font-bold text-ink-950">Atividade recente</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="table-modern min-w-[820px]">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Ação</th>
                        <th>Usuário</th>
                        <th>Tabela</th>
                        <th>Registro</th>
                        <th>Data</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activity as $row): ?>
                        <tr>
                            <td class="font-mono text-xs"><?= (int)($row['id'] ?? 0) ?></td>
                            <td><?= h((string)($row['action'] ?? $row['event'] ?? '—')) ?></td>
                            <td class="font-mono text-xs"><?= h((string)($row['user_id'] ?? '—')) ?></td>
                            <td><?= h((string)($row['table_name'] ?? $row['entity'] ?? '—')) ?></td>
                            <td class="font-mono text-xs"><?= h((string)($row['record_id'] ?? $row['entity_id'] ?? '—')) ?></td>
                            <td class="font-mono text-xs"><?= ymd_to_br((string)($row['created_at'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php
$body = ob_get_clean();
require __DIR__ . '/../inc/layout.php';