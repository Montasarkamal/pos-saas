<?php
// users/index.php — Gestão de Usuários (Versão Pro)
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php'; 
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/helpers.php';

// تشغيل الحماية
require_login();
require_role_admin();

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// 5. منطق البحث وجلب البيانات
$q = trim($_GET['q'] ?? '');
[$agencyCondition, $params] = agency_scope_sql('u.agency_id');
$where = " WHERE $agencyCondition ";
$agencyLabelParts = [];
if (has_column($pdo, 'agencies', 'fantasy_name')) $agencyLabelParts[] = "NULLIF(a.fantasy_name, '')";
if (has_column($pdo, 'agencies', 'name')) $agencyLabelParts[] = "NULLIF(a.name, '')";
if (has_column($pdo, 'agencies', 'legal_name')) $agencyLabelParts[] = "NULLIF(a.legal_name, '')";
$agencyLabelExpr = $agencyLabelParts
    ? 'COALESCE(' . implode(', ', $agencyLabelParts) . ", CONCAT('Empresa #', u.agency_id))"
    : "CONCAT('Empresa #', u.agency_id)";

if ($q !== '') {
    $where .= " AND (u.name LIKE ? OR u.email LIKE ? OR COALESCE(u.login, '') LIKE ?) ";
    $like = "%$q%";
    array_push($params, $like, $like, $like);
}

$sql = "SELECT u.id, u.name, u.email, u.login, u.role, u.is_active, u.created_at, u.agency_id,
               {$agencyLabelExpr} AS agency_name
        FROM users u
        LEFT JOIN agencies a ON a.id = u.agency_id
        $where
        ORDER BY u.id DESC
        LIMIT 500";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$token = csrf_token();
$pageTitle = 'Usuários';
$msg = $_GET['msg'] ?? '';
ob_start();
?>
<!-- Header -->
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-xl font-bold text-ink-950">Usuários</h2>
        <p class="mt-0.5 text-sm text-ink-500">Gerenciamento de acessos do sistema</p>
    </div>
    <a href="/users/create.php" class="btn-primary">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><line x1="19" y1="8" x2="19" y2="14"></line><line x1="22" y1="11" x2="16" y2="11"></line></svg>
        Novo Usuário
    </a>
</div>

<!-- Feedback messages -->
<?php if ($msg === 'deactivated'): ?>
    <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800" role="alert">
        Usuário vinculado a registros existentes. Ele foi desativado em vez de excluído.
    </div>
<?php elseif ($msg === 'deleted'): ?>
    <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800" role="alert">
        Usuário excluído com sucesso.
    </div>
<?php elseif ($msg === 'error'): ?>
    <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800" role="alert">
        Não foi possível processar a solicitação.
    </div>
<?php endif; ?>

<!-- Lista -->
<div class="card overflow-hidden">
    <div class="border-b border-ink-100 px-5 py-4">
        <form method="get" class="flex flex-wrap items-center gap-2">
            <div class="relative min-w-[260px] flex-1">
                <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input class="input-field !pl-9" type="search" name="q" value="<?= h($q) ?>" placeholder="Buscar usuário por nome ou e-mail...">
            </div>
            <button class="btn-primary sm:px-6" type="submit">Buscar</button>
            <?php if ($q !== ''): ?>
                <a class="btn-ghost" href="/users/index.php">Limpar</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="overflow-x-auto">
        <table class="table-modern min-w-[920px]">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nome</th>
                    <th>E-mail</th>
                    <?php if (is_superadmin()): ?><th>Empresa</th><?php endif; ?>
                    <th>Cargo</th>
                    <th>Status</th>
                    <th class="text-right">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="font-mono text-xs text-ink-400"><?= (int)$r['id'] ?></td>
                        <td class="font-semibold text-ink-950"><?= h($r['name']) ?></td>
                        <td>
                            <span class="copyable js-copy-email" data-email="<?= h($r['email']) ?>" title="Clique para copiar">
                                <?= h($r['email']) ?>
                            </span>
                        </td>
                        <?php if (is_superadmin()): ?>
                            <td><?= h($r['agency_name'] ?: '—') ?></td>
                        <?php endif; ?>
                        <td>
                            <?php if (strtolower((string)$r['role']) === 'superadmin'): ?>
                                <span class="badge-soft bg-amber-100 text-amber-700">
                                    <svg class="mr-1 inline-block h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 4l3 12h14l3-12"></path><path d="M6 14h12"></path><path d="M12 4l1.5 3h-3z"></path></svg>
                                    MASTER
                                </span>
                            <?php elseif (strtolower((string)$r['role']) === 'admin'): ?>
                                <span class="badge-soft bg-violet-100 text-violet-700">
                                    <svg class="mr-1 inline-block h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
                                    ADMIN
                                </span>
                            <?php else: ?>
                                <span class="badge-soft bg-sky-100 text-sky-700">
                                    <svg class="mr-1 inline-block h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                                    OPERADOR
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int)$r['is_active'] === 1): ?>
                                <span class="badge-soft bg-emerald-100 text-emerald-700">
                                    <span class="mr-1 inline-block h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                    Ativo
                                </span>
                            <?php else: ?>
                                <span class="badge-soft bg-red-100 text-red-700">Inativo</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-right">
                            <div class="flex items-center justify-end gap-1.5">
                                <a class="btn-xs border border-ink-200 bg-white text-ink-700 hover:bg-ink-50" href="/users/edit.php?id=<?= (int)$r['id'] ?>">
                                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                    Editar
                                </a>

                                <?php if (is_master_user($r)): ?>
                                    <span class="badge-soft bg-amber-100 text-amber-700" title="Conta protegida do sistema">
                                        <svg class="mr-1 inline-block h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                                        Protegido
                                    </span>
                                <?php elseif ((int)$r['id'] !== (int)($_SESSION['uid'] ?? 0)): ?>
                                    <form action="/users/delete.php" method="post" class="inline" onsubmit="return confirm('Deseja excluir este usuário?');">
                                        <input type="hidden" name="csrf" value="<?= h($token) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <button type="submit" class="btn-xs border border-red-200 bg-white text-red-700 hover:bg-red-50">
                                            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                            Excluir
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="badge-soft bg-ink-100 text-ink-600" title="Sua própria conta">
                                        <svg class="mr-1 inline-block h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M17 11l2 2 4-4"></path></svg>
                                        Você
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="<?= is_superadmin() ? '7' : '6' ?>" class="px-4 py-10 text-center">
                            <svg class="mx-auto mb-2 h-8 w-8 text-ink-300" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><line x1="17" y1="8" x2="17" y2="14"></line><line x1="20" y1="11" x2="14" y2="11"></line></svg>
                            <p class="text-sm text-ink-400">Nenhum usuário encontrado com os critérios de busca.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Cópia de e-mail com um toque
document.querySelectorAll('.js-copy-email').forEach(el => {
    el.addEventListener('click', function() {
        const email = this.getAttribute('data-email');
        navigator.clipboard.writeText(email).then(() => {
            const original = this.innerHTML;
            this.innerHTML = '<span class="font-bold text-emerald-600">Copiado!</span>';
            setTimeout(() => { this.innerHTML = original; }, 1000);
        });
    });
});
</script>
<?php
$body = ob_get_clean();
require __DIR__ . '/../inc/layout.php';