<?php
// suppliers/create.php — Versão Corrigida e Segura
require __DIR__ . '/../inc/auth.php';
require_login();

function to_upper($s){ return mb_strtoupper(trim($s), 'UTF-8'); }

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // التحقق من توكن الحماية
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
            try {
                // التأكد من عدم تكرار المستند
                $ck = $pdo->prepare("SELECT 1 FROM suppliers WHERE document=? AND agency_id=? LIMIT 1");
                $ck->execute([$document, agency_id()]);
                
                if ($ck->fetch()) {
                    $err = 'Documento (CPF/CNPJ) já cadastrado.';
                } else {
                    // إدخال البيانات
                    $ins = $pdo->prepare("INSERT INTO suppliers 
                        (supplier_type, name, document, phone, email, address, notes, is_active, agency_id, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                    
                    $ins->execute([
                        $supplier_type,
                        $name,
                        $document,
                        $phone,
                        $email,
                        $address,
                        $notes !== '' ? $notes : null,
                        $is_active,
                        agency_id()
                    ]);

                    header('Location: /suppliers/index.php'); 
                    exit;
                }
            } catch (PDOException $e) {
                error_log('suppliers/create.php: ' . $e->getMessage());
                $err = "Erro no banco de dados.";
            }
        }
    }
}

$token = csrf_token();
$pageTitle = 'Novo Fornecedor';
require __DIR__ . '/../inc/header.php';
?>

<div class="page-body">
    <div class="container-xl">
        <div class="row justify-content-center">
            <div class="col-md-10">
                <form class="card" method="post" autocomplete="off">
                    <div class="card-header"><h3 class="card-title">Cadastro de Fornecedor</h3></div>
                    <div class="card-body">
                        <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label">Tipo de Pessoa</label>
                            <div class="form-selectgroup">
                                <label class="form-selectgroup-item">
                                    <input type="radio" name="supplier_type" value="pf" class="form-selectgroup-input">
                                    <span class="form-selectgroup-label">Pessoa Física (PF)</span>
                                </label>
                                <label class="form-selectgroup-item">
                                    <input type="radio" name="supplier_type" value="pj" class="form-selectgroup-input" checked>
                                    <span class="form-selectgroup-label">Pessoa Jurídica (PJ)</span>
                                </label>
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Nome / Razão Social *</label>
                                <input class="form-control" name="name" id="nameField" required placeholder="Ex: NOME DO FORNECEDOR">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">CPF/CNPJ *</label>
                                <input class="form-control" name="document" required placeholder="00.000.000/0000-00">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Telefone</label>
                                <input class="form-control" name="phone" placeholder="(00) 00000-0000">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">E-mail</label>
                                <input class="form-control" type="email" name="email" placeholder="contato@fornecedor.com">
                            </div>

                            <div class="col-12">
                                <label class="form-label">Endereço Completo</label>
                                <input class="form-control" name="address" placeholder="Rua, Número, Bairro, Cidade...">
                            </div>

                            <div class="col-12">
                                <label class="form-label">Notas Adicionais</label>
                                <textarea class="form-control" rows="4" name="notes" placeholder="Observações importantes..."></textarea>
                            </div>

                            <div class="col-12">
                                <label class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_active" checked>
                                    <span class="form-check-label">Fornecedor Ativo</span>
                                </label>
                            </div>
                        </div>

                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">
                    </div>
                    <div class="card-footer d-flex justify-content-between bg-light">
                        <a href="/suppliers/index.php" class="btn btn-link">Cancelar</a>
                        <button class="btn btn-primary" type="submit">
                            <i class="ti ti-device-floppy me-1"></i> Salvar Fornecedor
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// جعل الاسم يكتب بحروف كبيرة تلقائياً
document.getElementById('nameField').addEventListener('input', function(){
    const pos = this.selectionStart;
    this.value = this.value.toUpperCase();
    this.setSelectionRange(pos, pos);
});
</script>

<?php require __DIR__ . '/../inc/footer.php'; ?>
