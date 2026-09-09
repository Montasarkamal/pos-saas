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

require_once __DIR__ . '/../inc/header.php';
?>

<style>
    /* تنسيق الأزرار لتكون في صف واحد ومظبوطة */
    .actions-cell {
        min-width: 200px; /* ضمان مساحة كافية للأزرار */
    }
    .btn-group-actions {
        display: flex;
        gap: 8px; /* مسافة بين الأزرار */
        justify-content: flex-end;
        align-items: center;
    }
    .btn-sm-custom {
        padding: 4px 10px;
        font-size: 12px;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 4px;
        white-space: nowrap;
    }
    /* ميزة نسخ البريد الإلكتروني */
    .copyable-email {
        cursor: pointer;
        border-bottom: 1px dashed #ccc;
    }
    .copyable-email:hover {
        color: #206bc4;
        border-bottom-color: #206bc4;
    }
</style>

<div class="page-wrapper">
    <div class="container-xl">
        <div class="page-header d-print-none mb-3">
            <div class="row align-items-center">
                <div class="col">
                    <h2 class="page-title">Configurações de Usuários</h2>
                    <div class="text-muted small">Gerenciamento de acessos do sistema</div>
                </div>
                <div class="col-auto ms-auto">
                    <div class="d-flex gap-2">
                        <form method="get" class="d-none d-md-flex">
                            <div class="input-icon">
                                <span class="input-icon-addon"><i class="ti ti-search"></i></span>
                                <input type="search" class="form-control" name="q" value="<?= h($q) ?>" placeholder="Buscar usuário...">
                            </div>
                        </form>
                        <a href="/users/create.php" class="btn btn-primary">
                            <i class="ti ti-plus me-2"></i> Novo Usuário
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($msg === 'deactivated'): ?>
            <div class="alert alert-warning">
                Usuário vinculado a registros existentes. Ele foi desativado em vez de excluído.
            </div>
        <?php elseif ($msg === 'deleted'): ?>
            <div class="alert alert-success">Usuário excluído com sucesso.</div>
        <?php elseif ($msg === 'error'): ?>
            <div class="alert alert-danger">Não foi possível processar a solicitação.</div>
        <?php endif; ?>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-vcenter card-table table-striped">
                    <thead>
                        <tr>
                            <th style="width: 50px;">ID</th>
                            <th>Nome</th>
                            <th>E-mail</th>
                            <?php if (is_superadmin()): ?><th>Empresa</th><?php endif; ?>
                            <th>Cargo</th>
                            <th>Status</th>
                            <th class="text-end actions-cell">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td class="text-muted"><?= (int)$r['id'] ?></td>
                                <td class="fw-bold"><?= h($r['name']) ?></td>
                                <td>
                                    <span class="copyable-email js-copy-email" data-email="<?= h($r['email']) ?>" title="Clique para copiar">
                                        <?= h($r['email']) ?>
                                    </span>
                                </td>
                                <?php if (is_superadmin()): ?>
                                    <td><?= h($r['agency_name'] ?: '—') ?></td>
                                <?php endif; ?>
                                <td>
                                    <?php if (strtolower((string)$r['role']) === 'superadmin'): ?>
                                        <span class="badge bg-yellow-lt"><i class="ti ti-crown me-1"></i> MASTER</span>
                                    <?php elseif (strtolower((string)$r['role']) === 'admin'): ?>
                                        <span class="badge bg-purple-lt"><i class="ti ti-shield-lock me-1"></i> ADMIN</span>
                                    <?php else: ?>
                                        <span class="badge bg-blue-lt"><i class="ti ti-user me-1"></i> OPERADOR</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= (int)$r['is_active'] === 1 
                                        ? '<span class="status status-green"><span class="status-dot status-dot-animated"></span> Ativo</span>' 
                                        : '<span class="status status-red">Inativo</span>' ?>
                                </td>
                                <td class="text-end actions-cell">
                                    <div class="btn-group-actions">
                                        <a href="/users/edit.php?id=<?= (int)$r['id'] ?>" 
                                           class="btn btn-sm btn-outline-primary btn-sm-custom">
                                            <i class="ti ti-edit"></i> Editar
                                        </a>
                                        
                                        <?php if (is_master_user($r)): ?>
                                            <span class="badge bg-yellow-lt btn-sm-custom" title="Conta protegida do sistema">
                                                <i class="ti ti-lock"></i> Protegido
                                            </span>
                                        <?php elseif ((int)$r['id'] !== (int)($_SESSION['uid'] ?? 0)): ?>
                                            <form action="/users/delete.php" method="post" 
                                                  onsubmit="return confirm('Deseja excluir este usuário?');" 
                                                  style="margin:0;">
                                                <input type="hidden" name="csrf" value="<?= h($token) ?>">
                                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger btn-sm-custom">
                                                    <i class="ti ti-trash"></i> Excluir
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="badge bg-gray-lt btn-sm-custom" title="Sua própria conta">
                                                <i class="ti ti-user-check"></i> Você
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <?php if (!$rows): ?>
                            <tr>
                                <td colspan="<?= is_superadmin() ? '7' : '6' ?>" class="text-center p-5 text-muted">
                                    <i class="ti ti-user-off d-block mb-2 h1"></i>
                                    Nenhum usuário encontrado com os critérios de busca.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    // نظام نسخ الإيميل بلمسة واحدة
    document.querySelectorAll('.js-copy-email').forEach(el => {
        el.addEventListener('click', function() {
            const email = this.getAttribute('data-email');
            navigator.clipboard.writeText(email).then(() => {
                const original = this.innerHTML;
                this.innerHTML = '<span class="text-success fw-bold">Copiado!</span>';
                setTimeout(() => { this.innerHTML = original; }, 1000);
            });
        });
    });
</script>

<?php require_once __DIR__ . '/../inc/footer.php'; ?>
