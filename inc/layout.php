<?php
/**
 * KAMALTUR POS — modern app layout (Phase 2)
 *
 * Server-rendered shell with a dark sidebar + topbar, built on the
 * Tailwind design system (frontend/src/app.css). Converted pages render
 * their content into `$body` and require this file at the end.
 *
 * Expected variables set by the page before including this file:
 *   $pageTitle (string)
 *   $body      (string) — page content HTML
 */

declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/company.php';
require_once __DIR__ . '/ui.php';

$uName  = $_SESSION['name'] ?? null;
$uRole  = $_SESSION['role'] ?? null;
$pageTitle = $pageTitle ?? 'KAMALTUR POS';

$agencyHeader = [];
if (!empty($_SESSION['agency_id']) && isset($pdo) && $pdo instanceof PDO) {
    try {
        $stAgencyHeader = $pdo->prepare("SELECT name, fantasy_name, legal_name, logo_path FROM agencies WHERE id=? LIMIT 1");
        $stAgencyHeader->execute([(int)$_SESSION['agency_id']]);
        $agencyHeader = $stAgencyHeader->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[LAYOUT_AGENCY] ' . $e->getMessage());
    }
}
$headerCompanyName = trim((string)($agencyHeader['fantasy_name'] ?? ''));
if ($headerCompanyName === '') $headerCompanyName = trim((string)($agencyHeader['name'] ?? ''));
if ($headerCompanyName === '') $headerCompanyName = COMPANY_NAME;
$headerCompanyLogo = trim((string)($agencyHeader['logo_path'] ?? ''));
if ($headerCompanyLogo === '') $headerCompanyLogo = '/assets/img/kamaltur.png';

$headerRoleLabel = $uRole === 'superadmin' ? 'MASTER' : strtoupper((string)$uRole);
$dashboardHref   = $uRole === 'superadmin' ? '/master/dashboard.php' : '/dashboard.php';

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$navActive = [
    'dashboard'  => preg_match('#^/(dashboard\.php|master/dashboard\.php)?$#', $requestPath) === 1,
    'sales'      => preg_match('#^/sales/#', $requestPath) === 1,
    'reports'    => preg_match('#^/reports/#', $requestPath) === 1,
    'refunds'    => preg_match('#^/refunds/#', $requestPath) === 1,
    'clients'    => preg_match('#^/clients/#', $requestPath) === 1,
    'suppliers'  => preg_match('#^/suppliers/#', $requestPath) === 1,
    'settings'   => preg_match('#^/settings/#', $requestPath) === 1,
];
$canSettings = in_array((string)$uRole, ['admin', 'superadmin'], true);

