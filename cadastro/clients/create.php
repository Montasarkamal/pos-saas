<?php
require __DIR__ . '/../inc/auth.php';
require_login();

/* ===== Helpers ===== */
function to_upper($s){ return mb_strtoupper(trim((string)$s),'UTF-8'); }
function br_to_mysql_date($s){
  $s = trim((string)$s);
  if ($s==='') return null;
  if (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~',$s,$m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
  return null;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $err = 'Sessão expirada. Atualize e tente novamente.';
  } else {
    $client_type   = (($_POST['client_type'] ?? 'pf') === 'pj') ? 'pj' : 'pf';
    $name          = to_upper($_POST['name'] ?? '');
    $document      = trim($_POST['document'] ?? '');
    $phone         = trim($_POST['phone'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $birth_date_in = $_POST['birth_date'] ?? '';
    $birth_date    = br_to_mysql_date($birth_date_in);
    $address       = trim($_POST['address'] ?? '');
    $notes         = trim($_POST['notes'] ?? '');
    $employer_name = trim($_POST['employer_name'] ?? '');
    $employer_id   = filter_var($_POST['employer_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['default'=>null]]);

    // NEW: gender
    $gender = $_POST['gender'] ?? null;
    if (!in_array($gender, ['M','F','O'], true)) { $gender = null; }

    if ($name === '') {
      $err = 'Nome é obrigatório.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $err = 'E-mail inválido.';
    } elseif ($document !== '') {
      $ck = $pdo->prepare("SELECT 1 FROM clients WHERE document=? AND agency_id=? LIMIT 1");
      $ck->execute([$document, agency_id()]);
      if ($ck->fetch()) $err = 'Documento (CPF/CNPJ) já cadastrado.';
    }

    if (!$err && $employer_id !== null) {
      $st = $pdo->prepare("SELECT id FROM clients WHERE id=? AND agency_id=? LIMIT 1");
      $st->execute([$employer_id, agency_id()]);
      if (!$st->fetch()) $err = 'Empresa selecionada é inválida.';
    }

    if (!$err) {
      $ins = $pdo->prepare("INSERT INTO clients
        (client_type, name, document, phone, email, birth_date, address, notes, employer_id, gender, agency_id, created_by, created_at, updated_at)
        VALUES (?,?,?,?,?,?, ?,?,?, ?, ?, ?, NOW(), NOW())");

      $ins->execute([
        $client_type,
        $name,
        ($document !== '' ? $document : null),
        $phone,
        $email,
        $birth_date,
        $address,
        ($notes !== '' ? $notes : null),
        $employer_id,
        $gender,
        agency_id(),
        $_SESSION['uid'] ?? null
      ]);

      header('Location: /clients/index.php'); exit;
    }
  }
}

$token = csrf_token();
$pageTitle = 'Novo Cliente';
require __DIR__ . '/../inc/header.php';

/* Prefill employer option when POST falha */
$prefilledEmployer = null;
if (!empty($_POST['employer_id'])) {
  $st = $pdo->prepare("SELECT id,name FROM clients WHERE id=? AND agency_id=? LIMIT 1");
  $st->execute([ (int)$_POST['employer_id'], agency_id() ]);
  $prefilledEmployer = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
?>
<div class="row justify-content-center">
  <div class="col-md-10">
    <form class="card" method="post" autocomplete="off">
      <div class="card-header"><h3 class="card-title">Cadastro de Cliente</h3></div>

      <div class="card-body">
        <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php endif; ?>

        <div class="mb-3">
          <label class="form-label">Tipo de Pessoa</label>
          <div class="form-selectgroup">
            <label class="form-selectgroup-item">
              <input type="radio" name="client_type" value="pf" class="form-selectgroup-input"
                     <?= (($_POST['client_type'] ?? 'pf') !== 'pj') ? 'checked' : '' ?>>
              <span class="form-selectgroup-label">Pessoa Física</span>
            </label>
            <label class="form-selectgroup-item">
              <input type="radio" name="client_type" value="pj" class="form-selectgroup-input"
                     <?= (($_POST['client_type'] ?? '') === 'pj') ? 'checked' : '' ?>>
              <span class="form-selectgroup-label">Pessoa Jurídica</span>
            </label>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-md-8">
            <label class="form-label">Nome / Razão Social *</label>
            <input class="form-control" name="name" id="nameField" required
                   value="<?= htmlspecialchars($_POST['name'] ?? '') ?>">
          </div>

          <div class="col-md-4">
            <label class="form-label">CPF/CNPJ</label>
            <input class="form-control" name="document" placeholder="Somente números"
                   value="<?= htmlspecialchars($_POST['document'] ?? '') ?>">
          </div>

          <div class="col-md-3">
            <label class="form-label">Data de Nascimento</label>
            <input
              class="form-control"
              type="text"
              name="birth_date"
              placeholder="DD/MM/YYYY"
              inputmode="numeric"
              autocomplete="off"
              value="<?= htmlspecialchars($_POST['birth_date'] ?? '') ?>">
          </div>

          <!-- NEW: Gender -->
          <div class="col-md-3">
            <label class="form-label">Gênero</label>
            <select class="form-select" name="gender">
              <option value="">Selecione</option>
              <option value="M" <?= (($_POST['gender'] ?? '')==='M')?'selected':'' ?>>Masculino</option>
              <option value="F" <?= (($_POST['gender'] ?? '')==='F')?'selected':'' ?>>Feminino</option>
              <option value="O" <?= (($_POST['gender'] ?? '')==='O')?'selected':'' ?>>Outro/Não informado</option>
            </select>
          </div>

          <div class="col-md-3">
            <label class="form-label">Telefone</label>
            <input class="form-control" name="phone" placeholder="(00) 00000-0000"
                   value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
          </div>

          <div class="col-md-6">
            <label class="form-label">E-mail</label>
            <input class="form-control" type="email" name="email" placeholder="email@dominio.com"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
          </div>

          <!-- Employer: input + hidden id -->
          <div class="col-md-6">
            <label class="form-label">Trabalha Na</label>
            <input class="form-control" id="employer_name" name="employer_name"
                   placeholder="trabalha na "
                   value="<?= htmlspecialchars($_POST['employer_name'] ?? ($prefilledEmployer['name'] ?? '')) ?>">
            <input type="hidden" id="employer_id" name="employer_id"
                   value="<?= htmlspecialchars($_POST['employer_id'] ?? ($prefilledEmployer['id'] ?? '')) ?>">
          </div>

          <div class="col-12">
            <label class="form-label">Endereço</label>
            <input class="form-control" name="address" placeholder="Rua, número, bairro, cidade/UF"
                   value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">
          </div>

          <div class="col-12">
            <label class="form-label">Notas</label>
            <textarea class="form-control" rows="10" name="notes" placeholder="Observações internas..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
          </div>
        </div>

        <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">
      </div>

      <div class="card-footer d-flex justify-content-between">
        <a href="/clients/index.php" class="btn">Cancelar</a>
        <button class="btn btn-primary" type="submit">
          <i class="ti ti-device-floppy me-1"></i> Salvar
        </button>
      </div>
    </form>
  </div>
</div>

<script>
// Uppercase بصري للاسم
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

<style>.ui-autocomplete{z-index:3000!important;max-height:260px;overflow:auto}</style>
<script>
jQuery(function($){
  if (!$.ui || !$.fn.autocomplete) { console.error('jQuery UI غير محمّل'); return; }
  $('#employer_name').autocomplete({
    minLength: 2,
    delay: 0,
    appendTo: 'body',
    source: function(req, res){
      $.ajax({
        url: './search_clients.php',
        dataType: 'json',
        data: { q: req.term },
        success: function(data){
          const arr = Array.isArray(data.results) ? data.results : [];
          res(arr.map(r => ({label:r.text, value:r.text, id:r.id})));
        },
        error: function(xhr){ console.error('AJAX', xhr.status, xhr.responseText); res([]); }
      });
    },
    select: function(_e, ui){ $('#employer_id').val(ui.item.id); },
    change: function(_e, ui){ if(!ui.item) $('#employer_id').val(''); }
  });
});
</script>

<?php require __DIR__ . '/../inc/footer.php'; ?>
