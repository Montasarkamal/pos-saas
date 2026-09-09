<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';
require_login();
require_role_admin();
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/company.php';
require_once __DIR__ . '/../inc/list_store.php';

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function setting_company_field(array $agency, string $field, ?string $fallback = null): string {
    $value = trim((string)($agency[$field] ?? ''));
    if ($value !== '') return $value;
    return trim((string)($fallback ?? ''));
}

$agency = null;
try {
    $agencySelect = ['id', 'email', 'phone', 'cnpj', 'city', 'uf'];
    if (has_column($pdo, 'agencies', 'name')) $agencySelect[] = 'name';
    if (has_column($pdo, 'agencies', 'legal_name')) $agencySelect[] = 'legal_name';
    if (has_column($pdo, 'agencies', 'fantasy_name')) $agencySelect[] = 'fantasy_name';
    $agencySelectSql = implode(', ', array_unique($agencySelect));
    if (is_superadmin()) {
        $st = $pdo->query("SELECT {$agencySelectSql} FROM agencies ORDER BY id ASC LIMIT 1");
    } else {
        $st = $pdo->prepare("SELECT {$agencySelectSql} FROM agencies WHERE id=? LIMIT 1");
        $st->execute([agency_id()]);
    }
    $agency = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[SETTINGS_INDEX_AGENCY] ' . $e->getMessage());
}

$stats = [
    'users' => 0,
    'lists' => 0,
    'company' => 0,
    'version' => '6.3',
];

try {
    $lists = list_store_all();
    $stats['lists'] = count($lists['classes'] ?? []) + count($lists['baggage'] ?? []) + count($lists['airlines'] ?? []);
} catch (Throwable $e) {
    error_log('[SETTINGS_INDEX_LISTS] ' . $e->getMessage());
}

$agencyDisplay = [
    'name' => $agency ? setting_company_field($agency, 'fantasy_name', setting_company_field($agency, 'name', COMPANY_NAME)) : COMPANY_NAME,
    'legal_name' => $agency ? setting_company_field($agency, 'legal_name', setting_company_field($agency, 'name', COMPANY_NAME)) : COMPANY_NAME,
    'email' => $agency ? setting_company_field($agency, 'email', COMPANY_EMAIL) : COMPANY_EMAIL,
    'phone' => $agency ? setting_company_field($agency, 'phone', COMPANY_WHATS) : COMPANY_WHATS,
    'cnpj' => $agency ? setting_company_field($agency, 'cnpj') : '',
    'city_uf' => $agency ? trim(setting_company_field($agency, 'city') . ' ' . setting_company_field($agency, 'uf')) : '',
];

try {
    if (is_superadmin()) {
        $st = $pdo->query("SELECT COUNT(*) FROM users");
        $stats['company'] = (int)$pdo->query("SELECT COUNT(*) FROM agencies")->fetchColumn();
    } else {
        $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE agency_id=?");
        $st->execute([agency_id()]);
        $stats['company'] = $agency ? 1 : 0;
    }
    $stats['users'] = (int)$st->fetchColumn();
} catch (Throwable $e) {
    error_log('[SETTINGS_INDEX_USERS] ' . $e->getMessage());
}

$pageTitle = 'Configurações';
require_once __DIR__ . '/../inc/header.php';
?>

