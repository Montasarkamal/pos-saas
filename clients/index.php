<?php
// clients/index.php — modern layout (Phase 2)
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/ui.php';
require_login();

if (!function_exists('h')) {
    function h($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

function client_digits_only(string $value): string {
    return preg_replace('/\D+/', '', $value) ?? '';
}

function client_date_br(?string $value): string {
    if (!$value) return '—';
    $ts = strtotime($value);
    return $ts ? date('d/m/Y', $ts) : h($value);
}

function client_sort_link(string $key, string $label, string $currentSort, string $currentDir): string {
    $nextDir = ($currentSort === $key && strtolower($currentDir) === 'asc') ? 'desc' : 'asc';
    $params = $_GET;
    $params['sort'] = $key;
    $params['dir'] = $nextDir;
    $icon = '';
    if ($currentSort === $key) {
        $icon = strtolower($currentDir) === 'asc' ? '↑' : '↓';
    }
    $active = $icon !== '' ? ' font-bold text-ink-900' : '';
    return '<a class="inline-flex items-center gap-1 whitespace-nowrap text-ink-500 transition hover:text-ink-900'.$active.'" href="?'.h(http_build_query($params)).'">'.h($label).($icon !== '' ? '<span class="text-brand-600">'.$icon.'</span>' : '').'</a>';
}

function client_type_badge(?string $type): string {
    if ($type === 'pj') {
        return '<span class="badge-soft bg-blue-100 text-blue-700">Empresa</span>';
    }
    return '<span class="badge-soft bg-emerald-100 text-emerald-700">Pessoa</span>';
}

function client_is_incomplete(array $client): bool {
    return trim((string)($client['document'] ?? '')) === ''
        || trim((string)($client['phone'] ?? '')) === ''
        || trim((string)($client['birth_date'] ?? '')) === '';
}

$allowedSort = [
    'id' => 'c.id',
    'name' => 'c.name',
    'document' => 'c.document',
    'phone' => 'c.phone',
    'email' => 'c.email',
    'birth_date' => 'c.birth_date',
    'type' => 'c.client_type',
    'employer' => 'e.name',
    'created' => 'c.created_at',
];

$sortKey = (string)($_GET['sort'] ?? 'created');
$sortSql = $allowedSort[$sortKey] ?? 'c.created_at';
$dir = (isset($_GET['dir']) && strtolower((string)$_GET['dir']) === 'asc') ? 'ASC' : 'DESC';

$q = trim((string)($_GET['q'] ?? ''));
$type = (string)($_GET['type'] ?? '');
$contact = (string)($_GET['contact'] ?? '');
$birth = (string)($_GET['birth'] ?? '');
$employer = trim((string)($_GET['employer'] ?? ''));

$pageTitle = 'Clientes';

if (!has_table($pdo, 'clients')) {
    $token = csrf_token();
    ob_start();
    ?>
    <div class="card p-8 text-center">
        <p class="text-sm font-medium text-amber-700">Tabela de clientes não está disponível na base atual.</p>
    </div>
    <?php
    $body = ob_get_clean();
    require __DIR__ . '/../inc/layout.php';
    exit;
}

[$agencyCondition, $agencyParams] = agency_scope_sql('c.agency_id');
$whereParts = [$agencyCondition];
$params = $agencyParams;

$documentDigits = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(c.document,''),'.',''),'-',''),'/',''),' ',''),'(',''),')','')";
$phoneDigits = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(c.phone,''),'.',''),'-',''),'/',''),' ',''),'(',''),')','')";

if ($q !== '') {
    $like = '%' . $q . '%';
    $searchParts = [
        'c.name LIKE ?',
        'c.document LIKE ?',
        'c.phone LIKE ?',
        'c.email LIKE ?',
        'c.address LIKE ?',
        'c.notes LIKE ?',
        'e.name LIKE ?',
    ];
    array_push($params, $like, $like, $like, $like, $like, $like, $like);

    $digits = client_digits_only($q);
    if ($digits !== '') {
        $digitLike = '%' . $digits . '%';
        $searchParts[] = "$documentDigits LIKE ?";
        $searchParts[] = "$phoneDigits LIKE ?";
        array_push($params, $digitLike, $digitLike);
    }

    $whereParts[] = '(' . implode(' OR ', $searchParts) . ')';
}

