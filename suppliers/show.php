<?php
// suppliers/show.php — Visualização Detalhada do Fornecedor
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php'; 
require_once __DIR__ . '/../inc/auth.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
[$agencyCondition, $agencyParams] = agency_scope_sql('agency_id');

// 5. جلب بيانات المورد الأساسية
$st = $pdo->prepare("SELECT * FROM suppliers WHERE id = ? AND $agencyCondition");
$st->execute(array_merge([$id], $agencyParams));
$s = $st->fetch(PDO::FETCH_ASSOC);

if (!$s) { 
    http_response_code(404); 
    die('Fornecedor não encontrado (ID: ' . $id . ')'); 
}

$token = csrf_token();

// 6. جلب آخر 10 فواتير مرتبطة (اختياري)
$invoices = [];
try {
    $si = $pdo->prepare("SELECT id, invoice_number, issue_date, status, total_amount 
                         FROM invoices WHERE supplier_id = ? AND " . (is_superadmin() ? "1=1" : "agency_id = ?") . " ORDER BY id DESC LIMIT 10");
    $si->execute(array_merge([$id], is_superadmin() ? [] : [agency_id()]));
    $invoices = $si->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // نترك المصفوفة فارغة إذا لم يكن جدول الفواتير جاهزاً
}

$pageTitle = 'Fornecedor: ' . h($s['name']);
require_once __DIR__ . '/../inc/header.php';

// دالة مساعدة للحماية
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>

<div class="page-header d-print-none mb-3">
    <div class="row align-items-center">
        <div class="col">
            <h2 class="page-title">Detalhes do Fornecedor</h2>
            <div class="text-muted mt-1">Visualizando informações completas do ID #<?= $id ?></div>
        </div>
        <div class="col-auto ms-auto d-print-none">
            <div class="btn-list">
                <a href="/suppliers/index.php" class="btn btn-outline-secondary">
                    <i class="ti ti-arrow-left me-1"></i> Voltar
                </a>
                <a href="/suppliers/edit.php?id=<?= $id ?>" class="btn btn-primary">
                    <i class="ti ti-edit me-1"></i> Editar
                </a>
                <form action="/suppliers/delete.php" method="post" class="d-inline" onsubmit="return confirm('Excluir este fornecedor?');">
                    <input type="hidden" name="csrf" value="<?= h($token) ?>">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <button class="btn btn-outline-danger"><i class="ti ti-trash me-1"></i> Excluir</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row row-cards">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Informações do Cadastro</h3></div>
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-md-6">
                        <label class="form-label text-muted">Nome / Razão Social</label>
                        <div class="h3"><?= h($s['name']) ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted"><?= $s['supplier_type'] === 'pj' ? 'CNPJ' : 'CPF' ?></label>
                        <div class="h3"><?= h($s['document']) ?></div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label text-muted">Tipo</label>
                        <div><span class="badge bg-azure-lt"><?= $s['supplier_type'] === 'pj' ? 'Pessoa Jurídica' : 'Pessoa Física' ?></span></div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label text-muted">Status</label>
                        <div>
                            <?= (int)$s['is_active'] === 1 
                                ? '<span class="badge bg-success">Ativo</span>' 
                                : '<span class="badge bg-danger">Inativo</span>' ?>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label text-muted">Telefone</label>
                        <div class="fw-bold"><?= h($s['phone'] ?: '—') ?></div>
                    </div>
                    <div class="col-12">
                        <label class="form-label text-muted">E-mail</label>
                        <div><?= h($s['email'] ?: '—') ?></div>
                    </div>
                    <div class="col-12">
                        <label class="form-label text-muted">Endereço</label>
                        <div><?= h($s['address'] ?: '—') ?></div>
                    </div>
                    <div class="col-12">
                        <label class="form-label text-muted">Observações</label>
                        <div class="p-3 bg-light rounded">
                            <?= nl2br(h($s['notes'] ?? 'Nenhuma observação registrada.')) ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Comunicação</h3></div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <?php if (!empty($s['phone'])): ?>
                        <a href="https://wa.me/<?= preg_replace('/\D+/', '', $s['phone']) ?>" target="_blank" class="btn btn-success w-100">
                            <i class="ti ti-brand-whatsapp me-2"></i> Chamar no WhatsApp
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($s['email'])): ?>
                        <a href="mailto:<?= h($s['email']) ?>" class="btn btn-outline-primary w-100">
                            <i class="ti ti-mail me-2"></i> Enviar E-mail
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body text-center">
                <div class="subheader">Histórico</div>
                <div class="h1 m-0"><?= count($invoices) ?></div>
                <div class="text-muted small">Faturas recentes encontradas</div>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card mt-3">
            <div class="card-header"><h3 class="card-title">Últimas Faturas do Fornecedor</h3></div>
            <div class="table-responsive">
                <table class="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th>Fatura #</th>
                            <th>Emissão</th>
                            <th>Status</th>
                            <th class="text-end">Valor</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($invoices): foreach ($invoices as $inv): ?>
                            <tr>
                                <td><?= h($inv['invoice_number']) ?></td>
                                <td><?= date('d/m/Y', strtotime($inv['issue_date'])) ?></td>
                                <td>
                                    <?php
                                        $st_low = strtolower((string)$inv['status']);
                                        $badge = 'bg-secondary-lt';
                                        if (in_array($st_low, ['pago','paid'])) $badge = 'bg-success-lt';
                                        elseif (in_array($st_low, ['nao pago','unpaid'])) $badge = 'bg-danger-lt';
                                    ?>
                                    <span class="badge <?= $badge ?>"><?= h(status_label($inv['status'])) ?></span>
                                </td>
                                <td class="text-end fw-bold"><?= number_format((float)$inv['total_amount'], 2, ',', '.') ?></td>
                                <td class="text-end">
                                    <a href="/sales/show.php?id=<?= $inv['id'] ?>" class="btn btn-sm btn-ghost-primary">Ver</a>
                                </td>
                            </tr>
                        <?php endforeach; else: ?>
                            <tr><td colspan="5" class="text-center text-muted p-4">Nenhuma fatura encontrada para este fornecedor.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../inc/footer.php'; ?>
