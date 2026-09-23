<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';
require_login();
require_role_admin();
require_once __DIR__ . '/../inc/company.php';

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function company_field(array $agency, string $field, ?string $fallback = null): string {
    $value = trim((string)($agency[$field] ?? ''));
    if ($value !== '') return $value;
    return trim((string)($fallback ?? ''));
}

function upload_company_asset(string $field, int $agencyId): ?string {
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) return null;
    if (($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($_FILES[$field]['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) return null;

    $tmp = (string)($_FILES[$field]['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) return null;

    $allowed = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
    ];

    $mime = mime_content_type($tmp) ?: '';
    if (!isset($allowed[$mime])) return null;

    $dir = dirname(__DIR__) . '/uploads/agencies/' . $agencyId;
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    $filename = $field . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $dest = $dir . '/' . $filename;
    if (!move_uploaded_file($tmp, $dest)) return null;

    return '/uploads/agencies/' . $agencyId . '/' . $filename;
}

$err = '';
$msg = (string)($_GET['msg'] ?? '');
$agencyId = agency_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        $err = 'Sessão expirada. Recarregue a página e tente novamente.';
    } else {
        try {
            $fields = [
                'cnpj' => trim((string)($_POST['cnpj'] ?? '')),
                'legal_name' => trim((string)($_POST['legal_name'] ?? '')),
                'fantasy_name' => trim((string)($_POST['fantasy_name'] ?? '')),
                'email' => trim((string)($_POST['email'] ?? '')),
                'phone' => trim((string)($_POST['phone'] ?? '')),
                'cep' => trim((string)($_POST['cep'] ?? '')),
                'street' => trim((string)($_POST['street'] ?? '')),
                'district' => trim((string)($_POST['district'] ?? '')),
                'complement' => trim((string)($_POST['complement'] ?? '')),
                'number' => trim((string)($_POST['number'] ?? '')),
                'city' => trim((string)($_POST['city'] ?? '')),
                'uf' => strtoupper(substr(trim((string)($_POST['uf'] ?? '')), 0, 2)),
                'bank_details' => trim((string)($_POST['bank_details'] ?? '')),
            ];

            if ($fields['fantasy_name'] === '' && $fields['legal_name'] === '') {
                $err = 'Informe pelo menos o nome fantasia ou a razão social.';
            } elseif ($fields['email'] !== '' && !filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
                $err = 'Email inválido.';
            }

            if ($err === '') {
                $fields['name'] = $fields['fantasy_name'] !== '' ? $fields['fantasy_name'] : $fields['legal_name'];

                foreach (['logo' => 'logo_path', 'stamp' => 'stamp_path', 'favicon' => 'favicon_path'] as $input => $column) {
                    $path = upload_company_asset($input, $agencyId);
                    if ($path !== null) $fields[$column] = $path;
                }

                $sets = [];
                $vals = [];
                foreach ($fields as $column => $value) {
                    $sets[] = "`{$column}`=?";
                    $vals[] = $value === '' ? null : $value;
                }
                $vals[] = $agencyId;

                $sql = "UPDATE agencies SET " . implode(',', $sets) . " WHERE id=? LIMIT 1";
                $st = $pdo->prepare($sql);
                $st->execute($vals);

                header('Location: /settings/company.php?msg=saved');
                exit;
            }
        } catch (Throwable $e) {
            error_log('[SETTINGS_COMPANY_SAVE] ' . $e->getMessage());
            $err = 'Não foi possível salvar os dados da empresa.';
        }
    }
}

$st = $pdo->prepare("SELECT * FROM agencies WHERE id=? LIMIT 1");
$st->execute([$agencyId]);
$agency = $st->fetch(PDO::FETCH_ASSOC) ?: [];

$companyValues = [
    'cnpj' => company_field($agency, 'cnpj', COMPANY_CNPJ),
    'legal_name' => company_field($agency, 'legal_name', company_field($agency, 'name', COMPANY_NAME)),
    'fantasy_name' => company_field($agency, 'fantasy_name', company_field($agency, 'name', COMPANY_NAME)),
    'email' => company_field($agency, 'email', COMPANY_EMAIL),
    'phone' => company_field($agency, 'phone', COMPANY_WHATS),
    'cep' => company_field($agency, 'cep'),
    'street' => company_field($agency, 'street', COMPANY_ADDR),
    'district' => company_field($agency, 'district'),
    'complement' => company_field($agency, 'complement'),
    'number' => company_field($agency, 'number'),
    'city' => company_field($agency, 'city'),
    'uf' => company_field($agency, 'uf'),
    'bank_details' => company_field($agency, 'bank_details'),
    'logo_path' => company_field($agency, 'logo_path'),
    'stamp_path' => company_field($agency, 'stamp_path'),
    'favicon_path' => company_field($agency, 'favicon_path'),
];

$token = csrf_token();
$pageTitle = 'Dados da Empresa';
ob_start();
?>
<div class="mx-auto max-w-4xl">

  <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
      <p class="text-xs font-bold uppercase tracking-wider text-brand-600">Configurações</p>
      <h2 class="text-xl font-bold text-ink-950">Dados da Empresa</h2>
      <p class="mt-1 text-sm text-ink-500">Campos preenchidos pela base atual. Edite e salve para gravar na tabela da empresa.</p>
    </div>
    <div class="flex items-center gap-2">
      <a href="/settings/index.php" class="btn-ghost">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"></path><path d="M12 19l-7-7 7-7"></path></svg>
        Voltar
      </a>
      <button class="btn-primary" form="companyForm">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
        Salvar
      </button>
    </div>
  </div>

  <?php if ($msg === 'saved'): ?>
    <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800" role="alert">
      Dados da empresa salvos com sucesso.
    </div>
  <?php endif; ?>
  <?php if ($err !== ''): ?>
    <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800" role="alert">
      <?= h($err) ?>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" id="companyForm" autocomplete="off" class="space-y-5">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">

    <div class="card overflow-hidden">
      <div class="border-b border-ink-100 px-5 py-4">
        <h3 class="text-sm font-bold text-ink-950">Dados da Agência</h3>
      </div>
      <div class="p-5">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <div>
            <label class="label-field" for="cnpj">CNPJ</label>
            <input class="input-field" id="cnpj" name="cnpj" value="<?= h($companyValues['cnpj']) ?>">
          </div>
          <div>
            <label class="label-field" for="legal_name">Razão Social</label>
            <input class="input-field" id="legal_name" name="legal_name" value="<?= h($companyValues['legal_name']) ?>">
          </div>
          <div>
            <label class="label-field" for="fantasy_name">Nome Fantasia</label>
            <input class="input-field" id="fantasy_name" name="fantasy_name" value="<?= h($companyValues['fantasy_name']) ?>">
          </div>
          <div>
            <label class="label-field" for="email">Email</label>
            <input class="input-field" id="email" type="email" name="email" value="<?= h($companyValues['email']) ?>">
          </div>
          <div>
            <label class="label-field" for="phone">Fone</label>
            <input class="input-field" id="phone" name="phone" value="<?= h($companyValues['phone']) ?>">
          </div>
        </div>
      </div>
    </div>

    <div class="card overflow-hidden">
      <div class="border-b border-ink-100 px-5 py-4">
        <h3 class="text-sm font-bold text-ink-950">Endereço Comercial</h3>
      </div>
      <div class="p-5">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
          <div class="lg:col-span-1">
            <label class="label-field" for="cep">CEP</label>
            <input class="input-field" id="cep" name="cep" value="<?= h($companyValues['cep']) ?>">
          </div>
          <div class="lg:col-span-3">
            <label class="label-field" for="street">Logradouro</label>
            <input class="input-field" id="street" name="street" value="<?= h($companyValues['street']) ?>">
          </div>
          <div class="lg:col-span-1">
            <label class="label-field" for="number">Número</label>
            <input class="input-field" id="number" name="number" value="<?= h($companyValues['number']) ?>">
          </div>
          <div class="lg:col-span-1">
            <label class="label-field" for="uf">UF</label>
            <input class="input-field" id="uf" name="uf" maxlength="2" value="<?= h($companyValues['uf']) ?>">
          </div>
          <div class="lg:col-span-2">
            <label class="label-field" for="district">Bairro</label>
            <input class="input-field" id="district" name="district" value="<?= h($companyValues['district']) ?>">
          </div>
          <div class="lg:col-span-2">
            <label class="label-field" for="complement">Complemento</label>
            <input class="input-field" id="complement" name="complement" value="<?= h($companyValues['complement']) ?>">
          </div>
          <div class="lg:col-span-2">
            <label class="label-field" for="city">Cidade</label>
            <input class="input-field" id="city" name="city" value="<?= h($companyValues['city']) ?>">
          </div>
        </div>
      </div>
    </div>

    <div class="card overflow-hidden">
      <div class="border-b border-ink-100 px-5 py-4">
        <h3 class="text-sm font-bold text-ink-950">Dados Bancários</h3>
      </div>
      <div class="p-5">
        <textarea class="input-field" name="bank_details" rows="5"><?= h($companyValues['bank_details']) ?></textarea>
      </div>
    </div>

    <div class="card overflow-hidden">
      <div class="border-b border-ink-100 px-5 py-4">
        <h3 class="text-sm font-bold text-ink-950">Arquivos</h3>
      </div>
      <div class="p-5">
        <div class="grid gap-4 sm:grid-cols-3">
          <div>
            <label class="label-field" for="logo">Logo</label>
            <input class="input-field file:mr-3 file:rounded-lg file:border-0 file:bg-ink-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-ink-700" id="logo" type="file" name="logo" accept="image/*">
            <?php if ($companyValues['logo_path'] !== ''): ?>
              <p class="mt-1.5 truncate text-xs text-ink-500" title="<?= h($companyValues['logo_path']) ?>"><?= h($companyValues['logo_path']) ?></p>
            <?php endif; ?>
          </div>
          <div>
            <label class="label-field" for="stamp">Carimbo</label>
            <input class="input-field file:mr-3 file:rounded-lg file:border-0 file:bg-ink-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-ink-700" id="stamp" type="file" name="stamp" accept="image/*">
            <?php if ($companyValues['stamp_path'] !== ''): ?>
              <p class="mt-1.5 truncate text-xs text-ink-500" title="<?= h($companyValues['stamp_path']) ?>"><?= h($companyValues['stamp_path']) ?></p>
            <?php endif; ?>
          </div>
          <div>
            <label class="label-field" for="favicon">Favicon</label>
            <input class="input-field file:mr-3 file:rounded-lg file:border-0 file:bg-ink-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-ink-700" id="favicon" type="file" name="favicon" accept="image/*,.ico">
            <?php if ($companyValues['favicon_path'] !== ''): ?>
              <p class="mt-1.5 truncate text-xs text-ink-500" title="<?= h($companyValues['favicon_path']) ?>"><?= h($companyValues['favicon_path']) ?></p>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </form>

</div>
<?php
$body = ob_get_clean();
require __DIR__ . '/../inc/layout.php';