if (in_array($type, ['pf', 'pj'], true)) {
    $whereParts[] = 'c.client_type = ?';
    $params[] = $type;
}

if ($contact === 'with_email') {
    $whereParts[] = "COALESCE(NULLIF(TRIM(c.email), ''), '') <> ''";
} elseif ($contact === 'missing_email') {
    $whereParts[] = "COALESCE(NULLIF(TRIM(c.email), ''), '') = ''";
} elseif ($contact === 'with_phone') {
    $whereParts[] = "COALESCE(NULLIF(TRIM(c.phone), ''), '') <> ''";
} elseif ($contact === 'missing_phone') {
    $whereParts[] = "COALESCE(NULLIF(TRIM(c.phone), ''), '') = ''";
}

if ($birth === 'with_birth') {
    $whereParts[] = "c.birth_date IS NOT NULL";
} elseif ($birth === 'missing_birth') {
    $whereParts[] = "c.birth_date IS NULL";
}

if ($employer !== '') {
    $whereParts[] = 'e.name LIKE ?';
    $params[] = '%' . $employer . '%';
}

$whereSql = 'WHERE ' . implode(' AND ', $whereParts);

$countSql = "SELECT COUNT(*) FROM clients c LEFT JOIN clients e ON e.id = c.employer_id AND e.agency_id = c.agency_id $whereSql";
$countSt = $pdo->prepare($countSql);
$countSt->execute($params);
$totalResults = (int)$countSt->fetchColumn();

$statsSt = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(c.client_type = 'pf') AS pf,
        SUM(c.client_type = 'pj') AS pj,
        SUM(COALESCE(NULLIF(TRIM(c.email), ''), '') <> '') AS with_email,
        SUM(COALESCE(NULLIF(TRIM(c.phone), ''), '') <> '') AS with_phone,
        SUM(c.birth_date IS NOT NULL) AS with_birth,
        SUM(
            COALESCE(NULLIF(TRIM(c.document), ''), '') = ''
            OR COALESCE(NULLIF(TRIM(c.phone), ''), '') = ''
            OR c.birth_date IS NULL
        ) AS incomplete
    FROM clients c
    WHERE $agencyCondition
");
$statsSt->execute($agencyParams);
$stats = $statsSt->fetch(PDO::FETCH_ASSOC) ?: [];

$sql = "
    SELECT c.*, e.name AS employer_name
    FROM clients c
    LEFT JOIN clients e ON e.id = c.employer_id AND e.agency_id = c.agency_id
    $whereSql
    ORDER BY $sortSql $dir, c.id DESC
    LIMIT 500
";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$hasFilters = $q !== '' || in_array($type, ['pf', 'pj'], true) || $contact !== '' || $birth !== '' || $employer !== '';
$token = csrf_token();

ob_start();
?>
<!-- Header -->
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-xl font-bold text-ink-950">Clientes</h2>
        <p class="mt-0.5 text-sm text-ink-500">Busca por nome, CPF/CNPJ, telefone, e-mail, endereço e empresa vinculada.</p>
    </div>
    <a href="/clients/create.php" class="btn-primary">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><line x1="19" y1="8" x2="19" y2="14"></line><line x1="22" y1="11" x2="16" y2="11"></line></svg>
        Novo cliente
    </a>
</div>

<!-- KPIs -->
<div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <div class="card p-5">
        <p class="stat-label">Total de clientes</p>
        <p class="stat-value"><?= (int)($stats['total'] ?? 0) ?></p>
    </div>
    <div class="card p-5">
        <p class="stat-label">Pessoas físicas</p>
        <p class="stat-value"><?= (int)($stats['pf'] ?? 0) ?></p>
    </div>
    <div class="card p-5">
        <p class="stat-label">Empresas</p>
        <p class="stat-value"><?= (int)($stats['pj'] ?? 0) ?></p>
    </div>
    <div class="card p-5">
        <p class="stat-label">Com nascimento</p>
        <p class="stat-value"><?= (int)($stats['with_birth'] ?? 0) ?></p>
    </div>
</div>

