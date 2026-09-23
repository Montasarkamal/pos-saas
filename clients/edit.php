<?php
require __DIR__ . '/../inc/auth.php';
require_login();

/* Helpers */
function to_upper($s){ return mb_strtoupper(trim((string)$s),'UTF-8'); }
function br_to_mysql_date($s){
  $s = trim((string)$s);
  if ($s==='') return null;
  if (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~',$s,$m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
  return null;
}
function mysql_to_br_date($s){
  if(!$s) return '';
  if (preg_match('~^(\d{4})-(\d{2})-(\d{2})$~',$s,$m)) return "{$m[3]}/{$m[2]}/{$m[1]}";
  return '';
}

/* Load target id */
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); echo 'ID inválido.'; exit; }
[$agencyCondition, $agencyParams] = agency_scope_sql('c.agency_id');

/* Fetch record + employer name */
$sql = "SELECT c.*, e.name AS employer_name
        FROM clients c
        LEFT JOIN clients e ON e.id = c.employer_id AND e.agency_id = c.agency_id
        WHERE c.id = ? AND $agencyCondition LIMIT 1";
$st = $pdo->prepare($sql);
$st->execute(array_merge([$id], $agencyParams));
$client = $st->fetch(PDO::FETCH_ASSOC);
if (!$client) { http_response_code(404); echo 'Registro não encontrado.'; exit; }
$recordAgencyId = (int)($client['agency_id'] ?? agency_id());

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
    $gender        = $_POST['gender'] ?? null;
    if (!in_array($gender, ['M','F','O'], true)) { $gender = null; }

    if ($name === '') {
      $err = 'Nome é obrigatório.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $err = 'E-mail inválido.';
    } elseif ($document !== '') {
      $ck = $pdo->prepare("SELECT 1 FROM clients WHERE document=? AND id<>? AND agency_id=? LIMIT 1");
      $ck->execute([$document, $id, $recordAgencyId]);
      if ($ck->fetch()) $err = 'Documento (CPF/CNPJ) já cadastrado em outro cliente.';
    }

    if (!$err && $employer_id !== null) {
      $st2 = $pdo->prepare("SELECT id FROM clients WHERE id=? AND agency_id=? LIMIT 1");
      $st2->execute([$employer_id, $recordAgencyId]);
      if (!$st2->fetch()) $err = 'Empresa selecionada é inválida.';
    }

    if (!$err) {
      $up = $pdo->prepare("UPDATE clients SET
          client_type=?, name=?, document=?, phone=?, email=?, birth_date=?,
          address=?, notes=?, employer_id=?, gender=?, updated_at=NOW()
        WHERE id=? AND agency_id=? LIMIT 1");
      $up->execute([
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
        $id,
        $recordAgencyId
      ]);
      header('Location: /clients/index.php'); exit;
    }

    $client = array_merge($client, [
      'client_type'   => $client_type,
      'name'          => $name,
      'document'      => $document,
      'phone'         => $phone,
      'email'         => $email,
      'birth_date'    => $birth_date,
      'address'       => $address,
      'notes'         => $notes,
      'employer_id'   => $employer_id,
      'employer_name' => $employer_name,
      'gender'        => $gender,
    ]);
  }
}

