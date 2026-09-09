<?php
// clients/index.php
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/helpers.php';
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
        $icon = strtolower($currentDir) === 'asc' ? ' <i class="ti ti-chevron-up"></i>' : ' <i class="ti ti-chevron-down"></i>';
    }
    return '<a class="client-sort-link" href="?' . h(http_build_query($params)) . '">' . h($label) . $icon . '</a>';
}

function client_type_badge(?string $type): string {
    if ($type === 'pj') {
        return '<span class="badge bg-blue-lt">Empresa</span>';
    }
    return '<span class="badge bg-green-lt">Pessoa</span>';
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

if (!has_table($pdo, 'clients')) {
    $token = csrf_token();
    $pageTitle = 'Clientes';
    require_once __DIR__ . '/../inc/header.php';
    ?>
    <div class="page-body">
      <div class="container-xl">
        <div class="alert alert-warning mt-3">Tabela de clientes não está disponível na base atual.</div>
      </div>
    </div>
    <?php require_once __DIR__ . '/../inc/footer.php'; exit; ?>
<?php }

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
$pageTitle = 'Clientes';
require_once __DIR__ . '/../inc/header.php';
?>

<style>
.clients-search-panel {
    border: 1px solid #dbe5f2;
    box-shadow: 0 8px 22px rgba(15, 23, 42, .04);
}
.client-stat {
    display: grid;
    gap: .15rem;
    padding: .85rem 1rem;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #fff;
}
.client-stat strong {
    font-size: 1.25rem;
    color: #0f172a;
}
.client-stat span {
    color: #64748b;
    font-weight: 700;
    font-size: .76rem;
    text-transform: uppercase;
    letter-spacing: .04em;
}
.clients-table td {
    vertical-align: middle;
}
.client-main-name {
    display: grid;
    gap: .12rem;
}
.client-main-name a {
    color: #111827;
    font-weight: 800;
    text-decoration: none;
}
.client-main-name a:hover {
    color: #2563eb;
}
.client-subline {
    color: #64748b;
    font-size: .82rem;
}
.client-name-row {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
}
.client-name-copy {
    color: #111827;
    font-weight: 800;
    padding: .1rem .2rem;
}
.client-badges {
    display: inline-flex;
    align-items: center;
    gap: .3rem;
    flex-wrap: wrap;
}
.client-inline-filter {
    max-width: 320px;
}
.clients-table tbody tr.is-hidden {
    display: none;
}
.client-sort-link {
    color: inherit;
    display: inline-flex;
    align-items: center;
    gap: .18rem;
    text-decoration: none;
}
.client-sort-link:hover {
    color: #2563eb;
}
.copyable {
    cursor: pointer;
    position: relative;
    border-radius: 6px;
    transition: background .15s ease;
}
.copyable:hover {
    background: rgba(37, 99, 235, .08);
}
.copy-tooltip {
    position: absolute;
    top: -1.35rem;
    left: 50%;
    transform: translateX(-50%);
    background: #16a34a;
    color: #fff;
    font-size: .68rem;
    font-weight: 800;
    padding: .18rem .45rem;
    border-radius: 5px;
    z-index: 20;
    white-space: nowrap;
}
.clients-empty {
    padding: 3rem 1rem;
    text-align: center;
    color: #64748b;
}
.clients-empty i {
    display: block;
    color: #94a3b8;
    font-size: 2.5rem;
    margin-bottom: .75rem;
}
</style>

<div class="page-header d-print-none mb-3">
    <div class="row align-items-center g-3">
        <div class="col">
            <h2 class="page-title">Clientes</h2>
            <div class="text-muted mt-1">Busca por nome, CPF/CNPJ, telefone, e-mail, endereço, observações e empresa vinculada.</div>
        </div>
        <div class="col-auto">
            <a href="/clients/create.php" class="btn btn-primary">
                <i class="ti ti-user-plus"></i>Novo cliente
            </a>
        </div>
    </div>
</div>

<div class="row row-cards mb-3">
    <div class="col-sm-6 col-lg-3">
        <div class="client-stat">
            <strong><?= (int)($stats['total'] ?? 0) ?></strong>
            <span>Total de clientes</span>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="client-stat">
            <strong><?= (int)($stats['pf'] ?? 0) ?></strong>
            <span>Pessoas físicas</span>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="client-stat">
            <strong><?= (int)($stats['pj'] ?? 0) ?></strong>
            <span>Empresas</span>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="client-stat">
            <strong><?= (int)($stats['with_birth'] ?? 0) ?></strong>
            <span>Com nascimento</span>
        </div>
    </div>
</div>

<div class="card clients-search-panel mb-3">
    <form method="get" class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-lg-4">
                <label class="form-label">Busca geral</label>
                <div class="input-icon">
                    <span class="input-icon-addon"><i class="ti ti-search"></i></span>
                    <input type="text" name="q" value="<?= h($q) ?>" class="form-control" placeholder="Nome, documento, telefone, e-mail, endereço...">
                </div>
            </div>
            <div class="col-md-4 col-lg-2">
                <label class="form-label">Tipo</label>
                <select name="type" class="form-select">
                    <option value="">Todos</option>
                    <option value="pf" <?= $type === 'pf' ? 'selected' : '' ?>>Pessoa física</option>
                    <option value="pj" <?= $type === 'pj' ? 'selected' : '' ?>>Empresa</option>
                </select>
            </div>
            <div class="col-md-4 col-lg-2">
                <label class="form-label">Contato</label>
                <select name="contact" class="form-select">
                    <option value="">Todos</option>
                    <option value="with_email" <?= $contact === 'with_email' ? 'selected' : '' ?>>Com e-mail</option>
                    <option value="missing_email" <?= $contact === 'missing_email' ? 'selected' : '' ?>>Sem e-mail</option>
                    <option value="with_phone" <?= $contact === 'with_phone' ? 'selected' : '' ?>>Com telefone</option>
                    <option value="missing_phone" <?= $contact === 'missing_phone' ? 'selected' : '' ?>>Sem telefone</option>
                </select>
            </div>
            <div class="col-md-4 col-lg-2">
                <label class="form-label">Nascimento</label>
                <select name="birth" class="form-select">
                    <option value="">Todos</option>
                    <option value="with_birth" <?= $birth === 'with_birth' ? 'selected' : '' ?>>Com nascimento</option>
                    <option value="missing_birth" <?= $birth === 'missing_birth' ? 'selected' : '' ?>>Sem nascimento</option>
                </select>
            </div>
            <div class="col-md-4 col-lg-2">
                <label class="form-label">Empresa vinculada</label>
                <input type="text" name="employer" value="<?= h($employer) ?>" class="form-control" placeholder="Nome da empresa">
            </div>
            <div class="col-lg-2">
                <div class="btn-list justify-content-lg-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="ti ti-search"></i>Buscar
                    </button>
                    <?php if ($hasFilters): ?>
                        <a href="/clients/index.php" class="btn btn-outline-secondary">
                            <i class="ti ti-x"></i>Limpar
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </form>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h3 class="card-title">Resultado da busca</h3>
            <div class="text-muted mt-1">
                <?= $totalResults ?> registro<?= $totalResults === 1 ? '' : 's' ?> encontrado<?= $totalResults === 1 ? '' : 's' ?><?= $totalResults > 500 ? ' - exibindo os primeiros 500' : '' ?>.
                <?= (int)($stats['incomplete'] ?? 0) ?> com dados incompletos.
            </div>
        </div>
        <div class="ms-auto client-inline-filter">
            <div class="input-icon">
                <span class="input-icon-addon"><i class="ti ti-filter-search"></i></span>
                <input type="search" id="clientQuickFilter" class="form-control" placeholder="Filtrar resultados visíveis...">
            </div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table clients-table">
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
                    <th class="text-end">Ações</th>
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
                            <td class="text-muted">#<?= (int)$r['id'] ?></td>
                            <td>
                                <div class="client-main-name">
                                    <span class="client-name-row">
                                        <span class="client-name-copy copyable" data-copy="<?= h($r['name'] ?? '') ?>"><?= h($r['name']) ?></span>
                                    </span>
                                    <span class="client-badges">
                                        <?php if ($incomplete): ?>
                                            <span class="badge bg-yellow-lt">Dados incompletos</span>
                                        <?php endif; ?>
                                    </span>
                                    <?php if (!empty($r['address'])): ?>
                                        <span class="client-subline"><?= h($r['address']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?= client_type_badge($r['client_type'] ?? null) ?></td>
                            <td class="copyable" data-copy="<?= h($r['document'] ?? '') ?>"><?= h($r['document'] ?: '—') ?></td>
                            <td class="copyable" data-copy="<?= !empty($r['birth_date']) ? h(client_date_br($r['birth_date'])) : '' ?>"><?= client_date_br($r['birth_date'] ?? null) ?></td>
                            <td><?= h($r['phone'] ?: '—') ?></td>
                            <td><?= h($r['email'] ?: '—') ?></td>
                            <td><?= h($r['employer_name'] ?: '—') ?></td>
                            <td><?= client_date_br($r['created_at'] ?? null) ?></td>
                            <td class="text-end">
                                <div class="btn-list justify-content-end">
                                    <a href="/clients/show.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                        <i class="ti ti-eye"></i>Ver
                                    </a>
                                    <a href="/clients/edit.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="ti ti-edit"></i>Editar
                                    </a>
                                    <a href="/sales/create.php?client_id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-success">
                                        <i class="ti ti-receipt"></i>Venda
                                    </a>
                                    <form action="/clients/delete.php" method="post" onsubmit="return confirm('Excluir este cliente?');" class="d-inline">
                                        <input type="hidden" name="csrf" value="<?= h($token) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="ti ti-trash"></i>Excluir
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="10">
                            <div class="clients-empty">
                                <i class="ti ti-users-off"></i>
                                <div class="h3 mb-1">Nenhum cliente encontrado</div>
                                <div>Ajuste os filtros ou cadastre um novo cliente.</div>
                            </div>
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

    const rows = Array.from(document.querySelectorAll('.clients-table tbody tr[data-client-search]'));
    quickFilter.addEventListener('input', function () {
        const needle = quickFilter.value.trim().toLowerCase();
        rows.forEach(function (row) {
            const haystack = row.getAttribute('data-client-search') || '';
            row.classList.toggle('is-hidden', needle !== '' && !haystack.includes(needle));
        });
    });
});
</script>

<?php require_once __DIR__ . '/../inc/footer.php'; ?>
