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
        <h3 class="text-sm font-bold text-ink-950">Editar Fornecedor #<?= (int)$s['id'] ?></h3>
      </div>
      <div class="p-5">
        <div class="mb-5">
          <label class="label-field">Tipo de Pessoa</label>
          <div class="rule-group">
            <label class="rule-radio">
              <input type="radio" name="supplier_type" value="pf" <?= $s['supplier_type'] === 'pf' ? 'checked' : '' ?>>
              <span>Pessoa Física</span>
            </label>
            <label class="rule-radio">
              <input type="radio" name="supplier_type" value="pj" <?= $s['supplier_type'] === 'pj' ? 'checked' : '' ?>>
              <span>Pessoa Jurídica</span>
            </label>
          </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <div>
            <label class="label-field">Nome / Razão Social *</label>
            <input class="input-field" name="name" id="nameField" value="<?= htmlspecialchars($s['name']) ?>" required>
          </div>
          <div>
            <label class="label-field">Documento (CPF/CNPJ) *</label>
            <input class="input-field" name="document" value="<?= htmlspecialchars($s['document']) ?>" required>
          </div>

          <div>
            <label class="label-field">Telefone</label>
            <input class="input-field" name="phone" value="<?= htmlspecialchars($s['phone']) ?>">
          </div>
          <div>
            <label class="label-field">E-mail</label>
            <input class="input-field" type="email" name="email" value="<?= htmlspecialchars($s['email']) ?>">
          </div>

          <div class="md:col-span-2">
            <label class="label-field">Endereço</label>
            <input class="input-field" name="address" value="<?= htmlspecialchars($s['address']) ?>">
          </div>
          <div class="md:col-span-2">
            <label class="label-field">Notas</label>
            <textarea class="input-field" rows="5" name="notes"><?= htmlspecialchars($s['notes'] ?? '') ?></textarea>
          </div>

          <div class="md:col-span-2">
            <label class="switch-item">
              <input type="checkbox" name="is_active" <?= !empty($s['is_active']) ? 'checked' : '' ?>>
              <span class="switch-track"><span class="switch-thumb"></span></span>
              <span class="switch-text">Fornecedor Ativo</span>
            </label>
          </div>
        </div>

        <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">
      </div>
    </div>

    <div class="flex flex-wrap items-center justify-end gap-2">
      <a class="btn-ghost" href="/suppliers/show.php?id=<?= $id ?>">Cancelar</a>
      <button class="btn-primary px-6" type="submit">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
        Salvar Alterações
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