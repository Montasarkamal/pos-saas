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
ob_start();
?>
<div class="mx-auto max-w-3xl">
  <form method="post" autocomplete="off" class="space-y-5">
    <?php if ($err): ?>
      <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800" role="alert">
        <?= htmlspecialchars($err) ?>
      </div>
    <?php endif; ?>

    <div class="card overflow-hidden">
      <div class="border-b border-ink-100 px-5 py-4">
        <h3 class="text-sm font-bold text-ink-950">Cadastro de Fornecedor</h3>
      </div>
      <div class="p-5">
        <div class="mb-5">
          <label class="label-field">Tipo de Pessoa</label>
          <div class="rule-group">
            <label class="rule-radio">
              <input type="radio" name="supplier_type" value="pf">
              <span>Pessoa Física (PF)</span>
            </label>
            <label class="rule-radio">
              <input type="radio" name="supplier_type" value="pj" checked>
              <span>Pessoa Jurídica (PJ)</span>
            </label>
          </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div>
            <label class="label-field">Nome / Razão Social *</label>
            <input class="input-field" name="name" id="nameField" required placeholder="Ex: NOME DO FORNECEDOR">
          </div>
          <div>
            <label class="label-field">CPF/CNPJ *</label>
            <input class="input-field" name="document" required placeholder="00.000.000/0000-00">
          </div>

          <div>
            <label class="label-field">Telefone</label>
            <input class="input-field" name="phone" placeholder="(00) 00000-0000">
          </div>
          <div>
            <label class="label-field">E-mail</label>
            <input class="input-field" type="email" name="email" placeholder="contato@fornecedor.com">
          </div>

          <div class="md:col-span-2">
            <label class="label-field">Endereço Completo</label>
            <input class="input-field" name="address" placeholder="Rua, Número, Bairro, Cidade...">
          </div>
          <div class="md:col-span-2">
            <label class="label-field">Notas Adicionais</label>
            <textarea class="input-field" rows="4" name="notes" placeholder="Observações importantes..."></textarea>
          </div>

          <div class="md:col-span-2">
            <label class="switch-item">
              <input type="checkbox" name="is_active" checked>
              <span class="switch-track"><span class="switch-thumb"></span></span>
              <span class="switch-text">Fornecedor Ativo</span>
            </label>
          </div>
        </div>

        <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">
      </div>
    </div>

    <div class="flex flex-wrap items-center justify-end gap-2">
      <a class="btn-ghost" href="/suppliers/index.php">Cancelar</a>
      <button class="btn-primary px-6" type="submit">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
        Salvar Fornecedor
      </button>
    </div>
  </form>
</div>

<script>
// Nome em maiúsculas (visual)
(function(){
  const f = document.getElementById('nameField');
  if (!f) return;
  f.addEventListener('input', function(){
    const pos = this.selectionStart;
    this.value = this.value.toUpperCase();
    this.setSelectionRange(pos, pos);
  });
})();
</script>
<?php
$body = ob_get_clean();
require __DIR__ . '/../inc/layout.php';