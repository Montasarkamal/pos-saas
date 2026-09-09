<?php
// inc/header.php — Unified compact top navigation
require_once __DIR__ . '/session.php';

$uName = $_SESSION['name'] ?? null;
$uRole = $_SESSION['role'] ?? null;
$pageTitle = $pageTitle ?? 'KAMALTUR POS';

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/metrics.php';
require_once __DIR__ . '/company.php';

$todayBR = date('d/m/Y H:i');
$agencyHeader = [];
if (!empty($_SESSION['agency_id']) && isset($pdo) && $pdo instanceof PDO) {
  try {
    $stAgencyHeader = $pdo->prepare("SELECT name, fantasy_name, legal_name, logo_path FROM agencies WHERE id=? LIMIT 1");
    $stAgencyHeader->execute([(int)$_SESSION['agency_id']]);
    $agencyHeader = $stAgencyHeader->fetch(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    error_log('[HEADER_AGENCY] ' . $e->getMessage());
  }
}

$headerCompanyName = trim((string)($agencyHeader['fantasy_name'] ?? ''));
if ($headerCompanyName === '') $headerCompanyName = trim((string)($agencyHeader['name'] ?? ''));
if ($headerCompanyName === '') $headerCompanyName = COMPANY_NAME;
$headerCompanyLogo = trim((string)($agencyHeader['logo_path'] ?? ''));
if ($headerCompanyLogo === '') $headerCompanyLogo = '/assets/img/kamaltur.png';
$headerRoleLabel = $uRole === 'superadmin' ? 'MASTER' : strtoupper((string)$uRole);
$dashboardHref = $uRole === 'superadmin' ? '/master/dashboard.php' : '/dashboard.php';
$premiumCssPath = __DIR__ . '/../assets/css/premium.css';
$premiumCssVersion = is_file($premiumCssPath) ? (string)filemtime($premiumCssPath) : '1';
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$navActive = [
  'dashboard' => preg_match('#^/(dashboard\.php|master/dashboard\.php)?$#', $requestPath) === 1,
  'sales' => preg_match('#^/sales/#', $requestPath) === 1,
  'reports' => preg_match('#^/reports/#', $requestPath) === 1,
  'refunds' => preg_match('#^/refunds/#', $requestPath) === 1,
  'clients' => preg_match('#^/clients/#', $requestPath) === 1,
  'suppliers' => preg_match('#^/suppliers/#', $requestPath) === 1,
  'settings' => preg_match('#^/settings/#', $requestPath) === 1,
];
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
  <script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
  <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>

  <!-- Tabler Core -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.4.0/dist/css/tabler.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tabler-icons@3.34.1/iconfont/tabler-icons.min.css">
  
  <!-- Premium Styles -->
  <link rel="stylesheet" href="/assets/css/premium.css?v=<?= htmlspecialchars($premiumCssVersion) ?>">
  <title><?= htmlspecialchars($pageTitle) ?></title>
</head>
<body>
  <div class="page">
    
    <!-- Unified Top Navbar -->
    <header class="navbar navbar-expand-lg navbar-light app-header d-print-none sticky-top">
      <div class="container-xl app-header-inner">
        <a class="header-company" href="<?= htmlspecialchars($dashboardHref) ?>" aria-label="Dashboard">
          <span class="header-company-logo">
            <img src="<?= htmlspecialchars($headerCompanyLogo) ?>" alt="<?= htmlspecialchars($headerCompanyName) ?>">
          </span>
          <span class="header-company-text">
            <span class="header-company-name" title="<?= htmlspecialchars($headerCompanyName) ?>"><?= htmlspecialchars($headerCompanyName) ?></span>
          </span>
        </a>

        <button class="navbar-toggler ms-auto" type="button" data-bs-toggle="collapse" data-bs-target="#navbar-menu" aria-controls="navbar-menu" aria-expanded="false" aria-label="Alternar navegação">
          <span class="navbar-toggler-icon"></span>
        </button>

        <div class="navbar-nav flex-row order-lg-last ms-auto">
          <div class="nav-item d-none d-lg-flex me-2">
            <div class="header-market">
              <span class="header-pill" title="Data e hora atual">
                <i class="ti ti-clock"></i>
                <span id="headerClock"><?= htmlspecialchars($todayBR) ?></span>
              </span>
              <span class="header-pill" title="Cotação PTAX Banco Central do Brasil">
                <i class="ti ti-currency-dollar"></i>
                <span id="headerUsdRate">USD ...</span>
              </span>
            </div>
          </div>
          <?php if ($uName): ?>
          <div class="nav-item dropdown">
            <a href="#" class="header-user" data-bs-toggle="dropdown">
              <span class="avatar avatar-sm bg-indigo text-white"><?= htmlspecialchars(mb_substr((string)$uName, 0, 2, 'UTF-8')) ?></span>
              <div class="header-user-text">
                <div><?= htmlspecialchars($uName) ?></div>
                <div><?= htmlspecialchars($headerRoleLabel) ?></div>
              </div>
            </a>
            <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
              <a href="/profile.php" class="dropdown-item"><i class="ti ti-user-circle me-2"></i>Perfil</a>
              <form action="/logout.php" method="post" class="m-0">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="dropdown-item text-danger">Sair</button>
              </form>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <div class="collapse navbar-collapse app-header-nav" id="navbar-menu">
          <div class="d-flex flex-column flex-lg-row flex-fill align-items-stretch align-items-lg-center">
            <ul class="navbar-nav">
              <li class="nav-item">
                <a class="nav-link <?= $navActive['dashboard'] ? 'active' : '' ?>" href="<?= htmlspecialchars($dashboardHref) ?>" >
                  <span class="nav-link-icon"><i class="ti ti-dashboard"></i></span>
                  <span class="nav-link-title">Painel</span>
                </a>
              </li>
              <li class="nav-item">
                <a class="nav-link <?= $navActive['sales'] ? 'active' : '' ?>" href="/sales/index.php" >
                  <span class="nav-link-icon"><i class="ti ti-file-invoice"></i></span>
                  <span class="nav-link-title">Vendas</span>
                </a>
              </li>
              <li class="nav-item">
                <a class="nav-link <?= $navActive['reports'] ? 'active' : '' ?>" href="/reports/index.php" >
                  <span class="nav-link-icon"><i class="ti ti-chart-bar"></i></span>
                  <span class="nav-link-title">Relatórios</span>
                </a>
              </li>
              <li class="nav-item">
                <a class="nav-link <?= $navActive['refunds'] ? 'active' : '' ?>" href="/refunds/index.php" >
                  <span class="nav-link-icon"><i class="ti ti-receipt-refund"></i></span>
                  <span class="nav-link-title">Reembolsos</span>
                </a>
              </li>
              <li class="nav-item">
                <a class="nav-link <?= $navActive['clients'] ? 'active' : '' ?>" href="/clients/index.php" >
                  <span class="nav-link-icon"><i class="ti ti-users"></i></span>
                  <span class="nav-link-title">Clientes</span>
                </a>
              </li>
              <li class="nav-item">
                <a class="nav-link <?= $navActive['suppliers'] ? 'active' : '' ?>" href="/suppliers/index.php" >
                  <span class="nav-link-icon"><i class="ti ti-building-store"></i></span>
                  <span class="nav-link-title">Fornecedores</span>
                </a>
              </li>
              
              <?php if (in_array((string)$uRole, ['admin', 'superadmin'], true)): ?>
              <li class="nav-item">
                <a class="nav-link <?= $navActive['settings'] ? 'active' : '' ?>" href="/settings/index.php" >
                  <span class="nav-link-icon"><i class="ti ti-settings"></i></span>
                  <span class="nav-link-title">Configurações</span>
                </a>
              </li>
              <?php endif; ?>
            </ul>
          </div>
        </div>

      </div>
    </header>

    <div class="page-wrapper">
      <div class="page-body">
        <div class="container-xl">
