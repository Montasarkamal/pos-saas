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

function settings_icon(string $name): string {
    $icons = [
        'company'   => '<path d="M3 21h18"></path><path d="M5 21V7l8-4v18"></path><path d="M19 21V11l-6-4"></path><line x1="9" y1="9" x2="9.01" y2="9"></line><line x1="9" y1="12" x2="9.01" y2="12"></line><line x1="9" y1="15" x2="9.01" y2="15"></line><line x1="9" y1="18" x2="9.01" y2="18"></line>',
        'shield'    => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>',
        'backup'    => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line>',
        'list'      => '<line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line>',
        'users'     => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path>',
        'info'      => '<circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line>',
        'table'     => '<rect x="3" y="3" width="18" height="18" rx="2"></rect><line x1="3" y1="9" x2="21" y2="9"></line><line x1="3" y1="15" x2="21" y2="15"></line><line x1="9" y1="3" x2="9" y2="21"></line>',
        'schema'    => '<circle cx="12" cy="5" r="2.5"></circle><circle cx="5" cy="19" r="2.5"></circle><circle cx="19" cy="19" r="2.5"></circle><line x1="10.5" y1="7" x2="6.5" y2="16.5"></line><line x1="13.5" y1="7" x2="17.5" y2="16.5"></line>',
        'dump'      => '<ellipse cx="12" cy="5" rx="9" ry="3"></ellipse><path d="M3 5v14a9 3 0 0 0 18 0V5"></path><path d="M3 12a9 3 0 0 0 18 0"></path>',
        'columns'   => '<rect x="3" y="3" width="18" height="18" rx="2"></rect><line x1="12" y1="3" x2="12" y2="21"></line>',
    ];
    $body = $icons[$name] ?? $icons['info'];
    return '<svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
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
ob_start();
?>
<!-- Header -->
<div class="mb-6">
    <h2 class="text-xl font-bold text-ink-950">Configurações</h2>
    <p class="mt-0.5 text-sm text-ink-500">Dados da empresa, listas, usuários, backup e rotinas administrativas.</p>
</div>

<!-- Hero -->
<div class="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
    <div class="card p-6">
        <p class="stat-label">Empresa ativa</p>
        <h3 class="mt-1 text-xl font-bold text-ink-950"><?= h($agencyDisplay['name']) ?></h3>
        <p class="mt-1 text-sm text-ink-500">
            <?= h($agencyDisplay['legal_name']) ?>
            <?php if ($agencyDisplay['cnpj'] !== ''): ?> · CNPJ <?= h($agencyDisplay['cnpj']) ?><?php endif; ?>
        </p>
        <p class="text-sm text-ink-500">
            <?= h($agencyDisplay['email']) ?>
            <?php if ($agencyDisplay['phone'] !== ''): ?> · <?= h($agencyDisplay['phone']) ?><?php endif; ?>
            <?php if ($agencyDisplay['city_uf'] !== ''): ?> · <?= h($agencyDisplay['city_uf']) ?><?php endif; ?>
        </p>
        <div class="mt-4">
            <a class="btn-primary" href="/settings/company.php">Editar dados da empresa</a>
        </div>
    </div>

    <div class="card p-6">
        <p class="stat-label mb-3">Resumo</p>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div class="rounded-xl bg-ink-50 p-4">
                <p class="text-xs font-medium text-ink-400">Usuários</p>
                <p class="stat-value"><?= (int)$stats['users'] ?></p>
            </div>
            <div class="rounded-xl bg-ink-50 p-4">
                <p class="text-xs font-medium text-ink-400">Itens de listas</p>
                <p class="stat-value"><?= (int)$stats['lists'] ?></p>
            </div>
            <div class="rounded-xl bg-ink-50 p-4">
                <p class="text-xs font-medium text-ink-400">Empresas</p>
                <p class="stat-value"><?= (int)$stats['company'] ?></p>
            </div>
        </div>
    </div>
</div>

<?php
function settings_action_card(string $href, string $icon, string $title, string $desc, bool $blank = false): void {
    $target = $blank ? ' target="_blank" rel="noopener"' : '';
    ?>
    <a href="<?= htmlspecialchars($href) ?>"<?= $target ?> class="group flex gap-4 rounded-2xl border border-ink-200 bg-white p-5 transition hover:-translate-y-0.5 hover:border-brand-300 hover:shadow-[0_10px_24px_rgba(15,23,42,0.08)]">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-600 transition group-hover:bg-brand-100">
            <?= settings_icon($icon) ?>
        </span>
        <span>
            <span class="block text-sm font-bold text-ink-950"><?= htmlspecialchars($title) ?></span>
            <span class="mt-0.5 block text-sm leading-relaxed text-ink-500"><?= htmlspecialchars($desc) ?></span>
        </span>
    </a>
    <?php
}
?>

<div class="mb-3 flex items-center gap-2">
    <h3 class="text-sm font-bold text-ink-950">Gestão</h3>
    <div class="h-px flex-1 bg-ink-100"></div>
</div>
<div class="mb-5 grid grid-cols-1 gap-4 md:grid-cols-2">
    <?php if (is_superadmin()): ?>
        <?php settings_action_card('/master/dashboard.php', 'shield', 'Master dashboard', 'Visão global do sistema, saúde da base, empresas, alertas e ferramentas administrativas.'); ?>
    <?php endif; ?>
    <?php settings_action_card('/settings/company.php', 'company', 'Dados da empresa', 'Razão social, nome fantasia, CNPJ, endereço, contatos, dados bancários e imagens.'); ?>
    <?php settings_action_card('/settings/backup.php', 'backup', 'Backup', 'Gerar uma cópia SQL da base local para restauração ou arquivo.'); ?>
    <?php settings_action_card('/settings/lists.php', 'list', 'Atualizar listas', 'Classes, bagagens e companhias aéreas usadas nas vendas.'); ?>
    <?php settings_action_card('/users/index.php', 'users', 'Gestão de usuários', 'Criar, editar, ativar, desativar e controlar acessos da equipe.'); ?>
    <?php settings_action_card('/settings/about.php', 'info', 'Sobre o aplicativo', 'Versão atual ' . $stats['version'] . ', ambiente local e informações técnicas.'); ?>
</div>

<div class="mb-3 flex items-center gap-2">
    <h3 class="text-sm font-bold text-ink-950">Ferramentas Técnicas</h3>
    <div class="h-px flex-1 bg-ink-100"></div>
</div>
<div class="mb-5 grid grid-cols-1 gap-4 md:grid-cols-2">
    <?php settings_action_card('/debug/db_viewer.php', 'table', 'Database Viewer', 'Exibe tabelas, colunas, contagem de registros e primeiras linhas de cada tabela.'); ?>
    <?php settings_action_card('/debug/db_structure.php', 'schema', 'DB Structure', 'Exibe a estrutura da base: nomes das colunas, tipos, chaves e presença de agency_id.'); ?>
    <?php settings_action_card('/debug/db_full_dump.php', 'dump', 'Full DB Dump', 'Exibe todos os dados das tabelas no navegador. Ferramenta sensível, recomenda-se uso rápido.'); ?>
    <?php settings_action_card('/debug/invoices_cols.php', 'columns', 'Invoices Columns', 'Exibe as colunas da tabela invoices para conferir os campos exigidos pelas vendas.'); ?>
</div>

<?php
$body = ob_get_clean();
require __DIR__ . '/../inc/layout.php';