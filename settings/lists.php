<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';
require_login();
require_role_admin();
require_once __DIR__ . '/../inc/list_store.php';

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function upload_airline_logo(array $file, string $code): ?string {
  if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
  if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) return null;
  $tmp = (string)($file['tmp_name'] ?? '');
  if ($tmp === '' || !is_uploaded_file($tmp)) return null;

  $allowed = [
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'image/webp' => 'webp',
    'image/svg+xml' => 'svg',
  ];
  $mime = mime_content_type($tmp) ?: '';
  if (!isset($allowed[$mime])) return null;

  $dir = dirname(__DIR__) . '/uploads/airlines';
  if (!is_dir($dir)) mkdir($dir, 0775, true);

  $safeCode = preg_replace('/[^A-Z0-9]+/', '', strtoupper($code)) ?: 'AIR';
  $filename = $safeCode . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
  $dest = $dir . '/' . $filename;
  if (!move_uploaded_file($tmp, $dest)) return null;
  return '/uploads/airlines/' . $filename;
}

$err = '';
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $err = 'Sessão expirada. Recarregue a página e tente novamente.';
  } else {
    try {
      $airCodes = $_POST['airline_code'] ?? [];
      $airLabels = $_POST['airline_label'] ?? [];
      $airLogos = $_POST['airline_logo'] ?? [];
      $airLogoFiles = $_FILES['airline_logo_file'] ?? null;
      $airlines = [];
      $nAir = max(count((array)$airCodes), count((array)$airLabels));
      for ($i = 0; $i < $nAir; $i++) {
        $code = strtoupper(trim((string)($airCodes[$i] ?? '')));
        $logo = trim((string)($airLogos[$i] ?? ''));
        if (is_array($airLogoFiles) && isset($airLogoFiles['name'][$i])) {
          $file = [
            'name' => $airLogoFiles['name'][$i],
            'type' => $airLogoFiles['type'][$i] ?? '',
            'tmp_name' => $airLogoFiles['tmp_name'][$i] ?? '',
            'error' => $airLogoFiles['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $airLogoFiles['size'][$i] ?? 0,
          ];
          $uploadedLogo = upload_airline_logo($file, $code);
          if ($uploadedLogo !== null) $logo = $uploadedLogo;
        }
        $airlines[] = [
          'code' => $code,
          'label' => (string)($airLabels[$i] ?? ''),
          'logo' => $logo,
        ];
      }

      list_store_save('airlines', $airlines);
      list_store_save('classes', (array)($_POST['classes'] ?? []));
      list_store_save('baggage', (array)($_POST['baggage'] ?? []));

      header('Location: /settings/lists.php?msg=saved');
      exit;
    } catch (Throwable $e) {
      error_log('[SETTINGS_LISTS] ' . $e->getMessage());
      $err = 'Não foi possível salvar as listas.';
    }
  }
}

$lists = list_store_all();
$token = csrf_token();
$msg = $_GET['msg'] ?? '';
$pageTitle = 'Listas do Sistema';
require_once __DIR__ . '/../inc/header.php';
?>

<style>
.settings-toolbar {
  display: flex;
  gap: .75rem;
  flex-wrap: wrap;
  align-items: center;
}
.settings-tabs {
  display: flex;
  gap: .5rem;
  flex-wrap: wrap;
  margin-bottom: 1rem;
}
.settings-tab {
  border: 1px solid #dbe3ee;
  background: #fff;
  border-radius: 8px;
  padding: .55rem .82rem;
  font-weight: 700;
  color: #475569;
}
.settings-tab.active {
  border-color: #206bc4;
  color: #206bc4;
  background: #eef6ff;
}
.settings-panel { display: none; }
.settings-panel.active { display: block; }
.settings-table input { min-width: 0; }
.settings-actions { width: 64px; }
.airline-logo-box {
  width: 56px;
  height: 40px;
  border: 1px solid #dbe3ee;
  border-radius: 8px;
  background: #f8fafc;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  color: #94a3b8;
}
.airline-logo-box i {
  opacity: .55;
  font-size: .95rem;
}
.airline-logo-box img {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
  display: block;
}
.airline-logo-cell {
  display: grid;
  grid-template-columns: 56px minmax(180px, 1fr);
  gap: .65rem;
  align-items: center;
  min-width: 310px;
}
.airline-file { max-width: 100%; }
.settings-summary {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: .75rem;
  margin-bottom: 1rem;
}
.settings-summary-item {
  border: 1px solid #dbe3ee;
  border-radius: 8px;
  background: #fff;
  padding: 1rem;
}
.settings-toolbar .btn,
.settings-tab,
[data-add-row],
[data-remove-row] {
  justify-content: center;
}
[data-add-row],
[data-remove-row] {
  min-width: 0;
}
[data-add-row] i,
[data-remove-row] i {
  margin-right: .22rem !important;
}
@media (max-width: 768px) {
  .settings-summary { grid-template-columns: 1fr; }
  .airline-logo-cell { grid-template-columns: 1fr; min-width: 220px; }
}
</style>