<!-- Filtros -->
<div class="card mb-5 p-5">
    <form method="get" class="grid grid-cols-1 items-end gap-4 md:grid-cols-2 xl:grid-cols-12">
        <div class="xl:col-span-4">
            <label class="label-field" for="c-q">Busca geral</label>
            <input id="c-q" class="input-field" type="text" name="q" value="<?= h($q) ?>" placeholder="Nome, documento, telefone, e-mail, endereço...">
        </div>
        <div class="xl:col-span-2">
            <label class="label-field" for="c-type">Tipo</label>
            <select id="c-type" class="select-field" name="type">
                <option value="">Todos</option>
                <option value="pf" <?= $type === 'pf' ? 'selected' : '' ?>>Pessoa física</option>
                <option value="pj" <?= $type === 'pj' ? 'selected' : '' ?>>Empresa</option>
            </select>
        </div>
        <div class="xl:col-span-2">
            <label class="label-field" for="c-contact">Contato</label>
            <select id="c-contact" class="select-field" name="contact">
                <option value="">Todos</option>
                <option value="with_email" <?= $contact === 'with_email' ? 'selected' : '' ?>>Com e-mail</option>
                <option value="missing_email" <?= $contact === 'missing_email' ? 'selected' : '' ?>>Sem e-mail</option>
                <option value="with_phone" <?= $contact === 'with_phone' ? 'selected' : '' ?>>Com telefone</option>
                <option value="missing_phone" <?= $contact === 'missing_phone' ? 'selected' : '' ?>>Sem telefone</option>
            </select>
        </div>
        <div class="xl:col-span-2">
            <label class="label-field" for="c-birth">Nascimento</label>
            <select id="c-birth" class="select-field" name="birth">
                <option value="">Todos</option>
                <option value="with_birth" <?= $birth === 'with_birth' ? 'selected' : '' ?>>Com nascimento</option>
                <option value="missing_birth" <?= $birth === 'missing_birth' ? 'selected' : '' ?>>Sem nascimento</option>
            </select>
        </div>
        <div class="xl:col-span-2">
            <label class="label-field" for="c-employer">Empresa vinculada</label>
            <input id="c-employer" class="input-field" type="text" name="employer" value="<?= h($employer) ?>" placeholder="Nome da empresa">
        </div>
        <div class="flex gap-2 md:col-span-2 xl:col-span-12">
            <button class="btn-primary flex-1 sm:flex-none sm:px-8" type="submit">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                Buscar
            </button>
            <?php if ($hasFilters): ?>
                <a class="btn-ghost" href="/clients/index.php">Limpar</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Resultado -->
