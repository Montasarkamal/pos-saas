<?php
// suppliers/edit.php — Versão Final Corrigida
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php'; 
require_once __DIR__ . '/../inc/auth.php';
require_login();

function to_upper($s){ return mb_strtoupper(trim($s), 'UTF-8'); }

// 4. جلب بيانات المورد
$id = (int)($_GET['id'] ?? 0);
$agencyScope = agency_scope_sql('agency_id');
$st = $pdo->prepare("SELECT * FROM suppliers WHERE id = ? AND {$agencyScope[0]}");
$st->execute(array_merge([$id], $agencyScope[1]));
$s = $st->fetch();

if (!$s) { 
    http_response_code(404); 
    exit('Fornecedor não encontrado'); 
}
$recordAgencyId = (int)($s['agency_id'] ?? agency_id());

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $err = 'Sessão expirada. Atualize e tente novamente.';
    } else {
        $supplier_type = ($_POST['supplier_type'] ?? 'pj') === 'pf' ? 'pf' : 'pj';
        $name       = to_upper($_POST['name'] ?? '');
        $document   = trim($_POST['document'] ?? '');
        $phone      = trim($_POST['phone'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $address    = trim($_POST['address'] ?? '');
        $notes      = trim($_POST['notes'] ?? '');
        $is_active  = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '' || $document === '') {
            $err = 'Nome/Razão e CPF/CNPJ são obrigatórios.';
        } else {
            // التأكد إن الرقم (CPF/CNPJ) مش مستخدم عند مورد تاني
            $ck = $pdo->prepare("SELECT 1 FROM suppliers WHERE document = ? AND id <> ? AND agency_id = ? LIMIT 1");
            $ck->execute([$document, $id, $recordAgencyId]);
            
            if ($ck->fetch()) {
                $err = 'Documento já cadastrado em outro fornecedor.';
            } else {
                try {
                    $up = $pdo->prepare("UPDATE suppliers 
                        SET supplier_type=?, name=?, document=?, phone=?, email=?, address=?, notes=?, is_active=?, updated_at=NOW() 
                        WHERE id=? AND agency_id=?");
                    
                    $up->execute([
                        $supplier_type,
                        $name,
                        $document,
                        $phone,
                        $email,
                        $address,
                        $notes,
                        $is_active,
                        $id,
                        $recordAgencyId
                    ]);

                    header('Location: /suppliers/show.php?id='.$id); 
                    exit;
                } catch (PDOException $e) {
                    error_log('suppliers/edit.php: ' . $e->getMessage());
                    $err = "Erro ao atualizar.";
                }
            }
        }
    }
}

$token = csrf_token();
$pageTitle = 'Editar Fornecedor';
require __DIR__ . '/../inc/header.php';
?>

<div class="page-body">
    <div class="container-xl">
        <div class="row justify-content-center">
            <div class="col-md-10">
                <form class="card" method="post" autocomplete="off">
                    <div class="card-header">
                        <h3 class="card-title">Editar Fornecedor #<?= (int)$s['id'] ?></h3>
                    </div>
                    <div class="card-body">
                        <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label">Tipo de Pessoa</label>
                            <div class="form-selectgroup">
                                <label class="form-selectgroup-item">
                                    <input type="radio" name="supplier_type" value="pf" class="form-selectgroup-input" <?= $s['supplier_type']==='pf'?'checked':'' ?>>
                                    <span class="form-selectgroup-label">Pessoa Física</span>
                                </label>
                                <label class="form-selectgroup-item">
                                    <input type="radio" name="supplier_type" value="pj" class="form-selectgroup-input" <?= $s['supplier_type']==='pj'?'checked':'' ?>>
                                    <span class="form-selectgroup-label">Pessoa Jurídica</span>
                                </label>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Nome / Razão Social *</label>
                                <input class="form-control" name="name" id="nameField" value="<?= htmlspecialchars($s['name']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Documento (CPF/CNPJ) *</label>
                                <input class="form-control" name="document" value="<?= htmlspecialchars($s['document']) ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Telefone</label>
                                <input class="form-control" name="phone" value="<?= htmlspecialchars($s['phone']) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">E-mail</label>
                                <input class="form-control" type="email" name="email" value="<?= htmlspecialchars($s['email']) ?>">
                            </div>

                            <div class="col-12">
                                <label class="form-label">Endereço</label>
                                <input class="form-control" name="address" value="<?= htmlspecialchars($s['address']) ?>">
                            </div>

                            <div class="col-12">
                                <label class="form-label">Notas</label>
                                <textarea class="form-control" rows="5" name="notes"><?= htmlspecialchars($s['notes'] ?? '') ?></textarea>
                            </div>

                            <div class="col-12">
                                <label class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_active" <?= !empty($s['is_active']) ? 'checked' : '' ?>>
                                    <span class="form-check-label">Fornecedor Ativo</span>
                                </label>
                            </div>
                        </div>

                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">
                    </div>
                    <div class="card-footer d-flex justify-content-between">
                        <a href="/suppliers/show.php?id=<?= $id ?>" class="btn btn-link">Cancelar</a>
                        <button class="btn btn-primary" type="submit">
                            <i class="ti ti-device-floppy me-1"></i> Salvar Alterações
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('nameField').addEventListener('input', function(){
    const pos = this.selectionStart;
    this.value = this.value.toUpperCase();
    this.setSelectionRange(pos, pos);
});
</script>

<?php require __DIR__ . '/../inc/footer.php'; ?>
