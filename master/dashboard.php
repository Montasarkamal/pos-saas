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
require_once __DIR__ . '/../inc/header.php';
?>

<style>
.master-grid {
  display: grid;
  gap: 1rem;
}
.master-kpis {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: .85rem;
}
.master-kpi {
  background: #fff;
  border: 1px solid #dbe3ee;
  border-radius: 8px;
  padding: 1rem;
}
.master-kpi-label {
  color: #64748b;
  font-size: .8rem;
  margin-bottom: .2rem;
}
.master-kpi-value {
  font-size: 1.35rem;
  font-weight: 800;
  color: #0f172a;
}
.master-layout {
  display: grid;
  grid-template-columns: minmax(0, 1.3fr) minmax(320px, .7fr);
  gap: 1rem;
}
.master-tools {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: .75rem;
}
.master-tool {
  display: flex;
  gap: .8rem;
  align-items: flex-start;
  padding: .95rem;
  border: 1px solid #dbe3ee;
  border-radius: 8px;
  background: #fff;
  text-decoration: none;
  color: inherit;
}
.master-tool:hover { text-decoration: none; border-color: #206bc4; }
.master-mini {
  color: #64748b;
  font-size: .85rem;
}
@media (max-width: 1200px) {
  .master-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .master-layout { grid-template-columns: 1fr; }
}
@media (max-width: 767px) {
  .master-kpis, .master-tools { grid-template-columns: 1fr; }
}
</style>

<div class="page-header d-print-none mb-3">
  <div class="row align-items-center">
    <div class="col">
      <div class="page-pretitle">Superadmin</div>
      <h2 class="page-title">Master Dashboard</h2>
      <div class="text-muted small">Visão geral do sistema inteiro, não de uma empresa específica.</div>
    </div>
    <div class="col-auto ms-auto">
      <div class="btn-list">
        <a class="btn" href="/settings/index.php"><i class="ti ti-settings me-1"></i> Configurações</a>
        <a class="btn btn-primary" href="/settings/backup.php"><i class="ti ti-database-export me-1"></i> Backup</a>
      </div>
    </div>
  </div>
</div>

<div class="master-grid">
  <div class="master-kpis">
    <div class="master-kpi"><div class="master-kpi-label">Empresas</div><div class="master-kpi-value"><?= (int)$systemStats['agencies'] ?></div></div>
    <div class="master-kpi"><div class="master-kpi-label">Usuários</div><div class="master-kpi-value"><?= (int)$systemStats['users'] ?></div></div>
    <div class="master-kpi"><div class="master-kpi-label">Clientes</div><div class="master-kpi-value"><?= (int)$systemStats['clients'] ?></div></div>
    <div class="master-kpi"><div class="master-kpi-label">Fornecedores</div><div class="master-kpi-value"><?= (int)$systemStats['suppliers'] ?></div></div>
    <div class="master-kpi"><div class="master-kpi-label">Vendas</div><div class="master-kpi-value"><?= (int)$systemStats['sales'] ?></div></div>
    <div class="master-kpi"><div class="master-kpi-label">Reembolsos</div><div class="master-kpi-value"><?= (int)$systemStats['refunds'] ?></div></div>
    <div class="master-kpi"><div class="master-kpi-label">Total vendido</div><div class="master-kpi-value"><?= brl($systemStats['sales_total']) ?></div></div>
    <div class="master-kpi"><div class="master-kpi-label">Alertas</div><div class="master-kpi-value"><?= count($alerts) ?></div></div>
  </div>

  <div class="master-layout">
    <div class="card">
      <div class="card-header"><h3 class="card-title">Empresas</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Empresa</th>
              <th>Contato</th>
              <th>Usuários</th>
              <th>Clientes</th>
              <th>Vendas</th>
              <th>Última venda</th>
              <th class="text-end">Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($agencies as $agency): ?>
              <tr>
                <td><?= (int)$agency['id'] ?></td>
                <td>
                  <div class="fw-semibold"><?= h($agency['agency_name']) ?></div>
                  <?php if (!empty($agency['cnpj'])): ?><div class="master-mini"><?= h((string)$agency['cnpj']) ?></div><?php endif; ?>
                </td>
                <td><?= h((string)($agency['email'] ?? '—')) ?></td>
                <td><?= (int)$agency['users_count'] ?></td>
                <td><?= (int)$agency['clients_count'] ?></td>
                <td><?= (int)$agency['sales_count'] ?></td>
                <td><?= ymd_to_br((string)($agency['last_sale_at'] ?? '')) ?></td>
                <td class="text-end">
                  <div class="btn-list justify-content-end flex-nowrap">
                    <a class="btn btn-sm" href="/settings/company.php">Empresa</a>
                    <a class="btn btn-sm" href="/users/index.php">Usuários</a>
                    <a class="btn btn-sm" href="/settings/backup.php">Backup</a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if ($agencies === []): ?>
              <tr><td colspan="8" class="text-center text-muted p-4">Nenhuma empresa encontrada.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="d-grid gap-3">
      <div class="card">
        <div class="card-header"><h3 class="card-title">Saúde do sistema</h3></div>
        <div class="list-group list-group-flush">
          <?php foreach ($healthChecks as $check): ?>
            <div class="list-group-item d-flex justify-content-between align-items-center">
              <span><?= h($check['label']) ?></span>
              <span class="badge <?= $check['ok'] ? 'bg-success-lt text-success' : 'bg-danger-lt text-danger' ?>">
                <?= $check['ok'] ? 'OK' : 'Falha' ?>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h3 class="card-title">Ferramentas</h3></div>
        <div class="card-body">
          <div class="master-tools">
            <a class="master-tool" href="/settings/backup.php"><i class="ti ti-database-export"></i><span><strong>Backup</strong><div class="master-mini">Exportar, importar e limpar dados.</div></span></a>
            <a class="master-tool" href="/settings/company.php"><i class="ti ti-building-skyscraper"></i><span><strong>Empresa</strong><div class="master-mini">Dados da empresa ativa.</div></span></a>
            <a class="master-tool" href="/settings/lists.php"><i class="ti ti-list-details"></i><span><strong>Listas</strong><div class="master-mini">Classes, bagagens e companhias.</div></span></a>
            <a class="master-tool" href="/users/index.php"><i class="ti ti-users-cog"></i><span><strong>Usuários</strong><div class="master-mini">Controle de acesso e equipe.</div></span></a>
            <a class="master-tool" href="/debug/db_viewer.php" target="_blank" rel="noopener"><i class="ti ti-table"></i><span><strong>DB Viewer</strong><div class="master-mini">Inspeção rápida das tabelas.</div></span></a>
            <a class="master-tool" href="/debug/db_full_dump.php" target="_blank" rel="noopener"><i class="ti ti-database-search"></i><span><strong>DB Dump</strong><div class="master-mini">Leitura completa da base atual.</div></span></a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row row-cards">
    <div class="col-lg-4">
      <div class="card">
        <div class="card-header"><h3 class="card-title">Alertas</h3></div>
        <div class="list-group list-group-flush">
          <?php foreach (array_slice($alerts, 0, 10) as $alert): ?>
            <div class="list-group-item text-danger"><?= h($alert) ?></div>
          <?php endforeach; ?>
          <?php if ($alerts === []): ?>
            <div class="list-group-item text-success">Nenhum alerta importante agora.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card">
        <div class="card-header"><h3 class="card-title">Últimos usuários</h3></div>
        <div class="table-responsive">
          <table class="table table-vcenter card-table">
            <tbody>
              <?php foreach ($recentUsers as $user): ?>
                <tr>
                  <td>
                    <div class="fw-semibold"><?= h((string)$user['name']) ?></div>
                    <div class="master-mini"><?= h((string)($user['agency_name'] ?? '—')) ?></div>
                  </td>
                  <td><?= h((string)($user['login'] ?: $user['email'])) ?></td>
                  <td><span class="badge bg-azure-lt"><?= h((string)$user['role']) ?></span></td>
                </tr>
              <?php endforeach; ?>
              <?php if ($recentUsers === []): ?>
                <tr><td colspan="3" class="text-center text-muted p-4">Sem usuários recentes.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card">
        <div class="card-header"><h3 class="card-title">Últimas vendas</h3></div>
        <div class="table-responsive">
          <table class="table table-vcenter card-table">
            <tbody>
              <?php foreach ($recentSales as $sale): ?>
                <tr>
                  <td>
                    <div class="fw-semibold"><?= h((string)($sale['invoice_number'] ?: ('#' . $sale['id']))) ?></div>
                    <div class="master-mini"><?= h((string)($sale['agency_name'] ?? '—')) ?></div>
                  </td>
                  <td><?= brl((float)$sale['total_amount']) ?></td>
                  <td><span class="badge <?= status_badge_class((string)$sale['status']) ?>"><?= h(status_label((string)$sale['status'])) ?></span></td>
                </tr>
              <?php endforeach; ?>
              <?php if ($recentSales === []): ?>
                <tr><td colspan="3" class="text-center text-muted p-4">Sem vendas recentes.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <?php if ($activity !== []): ?>
    <div class="card">
      <div class="card-header"><h3 class="card-title">Atividade recente</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>ID</th><th>Ação</th><th>Usuário</th><th>Tabela</th><th>Registro</th><th>Data</th></tr></thead>
          <tbody>
            <?php foreach ($activity as $row): ?>
              <tr>
                <td><?= (int)($row['id'] ?? 0) ?></td>
                <td><?= h((string)($row['action'] ?? $row['event'] ?? '—')) ?></td>
                <td><?= h((string)($row['user_id'] ?? '—')) ?></td>
                <td><?= h((string)($row['table_name'] ?? $row['entity'] ?? '—')) ?></td>
                <td><?= h((string)($row['record_id'] ?? $row['entity_id'] ?? '—')) ?></td>
                <td><?= ymd_to_br((string)($row['created_at'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../inc/footer.php'; ?>