<div class="card overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-100 px-5 py-4">
        <div>
            <h3 class="text-sm font-bold text-ink-950">Resultado da busca</h3>
            <p class="mt-0.5 text-xs text-ink-500">
                <?= $totalResults ?> registro<?= $totalResults === 1 ? '' : 's' ?> encontrado<?= $totalResults === 1 ? '' : 's' ?><?= $totalResults > 500 ? ' — exibindo os primeiros 500' : '' ?>.
                <?php if ((int)($stats['incomplete'] ?? 0) > 0): ?>
                    <span class="text-amber-600"><?= (int)$stats['incomplete'] ?> com dados incompletos.</span>
                <?php endif; ?>
            </p>
        </div>
        <div class="w-full max-w-xs">
            <input type="search" id="clientQuickFilter" class="input-field" placeholder="Filtrar resultados visíveis...">
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="table-modern min-w-[1180px]">
            <thead>
                <tr>
                    <th><?= client_sort_link('id', 'ID', $sortKey, $dir) ?></th>
                    <th><?= client_sort_link('name', 'Cliente', $sortKey, $dir) ?></th>
                    <th><?= client_sort_link('type', 'Tipo', $sortKey, $dir) ?></th>
                    <th><?= client_sort_link('document', 'Documento', $sortKey, $dir) ?></th>
                    <th><?= client_sort_link('birth_date', 'Nascimento', $sortKey, $dir) ?></th>
                    <th><?= client_sort_link('phone', 'Telefone', $sortKey, $dir) ?></th>
                    <th><?= client_sort_link('email', 'E-mail', $sortKey, $dir) ?></th>
                    <th><?= client_sort_link('employer', 'Empresa', $sortKey, $dir) ?></th>
                    <th><?= client_sort_link('created', 'Criado em', $sortKey, $dir) ?></th>
                    <th class="sticky right-0 bg-white text-right">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows): ?>
                    <?php foreach ($rows as $r): ?>
                        <?php
                            $searchText = implode(' ', [
                                $r['id'] ?? '',
                                $r['name'] ?? '',
                                $r['document'] ?? '',
                                $r['birth_date'] ? client_date_br($r['birth_date']) : '',
                                $r['phone'] ?? '',
                                $r['email'] ?? '',
                                $r['address'] ?? '',
                                $r['employer_name'] ?? '',
                            ]);
                            $incomplete = client_is_incomplete($r);
                        ?>
                        <tr data-client-search="<?= h(mb_strtolower($searchText, 'UTF-8')) ?>">
                            <td class="text-ink-400">#<?= (int)$r['id'] ?></td>
                            <td>
                                <div class="min-w-[260px]">
                                    <span class="copyable" data-copy="<?= h($r['name'] ?? '') ?>"><?= h($r['name']) ?></span>
                                    <?php if ($incomplete): ?>
                                        <span class="badge-soft ml-2 bg-amber-100 text-amber-700">Dados incompletos</span>
                                    <?php endif; ?>
                                    <?php if (!empty($r['address'])): ?>
                                        <p class="mt-0.5 text-xs text-ink-400"><?= h($r['address']) ?></p>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?= client_type_badge($r['client_type'] ?? null) ?></td>
                            <td class="copyable font-mono text-xs" data-copy="<?= h($r['document'] ?? '') ?>"><?= h($r['document'] ?: '—') ?></td>
                            <td class="copyable" data-copy="<?= !empty($r['birth_date']) ? h(client_date_br($r['birth_date'])) : '' ?>"><?= client_date_br($r['birth_date'] ?? null) ?></td>
                            <td><?= h($r['phone'] ?: '—') ?></td>
                            <td><?= h($r['email'] ?: '—') ?></td>
                            <td><?= h($r['employer_name'] ?: '—') ?></td>
                            <td class="text-ink-500"><?= client_date_br($r['created_at'] ?? null) ?></td>
                            <td class="sticky right-0 bg-white">
                                <div class="flex flex-wrap justify-end gap-1.5">
                                    <a class="btn-soft" href="/clients/show.php?id=<?= (int)$r['id'] ?>">Ver</a>
                                    <a class="btn-soft border-brand-200 text-brand-700 hover:bg-brand-50" href="/clients/edit.php?id=<?= (int)$r['id'] ?>">Editar</a>
                                    <a class="btn-soft border-emerald-200 text-emerald-700 hover:bg-emerald-50" href="/sales/create.php?client_id=<?= (int)$r['id'] ?>">Venda</a>
                                    <form action="/clients/delete.php" method="post" onsubmit="return confirm('Excluir este cliente?');">
                                        <input type="hidden" name="csrf" value="<?= h($token) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <button type="submit" class="btn-soft border-red-200 text-red-700 hover:bg-red-50">Excluir</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="10" class="px-4 py-12 text-center">
                            <p class="text-sm font-semibold text-ink-700">Nenhum cliente encontrado</p>
                            <p class="mt-1 text-sm text-ink-400">Ajuste os filtros ou cadastre um novo cliente.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('click', function (event) {
    const target = event.target.closest('.copyable');
    if (!target) return;

    const text = target.getAttribute('data-copy') || '';
    if (!text.trim()) return;

    navigator.clipboard.writeText(text).then(function () {
        const oldTooltip = target.querySelector('.copy-tooltip');
        if (oldTooltip) oldTooltip.remove();

        const tooltip = document.createElement('span');
        tooltip.className = 'copy-tooltip';
        tooltip.textContent = 'Copiado';
        target.appendChild(tooltip);

        setTimeout(function () {
            tooltip.remove();
        }, 900);
    }).catch(function (err) {
        console.error('Erro ao copiar:', err);
    });
});

document.addEventListener('DOMContentLoaded', function () {
    const quickFilter = document.getElementById('clientQuickFilter');
    if (!quickFilter) return;

    const rows = Array.from(document.querySelectorAll('tr[data-client-search]'));
    quickFilter.addEventListener('input', function () {
        const needle = quickFilter.value.trim().toLowerCase();
        rows.forEach(function (row) {
            const haystack = row.getAttribute('data-client-search') || '';
            row.classList.toggle('is-hidden', needle !== '' && !haystack.includes(needle));
        });
    });
});
</script>
<?php
$body = ob_get_clean();
require __DIR__ . '/../inc/layout.php';