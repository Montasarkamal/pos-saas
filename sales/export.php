<?php
declare(strict_types=1);

require __DIR__ . '/../inc/auth.php';
require_login();

$pageTitle = 'Exportar Vendas';
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
ob_start();
?>
<div class="mx-auto max-w-3xl">

  <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
      <p class="text-xs font-bold uppercase tracking-wider text-brand-600">Vendas</p>
      <h2 class="text-xl font-bold text-ink-950">Exportar Vendas</h2>
      <p class="mt-1 text-sm text-ink-500">Arquivo neutro para importar em outro sistema, sem depender das mesmas tabelas.</p>
    </div>
    <a href="/sales/index.php" class="btn-ghost">
      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"></path><path d="M12 19l-7-7 7-7"></path></svg>
      Voltar
    </a>
  </div>

  <form class="card overflow-hidden" method="get" action="/sales/export_download.php">
    <div class="border-b border-ink-100 px-5 py-4">
      <h3 class="text-sm font-bold text-ink-950">Configuração do arquivo</h3>
    </div>
    <div class="p-5">
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div>
          <label class="label-field" for="from">De</label>
          <input class="input-field" id="from" type="date" name="from" value="<?= h($monthStart) ?>">
        </div>
        <div>
          <label class="label-field" for="to">Até</label>
          <input class="input-field" id="to" type="date" name="to" value="<?= h($today) ?>">
        </div>
        <div>
          <label class="label-field" for="status">Status</label>
          <select class="select-field" id="status" name="status">
            <option value="">Todos</option>
            <option value="nao pago">Não pago</option>
            <option value="pago parcial">Pago parcial</option>
            <option value="pago">Pago</option>
          </select>
        </div>
        <div>
          <label class="label-field" for="format">Formato</label>
          <select class="select-field" id="format" name="format">
            <option value="zip">ZIP: CSV + JSON</option>
            <option value="json">JSON único</option>
            <option value="csv">CSV resumido</option>
          </select>
        </div>
        <div class="lg:col-span-2">
          <label class="label-field" for="q">Buscar</label>
          <input class="input-field" id="q" name="q" placeholder="Número, cliente ou PNR">
        </div>
      </div>

      <label class="mt-5 flex cursor-pointer items-start gap-2.5 select-none">
        <input type="checkbox" name="include_details" value="1" checked class="mt-0.5 h-4 w-4 rounded border-ink-300 text-brand-600 accent-brand-600">
        <span class="text-sm text-ink-700">Incluir passageiros, trechos e serviços em arquivos separados</span>
      </label>
    </div>
    <div class="flex items-center justify-end gap-3 border-t border-ink-100 bg-ink-50/50 px-5 py-4">
      <button class="btn-primary" type="submit">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
        Exportar
      </button>
    </div>
  </form>

  <div class="mt-5 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
    <strong>Formato neutro:</strong> o ZIP inclui <code class="rounded bg-sky-100 px-1 py-0.5 font-mono text-[12px]">sales.csv</code>, <code class="rounded bg-sky-100 px-1 py-0.5 font-mono text-[12px]">passengers.csv</code>, <code class="rounded bg-sky-100 px-1 py-0.5 font-mono text-[12px]">segments.csv</code>, <code class="rounded bg-sky-100 px-1 py-0.5 font-mono text-[12px]">services.csv</code>, <code class="rounded bg-sky-100 px-1 py-0.5 font-mono text-[12px]">sales_export.json</code> e <code class="rounded bg-sky-100 px-1 py-0.5 font-mono text-[12px]">README.txt</code>. Outro sistema pode ler por <code class="rounded bg-sky-100 px-1 py-0.5 font-mono text-[12px]">external_sale_id</code> e <code class="rounded bg-sky-100 px-1 py-0.5 font-mono text-[12px]">sale_number</code>.
  </div>

</div>
<?php
$body = ob_get_clean();
require __DIR__ . '/../inc/layout.php';
