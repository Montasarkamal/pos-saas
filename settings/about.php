<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';
require_login();
require_role_admin();

if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$appName = 'KAMALTUR POS';
$appVersion = '6.3';
$releaseDate = '23/04/2026';
$phpVersion = PHP_VERSION;
$serverSoftware = (string)($_SERVER['SERVER_SOFTWARE'] ?? 'PHP Server');
$databaseName = '';

try {
    if (isset($DB_NAME)) {
        $databaseName = (string)$DB_NAME;
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        $databaseName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    }
} catch (Throwable $e) {
    $databaseName = '';
}

$features = [
    'Vendas unificadas com serviços de viagem e serviços adicionais.',
    'Cadastro de clientes, fornecedores e usuários por agência.',
    'Configurações da empresa, listas operacionais e companhias aéreas.',
    'Backup local da base de dados.',
    'Cabeçalho com dados da empresa, usuário ativo e cotação USD/BRL.',
];

$pageTitle = 'Sobre o Aplicativo';
require_once __DIR__ . '/../inc/header.php';
?>

<style>
.about-hero {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 1.25rem;
  align-items: center;
  padding: 1.5rem;
  border: 1px solid #dbe5f2;
  border-radius: 8px;
  background: #fff;
  box-shadow: 0 10px 28px rgba(15, 23, 42, .05);
}
.about-mark {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 72px;
  height: 72px;
  border-radius: 8px;
  background: #eff6ff;
  color: #2563eb;
  font-size: 2.2rem;
}
.about-version {
  display: inline-flex;
  align-items: center;
  gap: .5rem;
  padding: .45rem .7rem;
  border: 1px solid #bfdbfe;
  border-radius: 999px;
  background: #eff6ff;
  color: #1d4ed8;
  font-weight: 800;
}
.about-info-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 1rem;
}
.about-info-item {
  padding: 1rem;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  background: #fff;
}
.about-info-item span {
  display: block;
  color: #64748b;
  font-size: .76rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .04em;
  margin-bottom: .35rem;
}
.about-info-item strong {
  color: #0f172a;
  font-size: 1rem;
}
.about-feature-list {
  display: grid;
  gap: .75rem;
  margin: 0;
  padding: 0;
  list-style: none;
}
.about-feature-list li {
  display: flex;
  gap: .65rem;
  align-items: flex-start;
  padding: .85rem;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  background: #fff;
}
.about-feature-list i {
  color: #16a34a;
  margin-top: .12rem;
}
@media (max-width: 768px) {
  .about-hero,
  .about-info-grid { grid-template-columns: 1fr; }
}
</style>

<div class="page-header d-print-none mb-3">
  <div class="row align-items-center">
    <div class="col">
      <h2 class="page-title">Sobre o Aplicativo</h2>
      <div class="text-muted small">Informações da instalação atual e versão do sistema.</div>
    </div>
    <div class="col-auto ms-auto">
      <a href="/settings/index.php" class="btn">
        <i class="ti ti-arrow-left"></i>Voltar
      </a>
    </div>
  </div>
</div>

<div class="about-hero mb-3">
  <div>
    <div class="about-version mb-3">
      <i class="ti ti-tag"></i>
      Versão <?= h($appVersion) ?>
    </div>
    <h1 class="mb-2"><?= h($appName) ?></h1>
    <div class="text-muted">
      Sistema local de gestão de vendas para agência de turismo, com clientes, fornecedores, vendas, recibos, vouchers, listas operacionais e configurações administrativas.
    </div>
  </div>
  <div class="about-mark" aria-hidden="true">
    <i class="ti ti-plane"></i>
  </div>
</div>

<div class="about-info-grid mb-3">
  <div class="about-info-item">
    <span>Última versão</span>
    <strong><?= h($appVersion) ?></strong>
  </div>
  <div class="about-info-item">
    <span>Data do release</span>
    <strong><?= h($releaseDate) ?></strong>
  </div>
  <div class="about-info-item">
    <span>Ambiente</span>
    <strong>Local</strong>
  </div>
  <div class="about-info-item">
    <span>PHP</span>
    <strong><?= h($phpVersion) ?></strong>
  </div>
  <div class="about-info-item">
    <span>Servidor</span>
    <strong><?= h($serverSoftware) ?></strong>
  </div>
  <div class="about-info-item">
    <span>Base de dados</span>
    <strong><?= h($databaseName !== '' ? $databaseName : 'Não identificada') ?></strong>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <h3 class="card-title">Recursos principais</h3>
  </div>
  <div class="card-body">
    <ul class="about-feature-list">
      <?php foreach ($features as $feature): ?>
        <li>
          <i class="ti ti-circle-check"></i>
          <span><?= h($feature) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>

<?php require_once __DIR__ . '/../inc/footer.php'; ?>