<div class="page-header d-print-none mb-3">
  <div class="row align-items-center">
    <div class="col">
      <h2 class="page-title">Listas do Sistema</h2>
      <div class="text-muted small">Classes, bagagens e companhias aéreas usadas nas vendas.</div>
    </div>
    <div class="col-auto ms-auto">
      <div class="settings-toolbar">
        <a href="/settings/index.php" class="btn"><i class="ti ti-arrow-left me-1"></i> Voltar</a>
        <button class="btn btn-primary" form="listsForm">
          <i class="ti ti-device-floppy me-1"></i> Salvar listas
        </button>
      </div>
    </div>
  </div>
</div>

<?php if ($msg === 'saved'): ?>
  <div class="alert alert-success">Listas salvas com sucesso.</div>
<?php endif; ?>
<?php if ($err !== ''): ?>
  <div class="alert alert-danger"><?= h($err) ?></div>
<?php endif; ?>

<form method="post" id="listsForm" autocomplete="off" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?= h($token) ?>">

  <div class="settings-summary">
    <div class="settings-summary-item">
      <div class="text-muted small">Classes</div>
      <div class="h2 m-0"><?= count($lists['classes']) ?></div>
    </div>
    <div class="settings-summary-item">
      <div class="text-muted small">Bagagens</div>
      <div class="h2 m-0"><?= count($lists['baggage']) ?></div>
    </div>
    <div class="settings-summary-item">
      <div class="text-muted small">Companhias aéreas</div>
      <div class="h2 m-0"><?= count($lists['airlines']) ?></div>
    </div>
  </div>

  <div class="settings-tabs" role="tablist">
    <button type="button" class="settings-tab active" data-settings-tab="classes"><i class="ti ti-armchair me-1"></i> Classes</button>
    <button type="button" class="settings-tab" data-settings-tab="baggage"><i class="ti ti-luggage me-1"></i> Bagagem</button>
    <button type="button" class="settings-tab" data-settings-tab="airlines"><i class="ti ti-plane me-1"></i> Companhias aéreas</button>
  </div>

  <div>
    <div class="card settings-panel active" data-settings-panel="classes">
      <div class="card-header">
        <h3 class="card-title">Classes</h3>
        <div class="ms-auto">
          <button type="button" class="btn btn-outline-primary btn-sm" data-add-row="classes">
            <i class="ti ti-plus"></i> Adicionar
          </button>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-vcenter settings-table" data-table="classes">
          <thead>
            <tr>
              <th>Nome</th>
              <th class="settings-actions"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($lists['classes'] as $class): ?>
              <tr>
                <td><input class="form-control" name="classes[]" value="<?= h($class) ?>"></td>
                <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-remove-row><i class="ti ti-trash"></i> Remover</button></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card settings-panel" data-settings-panel="baggage">
      <div class="card-header">
        <h3 class="card-title">Bagagem</h3>
        <div class="ms-auto">
          <button type="button" class="btn btn-outline-primary btn-sm" data-add-row="baggage">
            <i class="ti ti-plus"></i> Adicionar
          </button>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-vcenter settings-table" data-table="baggage">
          <thead>
            <tr>
              <th>Descrição</th>
              <th class="settings-actions"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($lists['baggage'] as $bag): ?>
              <tr>
                <td><input class="form-control" name="baggage[]" value="<?= h($bag) ?>"></td>
                <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-remove-row><i class="ti ti-trash"></i> Remover</button></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card settings-panel" data-settings-panel="airlines">
      <div class="card-header">
        <h3 class="card-title">Companhias Aéreas</h3>
        <div class="ms-auto">
          <button type="button" class="btn btn-outline-primary btn-sm" data-add-row="airlines">
            <i class="ti ti-plus"></i> Adicionar
          </button>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table table-vcenter settings-table" data-table="airlines">
          <thead>
            <tr>
              <th style="width: 160px;">Código</th>
              <th>Nome</th>
              <th style="width: 380px;">Logo</th>
              <th class="settings-actions"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($lists['airlines'] as $air): ?>
              <tr>
                <td><input class="form-control text-uppercase" name="airline_code[]" maxlength="3" value="<?= h($air['code']) ?>"></td>
                <td><input class="form-control" name="airline_label[]" value="<?= h($air['label']) ?>"></td>
                <td>
                  <div class="airline-logo-cell">
                    <div class="airline-logo-box" data-logo-preview>
                      <?php if (!empty($air['logo'])): ?>
                        <img src="<?= h($air['logo']) ?>" alt="<?= h($air['label']) ?>">
                      <?php else: ?>
                        <i class="ti ti-photo"></i>
                      <?php endif; ?>
                    </div>
                    <div>
                      <input type="hidden" name="airline_logo[]" value="<?= h($air['logo'] ?? '') ?>" data-logo-hidden>
                      <input class="form-control airline-file" type="file" name="airline_logo_file[]" accept="image/png,image/jpeg,image/webp,image/svg+xml" data-logo-file>
                      <div class="small text-muted mt-1"><?= !empty($air['logo']) ? h($air['logo']) : 'PNG, JPG, WEBP ou SVG' ?></div>
                    </div>
                  </div>
                </td>
                <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-remove-row><i class="ti ti-trash"></i> Remover</button></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</form>