$token = csrf_token();
$pageTitle = 'Editar Cliente';
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
        <h3 class="text-sm font-bold text-ink-950">Editar Cliente #<?= (int)$id ?></h3>
      </div>
      <div class="p-5">
        <div class="mb-5">
          <label class="label-field">Tipo de Pessoa</label>
          <div class="rule-group">
            <label class="rule-radio">
              <input type="radio" name="client_type" value="pf" <?= ($client['client_type'] ?? 'pf') !== 'pj' ? 'checked' : '' ?>>
              <span>Pessoa Física</span>
            </label>
            <label class="rule-radio">
              <input type="radio" name="client_type" value="pj" <?= ($client['client_type'] ?? '') === 'pj' ? 'checked' : '' ?>>
              <span>Pessoa Jurídica</span>
            </label>
          </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-12">
          <div class="md:col-span-8">
            <label class="label-field">Nome / Razão Social *</label>
            <input class="input-field" name="name" id="nameField" required value="<?= htmlspecialchars($client['name'] ?? '') ?>">
          </div>
          <div class="md:col-span-4">
            <label class="label-field">CPF/CNPJ</label>
            <input class="input-field" name="document" placeholder="Somente números" value="<?= htmlspecialchars($client['document'] ?? '') ?>">
          </div>

          <div class="md:col-span-3">
            <label class="label-field">Data de Nascimento</label>
            <input class="input-field" type="text" name="birth_date" placeholder="DD/MM/YYYY" inputmode="numeric" autocomplete="off" value="<?= htmlspecialchars(mysql_to_br_date($client['birth_date'] ?? null)) ?>">
          </div>
          <div class="md:col-span-3">
            <label class="label-field">Gênero</label>
            <select class="select-field" name="gender">
              <option value="">Selecione</option>
              <option value="M" <?= (($_POST['gender'] ?? ($client['gender'] ?? '')) === 'M') ? 'selected' : '' ?>>Masculino</option>
              <option value="F" <?= (($_POST['gender'] ?? ($client['gender'] ?? '')) === 'F') ? 'selected' : '' ?>>Feminino</option>
              <option value="O" <?= (($_POST['gender'] ?? ($client['gender'] ?? '')) === 'O') ? 'selected' : '' ?>>Outro/Não informado</option>
            </select>
          </div>
          <div class="md:col-span-3">
            <label class="label-field">Telefone</label>
            <input class="input-field" name="phone" placeholder="(00) 00000-0000" value="<?= htmlspecialchars($client['phone'] ?? '') ?>">
          </div>
          <div class="md:col-span-6">
            <label class="label-field">E-mail</label>
            <input class="input-field" type="email" name="email" placeholder="email@dominio.com" value="<?= htmlspecialchars($client['email'] ?? '') ?>">
          </div>

          <div class="autocomplete-wrap md:col-span-6">
            <label class="label-field" for="employer_name">Trabalha Na</label>
            <input class="input-field" id="employer_name" name="employer_name" placeholder="trabalha na" autocomplete="off"
                   data-search-url="../clients/search_clients.php"
                   value="<?= htmlspecialchars($client['employer_name'] ?? '') ?>">
            <input type="hidden" id="employer_id" name="employer_id" value="<?= htmlspecialchars($client['employer_id'] ?? '') ?>">
            <div id="employerSuggest" class="autocomplete-box is-hidden" role="listbox"></div>
          </div>

          <div class="md:col-span-12">
            <label class="label-field">Endereço</label>
            <input class="input-field" name="address" placeholder="Rua, número, bairro, cidade/UF" value="<?= htmlspecialchars($client['address'] ?? '') ?>">
          </div>
          <div class="md:col-span-12">
            <label class="label-field">Notas</label>
            <textarea class="input-field" rows="6" name="notes" placeholder="Observações internas..."><?= htmlspecialchars($client['notes'] ?? '') ?></textarea>
          </div>
        </div>

        <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">
      </div>
    </div>

    <div class="flex flex-wrap items-center justify-end gap-2">
      <a class="btn-ghost" href="/clients/index.php">Cancelar</a>
      <button class="btn-primary px-6" type="submit">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
        Salvar
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

<script>
/* Trabalha Na — autocomplete vanilla (substitui jQuery UI, mesmo endpoint) */
(function(){
  const input = document.getElementById('employer_name');
  const hid   = document.getElementById('employer_id');
  const box   = document.getElementById('employerSuggest');
  if (!input || !hid || !box) return;
  const url = input.getAttribute('data-search-url') || '';
  let timer = null;
  let selected = false;

  function esc(s){ return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
  function close(){ box.classList.add('is-hidden'); box.innerHTML = ''; }

  input.addEventListener('input', function(){
    clearTimeout(timer);
    const q = this.value.trim();
    if (q.length < 2) { close(); return; }
    timer = setTimeout(async () => {
      try {
        const sep = url.indexOf('?') >= 0 ? '&' : '?';
        const resp = await fetch(url + sep + 'q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } });
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const data = await resp.json();
        const arr = Array.isArray(data.results) ? data.results : [];
        if (!arr.length) {
          box.innerHTML = '<div class="autocomplete-empty">Nenhum resultado.</div>';
          box.classList.remove('is-hidden');
          return;
        }
        box.innerHTML = arr.map(r =>
          '<button type="button" class="autocomplete-item" role="option" data-id="' + esc(r.id) + '" data-label="' + esc(r.text) + '">' + esc(r.text) + '</button>'
        ).join('');
        box.classList.remove('is-hidden');
      } catch (e) {
        console.error('Autocomplete falhou', e);
        close();
      }
    }, 200);
  });

  box.addEventListener('click', function(e){
    const btn = e.target.closest('.autocomplete-item');
    if (!btn) return;
    hid.value = btn.getAttribute('data-id');
    input.value = btn.getAttribute('data-label');
    selected = true;
    close();
    input.focus();
  });

  input.addEventListener('blur', function(){
    setTimeout(function(){
      if (!selected) hid.value = '';
      close();
    }, 150);
  });

  input.addEventListener('focus', function(){
    if ((input.value || '').trim().length >= 2) input.dispatchEvent(new Event('input'));
  });
})();
</script>
<?php
$body = ob_get_clean();
require __DIR__ . '/../inc/layout.php';