function layout_icon(string $name, string $class = 'h-5 w-5'): string
{
    $icons = [
        'grid'    => '<path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z"></path>',
        'invoice' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><path d="M14 2v6h6"></path><path d="M8 13h8"></path><path d="M8 17h8"></path><path d="M8 9h2"></path>',
        'chart'   => '<path d="M3 3v18h18"></path><path d="M7 13l3-3 3 3 5-6"></path>',
        'refund'  => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path><path d="M12 7v5l4 2"></path>',
        'users'   => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
        'store'   => '<path d="M3 9l1-5h16l1 5"></path><path d="M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"></path><path d="M5 12v9h14v-9"></path><path d="M9 21v-6h6v6"></path>',
        'gear'    => '<circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>',
        'plus'    => '<path d="M12 5v14"></path><path d="M5 12h14"></path>',
        'logout'  => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><path d="M16 17l5-5-5-5"></path><path d="M21 12H9"></path>',
        'menu'    => '<path d="M3 6h18"></path><path d="M3 12h18"></path><path d="M3 18h18"></path>',
        'clock'   => '<circle cx="12" cy="12" r="10"></circle><path d="M12 6v6l4 2"></path>',
    ];
    $body = $icons[$name] ?? $icons['grid'];
    return '<svg class="' . htmlspecialchars($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($pageTitle) ?> · KAMALTUR POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="light">
<?= vite_head() ?>
</head>
<body class="bg-ink-50">

<div x-data="{ sidebarOpen: false }" class="min-h-svh lg:grid lg:grid-cols-[16rem_minmax(0,1fr)]">

    <!-- ================= Sidebar (mobile overlay) ================= -->
    <div class="fixed inset-0 z-40 bg-ink-950/60 lg:hidden"
         x-show="sidebarOpen" x-cloak x-transition.opacity
         @click="sidebarOpen = false" aria-hidden="true"></div>

    <aside class="fixed inset-y-0 left-0 z-50 flex w-64 flex-col bg-ink-950 text-ink-200 transition-transform lg:sticky lg:top-0 lg:h-svh lg:translate-x-0 lg:transition-none"
           :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'">

        <!-- Brand -->
        <div class="flex h-16 shrink-0 items-center gap-3 border-b border-white/10 px-5">
            <img src="<?= htmlspecialchars($headerCompanyLogo) ?>" alt="<?= htmlspecialchars($headerCompanyName) ?>" class="h-9 w-auto">
            <div class="min-w-0">
                <p class="truncate text-sm font-bold text-white"><?= htmlspecialchars($headerCompanyName) ?></p>
                <p class="text-[11px] uppercase tracking-widest text-ink-400">POS</p>
            </div>
        </div>

        <!-- Nav -->
        <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-6" aria-label="Menu principal">
            <div>
                <p class="mb-2 px-3 text-[10px] font-bold uppercase tracking-[0.18em] text-ink-500">Gestão</p>
                <ul class="space-y-1">
                    <li><a href="<?= htmlspecialchars($dashboardHref) ?>" class="sidebar-link <?= $navActive['dashboard'] ? 'sidebar-link-active' : '' ?>"><?= layout_icon('grid') ?><span>Painel</span></a></li>
                    <li><a href="/sales/index.php" class="sidebar-link <?= $navActive['sales'] ? 'sidebar-link-active' : '' ?>"><?= layout_icon('invoice') ?><span>Vendas</span></a></li>
                    <li><a href="/reports/index.php" class="sidebar-link <?= $navActive['reports'] ? 'sidebar-link-active' : '' ?>"><?= layout_icon('chart') ?><span>Relatórios</span></a></li>
                    <li><a href="/refunds/index.php" class="sidebar-link <?= $navActive['refunds'] ? 'sidebar-link-active' : '' ?>"><?= layout_icon('refund') ?><span>Reembolsos</span></a></li>
                </ul>
            </div>
            <div>
                <p class="mb-2 px-3 text-[10px] font-bold uppercase tracking-[0.18em] text-ink-500">Cadastros</p>
                <ul class="space-y-1">
                    <li><a href="/clients/index.php" class="sidebar-link <?= $navActive['clients'] ? 'sidebar-link-active' : '' ?>"><?= layout_icon('users') ?><span>Clientes</span></a></li>
                    <li><a href="/suppliers/index.php" class="sidebar-link <?= $navActive['suppliers'] ? 'sidebar-link-active' : '' ?>"><?= layout_icon('store') ?><span>Fornecedores</span></a></li>
                    <?php if ($canSettings): ?>
                    <li><a href="/settings/index.php" class="sidebar-link <?= $navActive['settings'] ? 'sidebar-link-active' : '' ?>"><?= layout_icon('gear') ?><span>Configurações</span></a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </nav>

        <!-- User + logout -->
        <div class="shrink-0 border-t border-white/10 p-4">
            <div class="flex items-center gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-600 text-xs font-bold text-white">
                    <?= htmlspecialchars(mb_strtoupper((string)mb_substr((string)($uName ?? '?'), 0, 2, 'UTF-8'), 'UTF-8')) ?>
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-white"><?= htmlspecialchars((string)$uName) ?></p>
                    <p class="text-[11px] text-ink-400"><?= htmlspecialchars($headerRoleLabel) ?></p>
                </div>
            </div>
            <a href="/profile.php" class="mt-3 block rounded-lg px-3 py-2 text-sm text-ink-300 hover:bg-white/5 hover:text-white">Editar perfil</a>
            <form action="/logout.php" method="post" class="mt-1">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-red-300 transition hover:bg-red-500/10 hover:text-red-200">
                    <?= layout_icon('logout', 'h-4 w-4') ?><span>Sair</span>
                </button>
            </form>
        </div>
    </aside>

    <!-- ================= Main column ================= -->
    <div class="min-w-0">
        <!-- Topbar -->
        <header class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-ink-200/70 bg-white/85 px-4 backdrop-blur sm:px-6">
            <button type="button" class="rounded-lg p-2 text-ink-600 hover:bg-ink-100 lg:hidden"
                    @click="sidebarOpen = true" aria-label="Abrir menu">
                <?= layout_icon('menu') ?>
            </button>

            <div class="min-w-0">
                <p class="text-[11px] font-medium uppercase tracking-wider text-ink-400">KAMALTUR POS</p>
                <h1 class="truncate text-base font-bold text-ink-950"><?= htmlspecialchars($pageTitle) ?></h1>
            </div>

            <div class="ml-auto flex items-center gap-2" x-data="{ now: '' }"
                 x-init="now = new Date().toLocaleString('pt-BR'); setInterval(() => now = new Date().toLocaleString('pt-BR'), 1000)">
                <span class="hidden items-center gap-1.5 rounded-full border border-ink-200 bg-ink-50 px-3 py-1.5 text-xs font-medium text-ink-600 sm:inline-flex">
                    <?= layout_icon('clock', 'h-3.5 w-3.5') ?>
                    <span x-text="now"></span>
                </span>
            </div>
        </header>

        <!-- Content -->
        <main class="px-4 py-6 sm:px-6 lg:px-8">
            <?= $body ?>
        </main>
    </div>
</div>

</body>
</html>