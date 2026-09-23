<?php
// suppliers/index.php — modern layout (Phase 2)
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/ui.php';
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

ob_start();
?>
<!-- Header -->
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-xl font-bold text-ink-950">Fornecedores</h2>
        <p class="mt-0.5 text-sm text-ink-500">Clique nos campos para copiar os dados.</p>
    </div>
    <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
        <form method="get" class="flex flex-1 items-center gap-2 sm:flex-none">
            <input type="search" class="input-field sm:w-56" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Buscar...">
            <button type="submit" class="btn-primary px-4">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                Buscar
            </button>
        </form>
        <a href="/suppliers/create.php" class="btn-primary px-4">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"></path><path d="M5 12h14"></path></svg>
            Novo
        </a>
    </div>
</div>

<div class="card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="table-modern min-w-[900px]">
            <thead>
                <tr>
                    <th style="width:80px">ID</th>
                    <th style="width:100px">Tipo</th>
                    <th>Nome / Razão Social</th>
                    <th>CPF/CNPJ</th>
                    <?php if (is_superadmin()): ?><th>Empresa</th><?php endif; ?>
                    <th style="width:100px">Status</th>
                    <th class="sticky right-0 bg-white text-right">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="text-ink-400"><?= (int)$r['id'] ?></td>
                    <td>
                        <?php if ($r['supplier_type'] === 'pj'): ?>
                            <span class="badge-soft bg-purple-100 text-purple-700">PJ</span>
                        <?php else: ?>
                            <span class="badge-soft bg-cyan-100 text-cyan-700">PF</span>
                        <?php endif; ?>
                    </td>
                    <td class="copyable font-semibold text-ink-950" data-copy="<?= htmlspecialchars($r['name']) ?>">
                        <?= htmlspecialchars($r['name']) ?>
                    </td>
                    <td class="copyable font-mono text-xs" data-copy="<?= htmlspecialchars($r['document']) ?>">
                        <?= htmlspecialchars($r['document']) ?>
                    </td>
                    <?php if (is_superadmin()): ?>
                        <td class="text-ink-500"><?= htmlspecialchars($r['agency_name'] ?: '—') ?></td>
                    <?php endif; ?>
                    <td>
                        <?php if ((int)$r['is_active'] === 1): ?>
                            <span class="badge-soft bg-emerald-100 text-emerald-700">Ativo</span>
                        <?php else: ?>
                            <span class="badge-soft bg-red-100 text-red-700">Inativo</span>
                        <?php endif; ?>
                    </td>
                    <td class="sticky right-0 bg-white">
                        <div class="flex flex-wrap justify-end gap-1.5">
                            <a class="btn-soft border-brand-200 text-brand-700 hover:bg-brand-50" href="/suppliers/show.php?id=<?= (int)$r['id'] ?>">Ver</a>
                            <a class="btn-soft border-amber-200 text-amber-700 hover:bg-amber-50" href="/suppliers/edit.php?id=<?= (int)$r['id'] ?>">Editar</a>
                            <form action="/suppliers/delete.php" method="post" onsubmit="return confirm('Excluir este fornecedor?');">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn-soft border-red-200 text-red-700 hover:bg-red-50">Excluir</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                <tr><td colspan="<?= is_superadmin() ? '7' : '6' ?>" class="px-4 py-10 text-center text-sm text-ink-400">Nenhum fornecedor encontrado.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('click', function (e) {
    const target = e.target.closest('.copyable');
    if (!target) return;
    const text = target.getAttribute('data-copy');
    if (!text) return;

    navigator.clipboard.writeText(text).then(() => {
        const tooltip = document.createElement('span');
        tooltip.className = 'copy-tooltip';
        tooltip.innerText = 'Copiado!';
        target.appendChild(tooltip);
        setTimeout(() => tooltip.remove(), 1000);
    });
});
</script>
<?php
$body = ob_get_clean();
require __DIR__ . '/../inc/layout.php';