<template id="tpl-classes">
  <tr>
    <td><input class="form-control" name="classes[]" value=""></td>
    <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-remove-row><i class="ti ti-trash"></i> Remover</button></td>
  </tr>
</template>

<template id="tpl-baggage">
  <tr>
    <td><input class="form-control" name="baggage[]" value=""></td>
    <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-remove-row><i class="ti ti-trash"></i> Remover</button></td>
  </tr>
</template>

<template id="tpl-airlines">
  <tr>
    <td><input class="form-control text-uppercase" name="airline_code[]" maxlength="3" value=""></td>
    <td><input class="form-control" name="airline_label[]" value=""></td>
    <td>
      <div class="airline-logo-cell">
        <div class="airline-logo-box" data-logo-preview><i class="ti ti-photo"></i></div>
        <div>
          <input type="hidden" name="airline_logo[]" value="" data-logo-hidden>
          <input class="form-control airline-file" type="file" name="airline_logo_file[]" accept="image/png,image/jpeg,image/webp,image/svg+xml" data-logo-file>
          <div class="small text-muted mt-1">PNG, JPG, WEBP ou SVG</div>
        </div>
      </div>
    </td>
    <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-remove-row><i class="ti ti-trash"></i> Remover</button></td>
  </tr>
</template>

<script>
document.addEventListener('click', function(e) {
  const tab = e.target.closest('[data-settings-tab]');
  if (!tab) return;
  const name = tab.getAttribute('data-settings-tab');
  document.querySelectorAll('[data-settings-tab]').forEach(btn => btn.classList.toggle('active', btn === tab));
  document.querySelectorAll('[data-settings-panel]').forEach(panel => {
    panel.classList.toggle('active', panel.getAttribute('data-settings-panel') === name);
  });
});

document.addEventListener('click', function(e) {
  const addBtn = e.target.closest('[data-add-row]');
  if (addBtn) {
    const name = addBtn.getAttribute('data-add-row');
    const table = document.querySelector(`[data-table="${name}"] tbody`);
    const tpl = document.getElementById(`tpl-${name}`);
    if (!table || !tpl) return;
    const row = tpl.content.firstElementChild.cloneNode(true);
    table.appendChild(row);
    row.querySelector('input')?.focus();
    return;
  }

  const removeBtn = e.target.closest('[data-remove-row]');
  if (removeBtn) {
    removeBtn.closest('tr')?.remove();
  }
});

document.addEventListener('input', function(e) {
  const code = e.target.closest('input[name="airline_code[]"]');
  if (code) code.value = code.value.toUpperCase().replace(/\s+/g, '');
});

document.addEventListener('change', function(e) {
  const fileInput = e.target.closest('[data-logo-file]');
  if (!fileInput || !fileInput.files || !fileInput.files[0]) return;
  const row = fileInput.closest('tr');
  const preview = row?.querySelector('[data-logo-preview]');
  if (!preview) return;
  const url = URL.createObjectURL(fileInput.files[0]);
  preview.innerHTML = `<img src="${url}" alt="">`;
});
</script>

<?php require_once __DIR__ . '/../inc/footer.php'; ?>
