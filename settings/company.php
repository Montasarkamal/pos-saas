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
require_once __DIR__ . '/../inc/header.php';
?>

<div class="page-header d-print-none mb-3">
  <div class="row align-items-center">
    <div class="col">
      <h2 class="page-title">Dados da Empresa</h2>
      <div class="text-muted small">Campos preenchidos pela base atual. Edite e salve para gravar na tabela da empresa.</div>
    </div>
    <div class="col-auto ms-auto">
      <a href="/settings/index.php" class="btn">
        <i class="ti ti-arrow-left me-1"></i> Voltar
      </a>
      <button class="btn btn-primary" form="companyForm">
        <i class="ti ti-device-floppy me-1"></i> Salvar
      </button>
    </div>
  </div>
</div>

<?php if ($msg === 'saved'): ?>
  <div class="alert alert-success">Dados da empresa salvos com sucesso.</div>
<?php endif; ?>
<?php if ($err !== ''): ?>
  <div class="alert alert-danger"><?= h($err) ?></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" id="companyForm" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= h($token) ?>">

  <div class="card mb-3">
    <div class="card-header"><h3 class="card-title">Dados da Agência</h3></div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">CNPJ</label>
          <input class="form-control" name="cnpj" value="<?= h($companyValues['cnpj']) ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Razão Social</label>
          <input class="form-control" name="legal_name" value="<?= h($companyValues['legal_name']) ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Nome Fantasia</label>
          <input class="form-control" name="fantasy_name" value="<?= h($companyValues['fantasy_name']) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Email</label>
          <input class="form-control" type="email" name="email" value="<?= h($companyValues['email']) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Fone</label>
          <input class="form-control" name="phone" value="<?= h($companyValues['phone']) ?>">
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header"><h3 class="card-title">Endereço Comercial</h3></div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-3"><label class="form-label">CEP</label><input class="form-control" name="cep" value="<?= h($companyValues['cep']) ?>"></div>
        <div class="col-md-6"><label class="form-label">Logradouro</label><input class="form-control" name="street" value="<?= h($companyValues['street']) ?>"></div>
        <div class="col-md-3"><label class="form-label">Número</label><input class="form-control" name="number" value="<?= h($companyValues['number']) ?>"></div>
        <div class="col-md-4"><label class="form-label">Bairro</label><input class="form-control" name="district" value="<?= h($companyValues['district']) ?>"></div>
        <div class="col-md-4"><label class="form-label">Complemento</label><input class="form-control" name="complement" value="<?= h($companyValues['complement']) ?>"></div>
        <div class="col-md-3"><label class="form-label">Cidade</label><input class="form-control" name="city" value="<?= h($companyValues['city']) ?>"></div>
        <div class="col-md-1"><label class="form-label">UF</label><input class="form-control" name="uf" maxlength="2" value="<?= h($companyValues['uf']) ?>"></div>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header"><h3 class="card-title">Dados Bancários</h3></div>
    <div class="card-body">
      <textarea class="form-control" name="bank_details" rows="5"><?= h($companyValues['bank_details']) ?></textarea>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header"><h3 class="card-title">Arquivos</h3></div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Logo</label>
          <input class="form-control" type="file" name="logo" accept="image/*">
          <?php if ($companyValues['logo_path'] !== ''): ?><div class="small text-muted mt-1"><?= h($companyValues['logo_path']) ?></div><?php endif; ?>
        </div>
        <div class="col-md-4">
          <label class="form-label">Carimbo</label>
          <input class="form-control" type="file" name="stamp" accept="image/*">
          <?php if ($companyValues['stamp_path'] !== ''): ?><div class="small text-muted mt-1"><?= h($companyValues['stamp_path']) ?></div><?php endif; ?>
        </div>
        <div class="col-md-4">
          <label class="form-label">Favicon</label>
          <input class="form-control" type="file" name="favicon" accept="image/*,.ico">
          <?php if ($companyValues['favicon_path'] !== ''): ?><div class="small text-muted mt-1"><?= h($companyValues['favicon_path']) ?></div><?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</form>

<?php require_once __DIR__ . '/../inc/footer.php'; ?>