<style>
.settings-hero {
  display: grid;
  gap: 1rem;
  grid-template-columns: 1.15fr .85fr;
  align-items: stretch;
}
.settings-panel {
  border: 1px solid #dbe3ee;
  border-radius: 8px;
  background: #fff;
}
.settings-panel-body { padding: 1.25rem; }
.settings-actions-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 1rem;
}
.settings-action {
  display: flex;
  gap: .9rem;
  min-height: 108px;
  padding: .95rem 1rem;
  border: 1px solid #dbe3ee;
  border-radius: 8px;
  background: #fff;
  color: inherit;
  text-decoration: none;
  transition: border-color .15s ease, transform .15s ease, box-shadow .15s ease;
}
.settings-action:hover {
  border-color: #206bc4;
  box-shadow: 0 10px 24px rgba(15, 23, 42, .08);
  transform: translateY(-1px);
  text-decoration: none;
}
.settings-icon {
  display: none;
}
.settings-action h3 { margin: 0 0 .3rem; font-size: .98rem; }
.settings-action p { margin: 0; color: #64748b; line-height: 1.4; }
.settings-action > span:last-child {
  display: grid;
  gap: .18rem;
  align-content: start;
}
.settings-kpi {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: .75rem;
}
.settings-kpi-item {
  padding: 1rem;
  border-radius: 8px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
}
.settings-subtitle {
  margin: 1.5rem 0 .75rem;
  color: #64748b;
  font-size: .82rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .05em;
}
@media (max-width: 992px) {
  .settings-hero,
  .settings-actions-grid { grid-template-columns: 1fr; }
}
@media (max-width: 576px) {
  .settings-kpi { grid-template-columns: 1fr; }
}
</style>

<div class="page-header d-print-none mb-3">
  <div class="row align-items-center">
    <div class="col">
      <h2 class="page-title">Configurações</h2>
      <div class="text-muted small">Dados da empresa, listas, usuários, backup e rotinas administrativas.</div>
    </div>
  </div>
</div>

<div class="settings-hero mb-3">
  <div class="settings-panel">
    <div class="settings-panel-body">
      <div class="subheader mb-2">Empresa ativa</div>
      <h1 class="h2 mb-2"><?= h($agencyDisplay['name']) ?></h1>
      <div class="text-muted">
        <?= h($agencyDisplay['legal_name']) ?>
        <?php if ($agencyDisplay['cnpj'] !== ''): ?> · CNPJ <?= h($agencyDisplay['cnpj']) ?><?php endif; ?>
      </div>
      <div class="text-muted mt-1">
        <?= h($agencyDisplay['email']) ?>
        <?php if ($agencyDisplay['phone'] !== ''): ?> · <?= h($agencyDisplay['phone']) ?><?php endif; ?>
        <?php if ($agencyDisplay['city_uf'] !== ''): ?> · <?= h($agencyDisplay['city_uf']) ?><?php endif; ?>
      </div>
      <div class="mt-3">
        <a class="btn btn-primary" href="/settings/company.php">
          <i class="ti ti-building-skyscraper me-1"></i> Editar dados da empresa
        </a>
      </div>
    </div>
  </div>

  <div class="settings-panel">
    <div class="settings-panel-body">
      <div class="subheader mb-3">Resumo</div>
      <div class="settings-kpi">
        <div class="settings-kpi-item">
          <div class="text-muted small">Usuários</div>
          <div class="h2 m-0"><?= (int)$stats['users'] ?></div>
        </div>
        <div class="settings-kpi-item">
          <div class="text-muted small">Itens de listas</div>
          <div class="h2 m-0"><?= (int)$stats['lists'] ?></div>
        </div>
        <div class="settings-kpi-item">
          <div class="text-muted small">Empresas</div>
          <div class="h2 m-0"><?= (int)$stats['company'] ?></div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="settings-actions-grid">
  <?php if (is_superadmin()): ?>
  <a class="settings-action" href="/master/dashboard.php">
    <span class="settings-icon"><i class="ti ti-shield-star"></i></span>
    <span>
      <h3>Master dashboard</h3>
      <p>Visão global do sistema, saúde da base, empresas, alertas e ferramentas administrativas.</p>
    </span>
  </a>
  <?php endif; ?>

  <a class="settings-action" href="/settings/company.php">
    <span class="settings-icon"><i class="ti ti-building-skyscraper"></i></span>
    <span>
      <h3>Dados da empresa</h3>
      <p>Razão social, nome fantasia, CNPJ, endereço, contatos, dados bancários e imagens.</p>
    </span>
  </a>

  <a class="settings-action" href="/settings/backup.php">
    <span class="settings-icon"><i class="ti ti-database-export"></i></span>
    <span>
      <h3>Backup</h3>
      <p>Gerar uma cópia SQL da base local para restauração ou arquivo.</p>
    </span>
  </a>

  <a class="settings-action" href="/settings/lists.php">
    <span class="settings-icon"><i class="ti ti-list-details"></i></span>
    <span>
      <h3>Atualizar listas</h3>
      <p>Classes, bagagens e companhias aéreas usadas nas vendas.</p>
    </span>
  </a>

  <a class="settings-action" href="/users/index.php">
    <span class="settings-icon"><i class="ti ti-users-cog"></i></span>
    <span>
      <h3>Gestão de usuários</h3>
      <p>Criar, editar, ativar, desativar e controlar acessos da equipe.</p>
    </span>
  </a>

  <a class="settings-action" href="/settings/about.php">
    <span class="settings-icon"><i class="ti ti-info-circle"></i></span>
    <span>
      <h3>Sobre o aplicativo</h3>
      <p>Versão atual <?= h($stats['version']) ?>, ambiente local e informações técnicas do sistema.</p>
    </span>
  </a>
</div>

<div class="settings-subtitle">Ferramentas Técnicas</div>

<div class="settings-actions-grid">
  <a class="settings-action" href="/debug/db_viewer.php" target="_blank" rel="noopener">
    <span class="settings-icon"><i class="ti ti-table"></i></span>
    <span>
      <h3>Database Viewer</h3>
      <p>يعرض الجداول، الأعمدة، عدد السجلات، وأول صفوف من كل جدول لمراجعة البيانات بسرعة.</p>
    </span>
  </a>

  <a class="settings-action" href="/debug/db_structure.php" target="_blank" rel="noopener">
    <span class="settings-icon"><i class="ti ti-schema"></i></span>
    <span>
      <h3>DB Structure</h3>
      <p>يعرض هيكل قاعدة البيانات فقط: أسماء الأعمدة، أنواعها، المفاتيح، وهل يوجد <code>agency_id</code>.</p>
    </span>
  </a>

  <a class="settings-action" href="/debug/db_full_dump.php" target="_blank" rel="noopener">
    <span class="settings-icon"><i class="ti ti-database-search"></i></span>
    <span>
      <h3>Full DB Dump</h3>
      <p>يعرض كل بيانات الجداول بالكامل داخل المتصفح. أداة حساسة ومناسبة للمراجعة السريعة فقط.</p>
    </span>
  </a>

  <a class="settings-action" href="/debug/invoices_cols.php" target="_blank" rel="noopener">
    <span class="settings-icon"><i class="ti ti-columns-3"></i></span>
    <span>
      <h3>Invoices Columns</h3>
      <p>يعرض أعمدة جدول <code>invoices</code> فقط للتأكد من وجود الحقول المطلوبة الخاصة بالمبيعات.</p>
    </span>
  </a>
</div>

<?php require_once __DIR__ . '/../inc/footer.php'; ?>
