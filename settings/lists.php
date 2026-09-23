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
ob_start();
?>
<style>
  .settings-tab {
    display: inline-flex; align-items: center; gap: .4rem;
    border: 1px solid #dfe4ef; border-radius: .75rem;
    padding: .5rem .9rem; font-weight: 600; font-size: .875rem;
    color: #475569; background: #fff; cursor: pointer; transition: all .15s;
  }
  .settings-tab:hover { background: #f5f6fa; }
  .settings-tab.active {
    border-color: #c7d8fe; color: #4c52e5; background: #eef4ff;
  }
  .settings-panel { display: none; }
  .settings-panel.active { display: block; }
  .logo-box img { max-width: 100%; max-height: 100%; object-fit: contain; display: block; }
</style>

<div class="mx-auto max-w-5xl">

  <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
      <p class="text-xs font-bold uppercase tracking-wider text-brand-600">Configurações</p>
      <h2 class="text-xl font-bold text-ink-950">Listas do Sistema</h2>
      <p class="mt-1 text-sm text-ink-500">Classes, bagagens e companhias aéreas usadas nas vendas.</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
      <a href="/settings/index.php" class="btn-ghost">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"></path><path d="M12 19l-7-7 7-7"></path></svg>
        Voltar
      </a>
      <button class="btn-primary" form="listsForm">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
        Salvar listas
      </button>
    </div>
  </div>

  <?php if ($msg === 'saved'): ?>
    <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800" role="alert">
      Listas salvas com sucesso.
    </div>
  <?php endif; ?>
  <?php if ($err !== ''): ?>
    <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800" role="alert">
      <?= h($err) ?>
    </div>
  <?php endif; ?>

  <form method="post" id="listsForm" autocomplete="off" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= h($token) ?>">

    <div class="mb-5 grid gap-3 sm:grid-cols-3">
      <div class="card p-4">
        <p class="text-xs font-medium text-ink-500">Classes</p>
        <p class="mt-0.5 text-2xl font-bold text-ink-950"><?= count($lists['classes']) ?></p>
      </div>
      <div class="card p-4">
        <p class="text-xs font-medium text-ink-500">Bagagens</p>
        <p class="mt-0.5 text-2xl font-bold text-ink-950"><?= count($lists['baggage']) ?></p>
      </div>
      <div class="card p-4">
        <p class="text-xs font-medium text-ink-500">Companhias aéreas</p>
        <p class="mt-0.5 text-2xl font-bold text-ink-950"><?= count($lists['airlines']) ?></p>
      </div>
    </div>

    <div class="mb-4 flex flex-wrap gap-2" role="tablist">
      <button type="button" class="settings-tab active" data-settings-tab="classes">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
        Classes
      </button>
      <button type="button" class="settings-tab" data-settings-tab="baggage">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="7" width="18" height="13" rx="2"></rect><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><path d="M8 21v-2"></path><path d="M16 21v-2"></path></svg>
        Bagagem
      </button>
      <button type="button" class="settings-tab" data-settings-tab="airlines">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z"></path></svg>
        Companhias aéreas
      </button>
    </div>

    <div>
      <div class="card settings-panel active overflow-hidden" data-settings-panel="classes">
        <div class="flex items-center justify-between gap-3 border-b border-ink-100 px-5 py-4">
          <h3 class="text-sm font-bold text-ink-950">Classes</h3>
          <button type="button" class="btn-soft border-brand-200 text-brand-700 hover:bg-brand-50 hover:text-brand-800" data-add-row="classes">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Adicionar
          </button>
        </div>
        <div class="overflow-x-auto">
          <table class="table-modern" data-table="classes">
            <thead>
              <tr>
                <th>Nome</th>
                <th class="w-28 text-right"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($lists['classes'] as $class): ?>
                <tr>
                  <td><input class="w-full rounded-lg border border-ink-200 bg-white px-2.5 py-1.5 text-sm text-ink-900 outline-none transition focus:border-brand-400 focus:ring-2 focus:ring-brand-500/10" name="classes[]" value="<?= h($class) ?>"></td>
                  <td class="text-right"><button type="button" class="btn-soft border-red-200 text-red-700 hover:bg-red-50 hover:text-red-800" data-remove-row><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg> Remover</button></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card settings-panel overflow-hidden" data-settings-panel="baggage">
        <div class="flex items-center justify-between gap-3 border-b border-ink-100 px-5 py-4">
          <h3 class="text-sm font-bold text-ink-950">Bagagem</h3>
          <button type="button" class="btn-soft border-brand-200 text-brand-700 hover:bg-brand-50 hover:text-brand-800" data-add-row="baggage">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Adicionar
          </button>
        </div>
        <div class="overflow-x-auto">
          <table class="table-modern" data-table="baggage">
            <thead>
              <tr>
                <th>Descrição</th>
                <th class="w-28 text-right"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($lists['baggage'] as $bag): ?>
                <tr>
                  <td><input class="w-full rounded-lg border border-ink-200 bg-white px-2.5 py-1.5 text-sm text-ink-900 outline-none transition focus:border-brand-400 focus:ring-2 focus:ring-brand-500/10" name="baggage[]" value="<?= h($bag) ?>"></td>
                  <td class="text-right"><button type="button" class="btn-soft border-red-200 text-red-700 hover:bg-red-50 hover:text-red-800" data-remove-row><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg> Remover</button></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card settings-panel overflow-hidden" data-settings-panel="airlines">
        <div class="flex items-center justify-between gap-3 border-b border-ink-100 px-5 py-4">
          <h3 class="text-sm font-bold text-ink-950">Companhias Aéreas</h3>
          <button type="button" class="btn-soft border-brand-200 text-brand-700 hover:bg-brand-50 hover:text-brand-800" data-add-row="airlines">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
            Adicionar
          </button>
        </div>
        <div class="overflow-x-auto">
          <table class="table-modern" data-table="airlines">
            <thead>
              <tr>
                <th style="width: 160px;">Código</th>
                <th>Nome</th>
                <th style="width: 380px;">Logo</th>
                <th class="w-28 text-right"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($lists['airlines'] as $air): ?>
                <tr>
                  <td><input class="w-full rounded-lg border border-ink-200 bg-white px-2.5 py-1.5 text-sm uppercase text-ink-900 outline-none transition focus:border-brand-400 focus:ring-2 focus:ring-brand-500/10" name="airline_code[]" maxlength="3" value="<?= h($air['code']) ?>"></td>
                  <td><input class="w-full rounded-lg border border-ink-200 bg-white px-2.5 py-1.5 text-sm text-ink-900 outline-none transition focus:border-brand-400 focus:ring-2 focus:ring-brand-500/10" name="airline_label[]" value="<?= h($air['label']) ?>"></td>
                  <td>
                    <div class="flex items-center gap-2.5">
                      <div class="logo-box flex h-10 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-ink-200 bg-ink-50 text-ink-400" data-logo-preview>
                        <?php if (!empty($air['logo'])): ?>
                          <img src="<?= h($air['logo']) ?>" alt="<?= h($air['label']) ?>">
                        <?php else: ?>
                          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
                        <?php endif; ?>
                      </div>
                      <div class="min-w-0 flex-1">
                        <input type="hidden" name="airline_logo[]" value="<?= h($air['logo'] ?? '') ?>" data-logo-hidden>
                        <input class="w-full rounded-lg border border-ink-200 bg-white px-2.5 py-1.5 text-sm text-ink-900 outline-none transition file:mr-3 file:rounded-md file:border-0 file:bg-ink-100 file:px-2.5 file:py-1 file:text-xs file:font-semibold file:text-ink-700 focus:border-brand-400 focus:ring-2 focus:ring-brand-500/10" type="file" name="airline_logo_file[]" accept="image/png,image/jpeg,image/webp,image/svg+xml" data-logo-file>
                        <p class="mt-1 truncate text-xs text-ink-500"><?= !empty($air['logo']) ? h($air['logo']) : 'PNG, JPG, WEBP ou SVG' ?></p>
                      </div>
                    </div>
                  </td>
                  <td class="text-right"><button type="button" class="btn-soft border-red-200 text-red-700 hover:bg-red-50 hover:text-red-800" data-remove-row><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg> Remover</button></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </form>

  <template id="tpl-classes" hidden>
    <tr>
      <td><input class="w-full rounded-lg border border-ink-200 bg-white px-2.5 py-1.5 text-sm text-ink-900 outline-none transition focus:border-brand-400 focus:ring-2 focus:ring-brand-500/10" name="classes[]" value=""></td>
      <td class="text-right"><button type="button" class="btn-soft border-red-200 text-red-700 hover:bg-red-50 hover:text-red-800" data-remove-row><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg> Remover</button></td>
    </tr>
  </template>

  <template id="tpl-baggage" hidden>
    <tr>
      <td><input class="w-full rounded-lg border border-ink-200 bg-white px-2.5 py-1.5 text-sm text-ink-900 outline-none transition focus:border-brand-400 focus:ring-2 focus:ring-brand-500/10" name="baggage[]" value=""></td>
      <td class="text-right"><button type="button" class="btn-soft border-red-200 text-red-700 hover:bg-red-50 hover:text-red-800" data-remove-row><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg> Remover</button></td>
    </tr>
  </template>

  <template id="tpl-airlines" hidden>
    <tr>
      <td><input class="w-full rounded-lg border border-ink-200 bg-white px-2.5 py-1.5 text-sm uppercase text-ink-900 outline-none transition focus:border-brand-400 focus:ring-2 focus:ring-brand-500/10" name="airline_code[]" maxlength="3" value=""></td>
      <td><input class="w-full rounded-lg border border-ink-200 bg-white px-2.5 py-1.5 text-sm text-ink-900 outline-none transition focus:border-brand-400 focus:ring-2 focus:ring-brand-500/10" name="airline_label[]" value=""></td>
      <td>
        <div class="flex items-center gap-2.5">
          <div class="logo-box flex h-10 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-ink-200 bg-ink-50 text-ink-400" data-logo-preview>
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
          </div>
          <div class="min-w-0 flex-1">
            <input type="hidden" name="airline_logo[]" value="" data-logo-hidden>
            <input class="w-full rounded-lg border border-ink-200 bg-white px-2.5 py-1.5 text-sm text-ink-900 outline-none transition file:mr-3 file:rounded-md file:border-0 file:bg-ink-100 file:px-2.5 file:py-1 file:text-xs file:font-semibold file:text-ink-700 focus:border-brand-400 focus:ring-2 focus:ring-brand-500/10" type="file" name="airline_logo_file[]" accept="image/png,image/jpeg,image/webp,image/svg+xml" data-logo-file>
            <p class="mt-1 text-xs text-ink-500">PNG, JPG, WEBP ou SVG</p>
          </div>
        </div>
      </td>
      <td class="text-right"><button type="button" class="btn-soft border-red-200 text-red-700 hover:bg-red-50 hover:text-red-800" data-remove-row><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg> Remover</button></td>
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

</div>
<?php
$body = ob_get_clean();
require __DIR__ . '/../inc/layout.php';
