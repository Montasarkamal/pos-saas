<?php
/* =====================================================================
   File: sales/show.php — modern layout (Phase 2)
   - نفس آلية العمل (نفس الـ queries والحسابات)
   - تغيير الحالة عبر AJAX باستخدام /sales/update_status.php
   - تنسيق التاريخ دائمًا: 01/01/2026
   ===================================================================== */

declare(strict_types=1);

require __DIR__ . '/../inc/auth.php'; require_login();
require __DIR__ . '/../inc/db.php';
require __DIR__ . '/../inc/invoices_lib.php';
require_once __DIR__ . '/../inc/public_link.php';
require_once __DIR__ . '/../inc/ui.php';

header('Content-Type: text/html; charset=UTF-8');

/* [A] id */
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(404); echo render404('Parâmetro ID inválido.'); exit; }
[$invoiceAgencyCondition, $invoiceAgencyParams] = agency_scope_sql('i.agency_id');

/* [B] venda + cliente + fornecedor */
$sqlInv = $pdo->prepare("
  SELECT i.*,
         c.name     AS client_name,
         c.document AS client_document,
         s.name     AS supplier_name
    FROM invoices i
    LEFT JOIN clients   c ON c.id = i.client_id AND c.agency_id = i.agency_id
    LEFT JOIN suppliers s ON s.id = i.supplier_id AND s.agency_id = i.agency_id
   WHERE i.id = ? AND $invoiceAgencyCondition
   LIMIT 1
");
$sqlInv->execute(array_merge([$id], $invoiceAgencyParams));
$inv = $sqlInv->fetch(PDO::FETCH_ASSOC);
if (!$inv) { http_response_code(404); echo render404('Venda não encontrada.'); exit; }

/* [C] passageiros + segmentos + serviços auxiliares */
$sqlP = $pdo->prepare("SELECT name, ptype, ticket_no, value FROM passengers WHERE invoice_id=? ORDER BY id");
$sqlP->execute([$id]);
$passengers = $sqlP->fetchAll(PDO::FETCH_ASSOC);

$sqlS = $pdo->prepare("
  SELECT airline_code, flight_no, `origin`, `destination`, `class`, `baggage`, record_locator
    FROM segments
   WHERE invoice_id=?
   ORDER BY id
");
$sqlS->execute([$id]);
$segments = $sqlS->fetchAll(PDO::FETCH_ASSOC);

$auxCols = ['code', 'service', 'value'];
foreach (['service_type', 'start_date', 'end_date', 'cost_value', 'supplier_paid', 'details'] as $col) {
  if (function_exists('has_column') && has_column($pdo, 'aux_services', $col)) $auxCols[] = $col;
}
$sqlA = $pdo->prepare("SELECT `" . implode('`,`', $auxCols) . "` FROM aux_services WHERE invoice_id=? ORDER BY id");
$sqlA->execute([$id]);
$aux_rows = $sqlA->fetchAll(PDO::FETCH_ASSOC);

/* [D] prev/next */
$prev_id = null; $next_id = null;
$prevAgencyCondition = is_superadmin() ? '1=1' : 'agency_id = ?';
$prevAgencyParams = is_superadmin() ? [] : [agency_id()];
$stPrev = $pdo->prepare("SELECT id FROM invoices WHERE id < ? AND $prevAgencyCondition ORDER BY id DESC LIMIT 1");
$stPrev->execute(array_merge([$id], $prevAgencyParams)); if ($r=$stPrev->fetch(PDO::FETCH_NUM)) $prev_id=(int)$r[0];
$stNext = $pdo->prepare("SELECT id FROM invoices WHERE id > ? AND $prevAgencyCondition ORDER BY id ASC LIMIT 1");
$stNext->execute(array_merge([$id], $prevAgencyParams)); if ($r=$stNext->fetch(PDO::FETCH_NUM)) $next_id=(int)$r[0];

/* [E] helpers */
function money_br($n){ return 'R$ ' . number_format((float)$n, 2, ',', '.'); }
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$saleServiceTypes = [
  'hotel' => 'Hotel',
  'car' => 'Aluguel de carro',
  'insurance' => 'Seguro saúde',
  'reception' => 'Recepção',
  'guide' => 'Guia turístico',
  'transfer' => 'Transfer',
  'other' => 'Outro serviço',
];

/* تاريخ DD/MM/YYYY دائمًا */
function br_date($s){
  if (!$s) return '—';
  $s = trim((string)$s);
  $ts = strtotime($s);
  return $ts ? date('d/m/Y', $ts) : '—';
}

function render404($msg='Página não encontrada'){
  return '<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><title>404</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body{ margin:0; font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
      background:#f6f7fb; color:#1b2140; display:flex; align-items:center; justify-content:center; min-height:100vh; }
    .box{ text-align:center; padding:2rem; }
    .big{ font-size:64px; font-weight:800; color:#6273f2; line-height:1; }
    h1{ font-size:20px; margin:.5rem 0; }
    p{ color:#525a73; margin:0 0 1.25rem; }
    a{ display:inline-block; background:#6273f2; color:#fff; font-weight:600; text-decoration:none;
       padding:.6rem 1.2rem; border-radius:10px; }
  </style></head>
  <body><div class="box">
    <div class="big">404</div>
    <h1>Página não encontrada</h1>
    <p>'.e($msg).'</p>
    <a href="/sales/index.php">Voltar</a>
  </div></body></html>';
}

/* [F] CSRF */
$token = csrf_token();

/* [G] public links */
if (function_exists('build_public_url')) {
  $publicInvoiceUrl = build_public_url('invoice', (int)$inv['id']);
  $publicVoucherUrl = build_public_url('voucher', (int)$inv['id']);
} else {
  $publicInvoiceUrl = '/sales/print.php?id='.(int)$inv['id'];
  $publicVoucherUrl = '/sales/voucher.php?id='.(int)$inv['id'];
}

/* [H] resumo financeiro */
$sumPassengers = 0.0; foreach ($passengers as $p){ $sumPassengers += (float)$p['value']; }
$sumAux = 0.0; foreach ($aux_rows as $a){ $sumAux += (float)$a['value']; }
$totalCliente_calc = $sumPassengers + $sumAux;
$totalCliente = ($inv['total_amount'] !== null) ? (float)$inv['total_amount'] : $totalCliente_calc;
$liquid = (float)($inv['supplier_liquid'] ?? max(0, (float)$inv['supplier_tarifa'] - (float)$inv['supplier_comissao']));
$margin = $totalCliente - $liquid;

/* [I] status */
$statusRaw = (string)($inv['status'] ?? '');
$status = mb_strtolower(trim($statusRaw), 'UTF-8');
if ($status === 'não pago') $status = 'nao pago';

/* [J] layout */
$pageTitle = 'Venda #'.e($inv['invoice_number']);
ob_start();
?>

<input type="hidden" id="csrfToken" value="<?= e($token) ?>">

<!-- شريط علوي -->
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div class="min-w-0">
        <a href="/sales/index.php" class="mb-2 inline-flex items-center gap-1 text-sm font-medium text-ink-500 transition hover:text-brand-600">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
            Voltar para a lista
        </a>
        <div class="flex flex-wrap items-center gap-2.5">
            <h2 class="font-mono text-xl font-bold text-ink-950">#<?= e($inv['invoice_number']) ?></h2>
            <span class="badge-soft <?= ui_status_badge($status) ?>" id="statusBadge">
                <span id="statusText"><?= e(ui_status_label($statusRaw)) ?></span>
            </span>
        </div>
        <p class="mt-1 text-sm text-ink-500">
            Cliente: <strong class="text-ink-800"><?= e($inv['client_name'] ?? '—') ?></strong>
            <?php if (!empty($inv['pnr_code'])): ?>
                · PNR: <span class="font-mono font-semibold text-ink-800" id="pnrText"><?= e($inv['pnr_code']) ?></span>
                <button type="button" class="btn-soft ml-1 border-brand-200 text-brand-700 hover:bg-brand-50" id="btnCopyPnr" data-copy="<?= e($inv['pnr_code']) ?>">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
                    Copiar
                </button>
            <?php endif; ?>
        </p>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <div class="flex items-center gap-2">
            <select class="select-field w-[180px]" id="statusSelect"
                    data-id="<?= (int)$inv['id'] ?>" data-current="<?= e($status) ?>">
                <option value="nao pago"     <?= $status==='nao pago'?'selected':'' ?>>Não pago</option>
                <option value="pago parcial" <?= $status==='pago parcial'?'selected':'' ?>>Pago parcial</option>
                <option value="pago"         <?= $status==='pago'?'selected':'' ?>>Pago</option>
            </select>
            <button class="btn-primary px-4" type="button" id="btnMarkPaid">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>
                Pago
            </button>
        </div>
        <a class="btn-soft border-amber-200 text-amber-700 hover:bg-amber-50" href="/sales/edit.php?id=<?= (int)$inv['id'] ?>">Editar</a>
        <a class="btn-soft border-brand-200 text-brand-700 hover:bg-brand-50" href="<?= e($publicInvoiceUrl) ?>" target="_blank" rel="noopener">Venda</a>
        <a class="btn-soft border-emerald-200 text-emerald-700 hover:bg-emerald-50" href="<?= e($publicVoucherUrl) ?>" target="_blank" rel="noopener">Voucher</a>
        <form action="/sales/delete.php" method="post" class="m-0"
              onsubmit="return confirm('Excluir a venda #<?= e($inv['invoice_number']) ?>? Esta ação não pode ser desfeita e será registrada.');">
            <input type="hidden" name="csrf" value="<?= e($token) ?>">
            <input type="hidden" name="id" value="<?= (int)$inv['id'] ?>">
            <button class="btn-soft border-red-200 text-red-700 hover:bg-red-50">Excluir</button>
        </form>
    </div>
</div>

<!-- معلومات أساسية -->
<div class="mb-5 grid grid-cols-1 gap-4 lg:grid-cols-3">
    <div class="card p-5">
        <h3 class="mb-3 text-sm font-bold text-ink-950">Dados</h3>
        <?php foreach ([
            ['ID', (int)$inv['id'], 'mono'],
            ['Emissão', br_date($inv['issue_date'] ?? ''), 'mono'],
            ['Viagem', br_date($inv['travel_date'] ?? ''), 'mono'],
            ['Âmbito', e($inv['scope'] ?: '—'), ''],
        ] as [$k, $v, $cls]): ?>
            <div class="flex items-start justify-between gap-3 border-b border-dashed border-ink-100 py-2 last:border-0">
                <span class="text-sm text-ink-500"><?= $k ?></span>
                <span class="text-right text-sm font-semibold text-ink-900 <?= $cls ?>"><?= $v ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card p-5">
        <h3 class="mb-3 text-sm font-bold text-ink-950">Cliente</h3>
        <?php foreach ([
            ['Nome', e($inv['client_name'] ?? '—'), ''],
            ['Documento', e($inv['client_document'] ?: '—'), 'mono'],
            ['PNR', e($inv['pnr_code'] ?: '—'), 'mono'],
        ] as [$k, $v, $cls]): ?>
            <div class="flex items-start justify-between gap-3 border-b border-dashed border-ink-100 py-2 last:border-0">
                <span class="text-sm text-ink-500"><?= $k ?></span>
                <span class="text-right text-sm font-semibold text-ink-900 <?= $cls ?>"><?= $v ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card p-5">
        <h3 class="mb-3 text-sm font-bold text-ink-950">Fornecedor &amp; Regras</h3>
        <?php foreach ([
            ['Fornecedor', e($inv['supplier_name'] ?? '—'), ''],
            ['Reembolso', e($inv['refund_rule'] ?: '—'), ''],
            ['Alteração', e($inv['change_rule'] ?: '—'), ''],
        ] as [$k, $v, $cls]): ?>
            <div class="flex items-start justify-between gap-3 border-b border-dashed border-ink-100 py-2 last:border-0">
                <span class="text-sm text-ink-500"><?= $k ?></span>
                <span class="text-right text-sm font-semibold text-ink-900 <?= $cls ?>"><?= $v ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- 1) Passageiros -->
<div class="card mb-5 overflow-hidden">
    <div class="flex items-center justify-between border-b border-ink-100 px-5 py-4">
        <h3 class="text-sm font-bold text-ink-950">Passageiros</h3>
        <span class="text-sm text-ink-500">Total: <strong class="text-ink-900"><?= money_br($sumPassengers) ?></strong></span>
    </div>
    <div class="overflow-x-auto">
        <table class="table-modern min-w-[640px]">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th style="width:100px">Tipo</th>
                    <th>Nº bilhete</th>
                    <th class="text-right" style="width:150px">Valor</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($passengers as $p): ?>
                    <tr>
                        <td class="font-semibold text-ink-950"><?= e($p['name']) ?></td>
                        <td><span class="badge-soft bg-ink-100 text-ink-600"><?= e($p['ptype']) ?></span></td>
                        <td class="font-mono text-xs text-ink-600"><?= e($p['ticket_no']) ?></td>
                        <td class="text-right font-semibold tabular-nums"><?= money_br($p['value']) ?></td>
                    </tr>
                <?php endforeach; if (!$passengers): ?>
                    <tr><td colspan="4" class="px-4 py-8 text-center text-sm text-ink-400">Sem passageiros.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 2) Voos -->
<div class="card mb-5 overflow-hidden">
    <div class="border-b border-ink-100 px-5 py-4">
        <h3 class="text-sm font-bold text-ink-950">Segmentos (Voos)</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="table-modern min-w-[820px]">
            <thead>
                <tr>
                    <th style="width:80px">Cia</th>
                    <th style="width:100px">Nº voo</th>
                    <th>Origem</th>
                    <th>Destino</th>
                    <th style="width:100px">Classe</th>
                    <th style="width:120px">Bagagem</th>
                    <th style="width:130px">Loc Cia</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($segments as $s): ?>
                    <tr>
                        <td class="font-semibold text-ink-950"><?= e($s['airline_code']) ?></td>
                        <td class="font-mono text-xs"><?= e($s['flight_no']) ?></td>
                        <td><?= e($s['origin']) ?></td>
                        <td><?= e($s['destination']) ?></td>
                        <td><span class="badge-soft bg-blue-100 text-blue-700"><?= e($s['class']) ?></span></td>
                        <td class="text-ink-600"><?= e($s['baggage']) ?></td>
                        <td class="font-mono text-xs text-ink-600"><?= e($s['record_locator']) ?></td>
                    </tr>
                <?php endforeach; if (!$segments): ?>
                    <tr><td colspan="7" class="px-4 py-8 text-center text-sm text-ink-400">Sem segmentos.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 3) Serviços (إن وجد) -->
<?php if ($aux_rows): ?>
<div class="card mb-5 overflow-hidden">
    <div class="flex items-center justify-between border-b border-ink-100 px-5 py-4">
        <h3 class="text-sm font-bold text-ink-950">Serviços Auxiliares</h3>
        <span class="text-sm text-ink-500">Total: <strong class="text-ink-900"><?= money_br($sumAux) ?></strong></span>
    </div>
    <div class="overflow-x-auto">
        <table class="table-modern min-w-[640px]">
            <thead>
                <tr>
                    <th style="width:160px">Código</th>
                    <th>Serviço</th>
                    <th class="text-right" style="width:150px">Valor</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($aux_rows as $a): ?>
                    <tr>
                        <td class="font-mono text-xs"><?= e($a['code'] ?: '—') ?></td>
                        <td>
                            <p class="font-semibold text-ink-950"><?= e($a['service']) ?></p>
                            <?php if (!empty($a['service_type']) || !empty($a['start_date']) || !empty($a['end_date']) || !empty($a['details'])): ?>
                                <p class="text-xs text-ink-400">
                                    <?= e($saleServiceTypes[$a['service_type'] ?? ''] ?? ($a['service_type'] ?? '')) ?>
                                    <?php if (!empty($a['start_date']) || !empty($a['end_date'])): ?>
                                        · <?= e($a['start_date'] ?: '—') ?> → <?= e($a['end_date'] ?: '—') ?>
                                    <?php endif; ?>
                                    <?php if (!empty($a['details'])): ?>
                                        · <?= e($a['details']) ?>
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>
                        </td>
                        <td class="text-right font-semibold tabular-nums"><?= money_br($a['value']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ملخص مالي -->
<div class="card mb-5 overflow-hidden">
    <div class="border-b border-ink-100 px-5 py-4">
        <h3 class="text-sm font-bold text-ink-950">Resumo financeiro</h3>
    </div>
    <div class="p-5">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="card p-4">
                <p class="stat-label">Total Cliente</p>
                <p class="stat-value"><?= money_br($totalCliente) ?></p>
            </div>
            <div class="card p-4">
                <p class="stat-label">Líquido Fornecedor</p>
                <p class="stat-value"><?= money_br($liquid) ?></p>
            </div>
            <div class="card p-4">
                <p class="stat-label">Margem / Lucro</p>
                <p class="stat-value <?= $margin >= 0 ? 'text-emerald-600' : 'text-red-500' ?>"><?= money_br($margin) ?></p>
            </div>
            <div class="card p-4">
                <p class="stat-label">Fornecedor pago?</p>
                <p class="stat-value"><?= !empty($inv['supplier_paid']) ? 'Sim' : 'Não' ?></p>
            </div>
        </div>
        <div class="mt-4 grid grid-cols-1 gap-2 border-t border-ink-100 pt-4 text-sm text-ink-600 md:grid-cols-2 xl:grid-cols-4">
            <span>Tarifa fornecedor: <strong class="text-ink-900"><?= money_br($inv['supplier_tarifa']) ?></strong></span>
            <span>Comissão fornecedor: <strong class="text-ink-900"><?= money_br($inv['supplier_comissao']) ?></strong></span>
            <span>Passageiros: <strong class="text-ink-900"><?= money_br($sumPassengers) ?></strong></span>
            <span>Serviços: <strong class="text-ink-900"><?= money_br($sumAux) ?></strong></span>
        </div>
    </div>
</div>

<!-- Prev/Next -->
<div class="flex flex-wrap items-center justify-between gap-3">
    <a href="/sales/index.php" class="btn-ghost">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
        Voltar para lista
    </a>
    <div class="flex items-center gap-2">
        <a class="btn-soft <?= $prev_id ? '' : 'pointer-events-none opacity-40' ?>" href="<?= $prev_id ? '/sales/show.php?id='.$prev_id : '#' ?>">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"></line><polyline points="12 19 5 12 12 5"></polyline></svg>
            Anterior
        </a>
        <a class="btn-soft <?= $next_id ? '' : 'pointer-events-none opacity-40' ?>" href="<?= $next_id ? '/sales/show.php?id='.$next_id : '#' ?>">
            Próxima
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
        </a>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {

  function toast(msg, type='success') {
    const wrap = document.createElement('div');
    wrap.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:1050;';
    const cls = type === 'success'
      ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
      : 'border-red-200 bg-red-50 text-red-800';
    wrap.innerHTML = `
      <div class="rounded-xl border px-3.5 py-3 text-sm shadow-lg ${cls}" role="alert">
        ${String(msg)}
      </div>`;
    document.body.appendChild(wrap);
    setTimeout(()=> wrap.remove(), 2500);
  }

  function badgeClass(v){
    v = (v||'').toLowerCase().trim();
    if (v==='nao pago' || v==='não pago') return 'badge-soft bg-red-100 text-red-700';
    if (v==='pago parcial') return 'badge-soft bg-amber-100 text-amber-700';
    if (v==='pago') return 'badge-soft bg-emerald-100 text-emerald-700';
    return 'badge-soft bg-ink-100 text-ink-600';
  }

  function updateBadge(v){
    const badge = document.getElementById('statusBadge');
    const text  = document.getElementById('statusText');
    if (!badge || !text) return;
    text.textContent = statusLabel(v);
    badge.className = badgeClass(v);
  }

  function statusLabel(v){
    v = (v || '').toLowerCase().trim();
    if (v === 'nao pago' || v === 'não pago') return 'Não pago';
    if (v === 'pago parcial') return 'Pago parcial';
    if (v === 'pago') return 'Pago';
    return v || '—';
  }

  async function sendStatus(id, status){
    const csrf = document.getElementById('csrfToken')?.value || '';
    const resp = await fetch('/sales/update_status.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ id: parseInt(id,10), status, csrf })
    });

    let data = null;
    try { data = await resp.json(); } catch(_) {}

    if (resp.ok && data && data.ok) return data;
    const err = (data && data.error) ? data.error : 'Falha ao atualizar.';
    throw new Error(err);
  }

  // Copy PNR
  const btnCopy = document.getElementById('btnCopyPnr');
  if (btnCopy) {
    btnCopy.addEventListener('click', async () => {
      const v = btnCopy.getAttribute('data-copy') || '';
      try {
        await navigator.clipboard.writeText(v);
        toast('Copiado!', 'success');
      } catch(e) {
        toast('Falha ao copiar.', 'danger');
      }
    });
  }

  // Status controls
  const sel = document.getElementById('statusSelect');
  const btnPaid = document.getElementById('btnMarkPaid');

  if (sel) {
    sel.addEventListener('change', async () => {
      const id = sel.dataset.id;
      const oldStatus = sel.dataset.current || '';
      const newStatus = sel.value;

      if (!confirm(`Alterar o pagamento da venda #<?= e($inv['invoice_number']) ?> de “${statusLabel(oldStatus)}” para “${statusLabel(newStatus)}”? A alteração será registrada.`)) {
        sel.value = oldStatus;
        return;
      }

      sel.disabled = true;
      try {
        await sendStatus(id, newStatus);
        sel.dataset.current = newStatus;
        updateBadge(newStatus);
        toast('Status atualizado!', 'success');
      } catch (e) {
        sel.value = oldStatus;
        toast(e.message || 'Erro', 'danger');
      } finally {
        sel.disabled = false;
      }
    });
  }

  if (btnPaid && sel) {
    btnPaid.addEventListener('click', async () => {
      const id = sel.dataset.id;
      const oldStatus = sel.dataset.current || '';
      if (!confirm('Marcar a venda #<?= e($inv['invoice_number']) ?> como paga? A alteração será registrada.')) return;
      sel.disabled = true;
      btnPaid.disabled = true;

      try {
        await sendStatus(id, 'pago');
        sel.value = 'pago';
        sel.dataset.current = 'pago';
        updateBadge('pago');
        toast('Marcado como Pago!', 'success');
      } catch (e) {
        sel.value = oldStatus;
        toast(e.message || 'Erro', 'danger');
      } finally {
        sel.disabled = false;
        btnPaid.disabled = false;
      }
    });
  }

});
</script>
<?php
$body = ob_get_clean();
require __DIR__ . '/../inc/layout.php';