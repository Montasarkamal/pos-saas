<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';
require_login();
require_role_admin();

if (!function_exists('h')) {
    function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$appName = 'KAMALTUR POS';
$appVersion = '6.3';
$releaseDate = '23/04/2026';
$phpVersion = PHP_VERSION;
$serverSoftware = (string)($_SERVER['SERVER_SOFTWARE'] ?? 'PHP Server');
$databaseName = '';

try {
    if (isset($DB_NAME)) {
        $databaseName = (string)$DB_NAME;
    } elseif (isset($pdo) && $pdo instanceof PDO) {
        $databaseName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    }
} catch (Throwable $e) {
    $databaseName = '';
}

$features = [
    'Vendas unificadas com serviços de viagem e serviços adicionais.',
    'Cadastro de clientes, fornecedores e usuários por agência.',
    'Configurações da empresa, listas operacionais e companhias aéreas.',
    'Backup local da base de dados.',
    'Cabeçalho com dados da empresa, usuário ativo e cotação USD/BRL.',
];

$pageTitle = 'Sobre o Aplicativo';
ob_start();
?>
<div class="mx-auto max-w-4xl">

  <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
      <p class="text-xs font-bold uppercase tracking-wider text-brand-600">Configurações</p>
      <h2 class="text-xl font-bold text-ink-950">Sobre o Aplicativo</h2>
      <p class="mt-1 text-sm text-ink-500">Informações da instalação atual e versão do sistema.</p>
    </div>
    <a href="/settings/index.php" class="btn-ghost">
      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"></path><path d="M12 19l-7-7 7-7"></path></svg>
      Voltar
    </a>
  </div>

  <div class="card overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-4 p-6">
      <div class="min-w-0">
        <span class="mb-3 inline-flex items-center gap-1.5 rounded-full border border-brand-200 bg-brand-50 px-3 py-1 text-xs font-bold text-brand-700">
          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.83z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg>
          Versão <?= h($appVersion) ?>
        </span>
        <h1 class="text-2xl font-bold text-ink-950"><?= h($appName) ?></h1>
        <p class="mt-2 max-w-2xl text-sm leading-relaxed text-ink-500">
          Sistema local de gestão de vendas para agência de turismo, com clientes, fornecedores, vendas, recibos, vouchers, listas operacionais e configurações administrativas.
        </p>
      </div>
      <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-2xl border border-ink-200 bg-ink-50 text-brand-600" aria-hidden="true">
        <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.8 19.2 16 11l3.5-3.5C21 6 21.5 4 21 3c-1-.5-3 0-4.5 1.5L13 8 4.8 6.2c-.5-.1-.9.1-1.1.5l-.3.5c-.2.5-.1 1 .3 1.3L9 12l-2 3H4l-1 1 3 2 2 3 1-1v-3l3-2 3.5 5.3c.3.4.8.5 1.3.3l.5-.2c.4-.3.6-.7.5-1.2z"></path></svg>
      </div>
    </div>
  </div>

  <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
    <div class="card p-4">
      <span class="block text-[11px] font-bold uppercase tracking-wider text-ink-500">Última versão</span>
      <strong class="mt-1 block text-base text-ink-950"><?= h($appVersion) ?></strong>
    </div>
    <div class="card p-4">
      <span class="block text-[11px] font-bold uppercase tracking-wider text-ink-500">Data do release</span>
      <strong class="mt-1 block text-base text-ink-950"><?= h($releaseDate) ?></strong>
    </div>
    <div class="card p-4">
      <span class="block text-[11px] font-bold uppercase tracking-wider text-ink-500">Ambiente</span>
      <strong class="mt-1 block text-base text-ink-950">Local</strong>
    </div>
    <div class="card p-4">
      <span class="block text-[11px] font-bold uppercase tracking-wider text-ink-500">PHP</span>
      <strong class="mt-1 block text-base text-ink-950"><?= h($phpVersion) ?></strong>
    </div>
    <div class="card p-4">
      <span class="block text-[11px] font-bold uppercase tracking-wider text-ink-500">Servidor</span>
      <strong class="mt-1 block truncate text-base text-ink-950" title="<?= h($serverSoftware) ?>"><?= h($serverSoftware) ?></strong>
    </div>
    <div class="card p-4">
      <span class="block text-[11px] font-bold uppercase tracking-wider text-ink-500">Base de dados</span>
      <strong class="mt-1 block text-base text-ink-950"><?= h($databaseName !== '' ? $databaseName : 'Não identificada') ?></strong>
    </div>
  </div>

  <div class="card mt-5 overflow-hidden">
    <div class="border-b border-ink-100 px-5 py-4">
      <h3 class="text-sm font-bold text-ink-950">Recursos principais</h3>
    </div>
    <div class="p-5">
      <ul class="space-y-2.5">
        <?php foreach ($features as $feature): ?>
          <li class="flex items-start gap-3 rounded-xl border border-ink-100 bg-ink-50/50 px-4 py-3 text-sm text-ink-700">
            <svg class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            <span><?= h($feature) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>

</div>
<?php
$body = ob_get_clean();
require __DIR__ . '/../inc/layout.php';
