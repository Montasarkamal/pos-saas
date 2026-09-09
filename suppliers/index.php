<?php
// suppliers/index.php — Versão Corrigida e Completa
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/helpers.php';
require_login();

$q = trim($_GET['q'] ?? '');
[$agencyCondition, $params] = agency_scope_sql('s.agency_id');
$where = " WHERE $agencyCondition ";
$agencyLabelParts = [];
if (has_column($pdo, 'agencies', 'fantasy_name')) $agencyLabelParts[] = "NULLIF(a.fantasy_name, '')";
if (has_column($pdo, 'agencies', 'name')) $agencyLabelParts[] = "NULLIF(a.name, '')";
if (has_column($pdo, 'agencies', 'legal_name')) $agencyLabelParts[] = "NULLIF(a.legal_name, '')";
$agencyLabelExpr = $agencyLabelParts
    ? 'COALESCE(' . implode(', ', $agencyLabelParts) . ", CONCAT('Empresa #', s.agency_id))"
    : "CONCAT('Empresa #', s.agency_id)";

if ($q !== '') {
    // استعلام بحث أبسط وأسرع بيغطي الاسم والمستند
    $where .= " AND (name LIKE ? OR document LIKE ? OR phone LIKE ? OR email LIKE ?) ";
    $like = "%$q%";
    array_push($params, $like, $like, $like, $like);
}

$sql = "SELECT s.id, s.supplier_type, s.name, s.document, s.is_active, s.phone, s.email,
               {$agencyLabelExpr} AS agency_name
        FROM suppliers s
        LEFT JOIN agencies a ON a.id = s.agency_id
        $where 
        ORDER BY s.id DESC LIMIT 500";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$token = csrf_token();
$pageTitle = 'Fornecedores';
require_once __DIR__ . '/../inc/header.php';
?>

<style>
    /* تأثير النسخ عند الضغط */
    .copyable { cursor: pointer; position: relative; }
    .copyable:hover { background: rgba(32, 107, 196, 0.08) !important; }
    .copy-tooltip {
        position: absolute; top: -20px; left: 50%; transform: translateX(-50%);
        background: #22c55e; color: white; font-size: 10px; padding: 2px 6px;
        border-radius: 4px; display: none; z-index: 10;
    }
</style>

<div class="page-header d-print-none mb-3">
    <div class="row align-items-center">
        <div class="col">
            <h2 class="page-title">Fornecedores (الموردين)</h2>
            <div class="text-muted small">Clique nos campos para copiar os dados</div>
        </div>
        <div class="col-auto ms-auto">
            <form class="d-inline-flex gap-2" method="get">
                <input type="search" class="form-control" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Buscar...">
                <button type="submit" class="btn btn-primary">Buscar</button>
                <a href="/suppliers/create.php" class="btn btn-success">
                    <i class="ti ti-plus"></i> Novo
                </a>
            </form>
        </div>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead>
                <tr>
                    <th style="width:80px">ID</th>
                    <th style="width:100px">Tipo</th>
                    <th>Nome / Razão Social</th>
                    <th>CPF/CNPJ</th>
                    <?php if (is_superadmin()): ?><th>Empresa</th><?php endif; ?>
                    <th style="width:100px">Status</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td>
                        <span class="badge <?= $r['supplier_type'] === 'pj' ? 'bg-purple-lt' : 'bg-azure-lt' ?>">
                            <?= $r['supplier_type'] === 'pj' ? 'PJ' : 'PF' ?>
                        </span>
                    </td>
                    <td class="fw-bold copyable" data-copy="<?= htmlspecialchars($r['name']) ?>">
                        <?= htmlspecialchars($r['name']) ?>
                    </td>
                    <td class="copyable" data-copy="<?= htmlspecialchars($r['document']) ?>">
                        <?= htmlspecialchars($r['document']) ?>
                    </td>
                    <?php if (is_superadmin()): ?>
                        <td><?= htmlspecialchars($r['agency_name'] ?: '—') ?></td>
                    <?php endif; ?>
                    <td>
                        <?= (int)$r['is_active'] === 1
                            ? '<span class="badge bg-success-lt">Ativo</span>'
                            : '<span class="badge bg-danger-lt">Inativo</span>' ?>
                    </td>
                    <td class="text-end">
                        <div class="btn-list justify-content-end">
                            <a class="btn btn-sm btn-outline-primary" href="/suppliers/show.php?id=<?= (int)$r['id'] ?>">Ver</a>
                            <a class="btn btn-sm btn-outline-warning" href="/suppliers/edit.php?id=<?= (int)$r['id'] ?>">Editar</a>
                            <form action="/suppliers/delete.php" method="post" class="d-inline" onsubmit="return confirm('Excluir este fornecedor?');">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger">Excluir</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                <tr><td colspan="<?= is_superadmin() ? '7' : '6' ?>" class="text-center text-muted p-4">Nenhum fornecedor encontrado.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    // كود النسخ عند الضغط
    document.addEventListener('click', function (e) {
        const target = e.target.closest('.copyable');
        if (!target) return;
        const text = target.getAttribute('data-copy');
        if (!text) return;

        navigator.clipboard.writeText(text).then(() => {
            const tooltip = document.createElement('span');
            tooltip.className = 'copy-tooltip';
            tooltip.innerText = 'Copiado!';
            tooltip.style.display = 'block';
            target.appendChild(tooltip);
            setTimeout(() => tooltip.remove(), 1000);
        });
    });
</script>

<?php require_once __DIR__ . '/../inc/footer.php'; ?>
