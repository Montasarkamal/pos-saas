<?php
/* =====================================================================
   File: sales/show.php — عرض سهل وواضح + تواريخ DD/MM/YYYY دائمًا
   - نفس آلية العمل (نفس الـ queries والحسابات)
   - تغيير الحالة عبر AJAX باستخدام /sales/update_status.php
   - تنسيق التاريخ دائمًا: 01/01/2026
   ===================================================================== */

declare(strict_types=1);

require __DIR__ . '/../inc/auth.php'; require_login();
require __DIR__ . '/../inc/db.php';
require __DIR__ . '/../inc/invoices_lib.php';
require_once __DIR__ . '/../inc/public_link.php';

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
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.4.0/dist/css/tabler.min.css"></head>
  <body class="antialiased"><div class="container-xl py-6"><div class="empty">
   <div class="empty-header">404</div>
   <p class="empty-title">This Page Does Not Exist</p>
   <p class="empty-subtitle text-secondary">'.e($msg).'</p>
   <div class="empty-action"><a href="/sales/index.php" class="btn btn-primary">Voltar</a></div>
  </div></div></body></html>';
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

$badge = 'bg-secondary';
if ($status==='pago') $badge='bg-success';
elseif ($status==='pago parcial') $badge='bg-warning';
elseif ($status==='nao pago') $badge='bg-danger';

/* [J] header */
$pageTitle = 'Venda #'.e($inv['invoice_number']);
require __DIR__ . '/../inc/header.php';
?>

<style>
  .mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace; }
  .info-grid{ display:grid; grid-template-columns: 1fr; gap: 10px; }
  @media (min-width: 992px){
    .info-grid{ grid-template-columns: 1fr 1fr 1fr; }
  }
  .info-box{
    border:1px solid rgba(0,0,0,.08);
    border-radius:12px;
    padding:12px;
    background:#fff;
  }
  .kv{ display:flex; justify-content:space-between; gap:10px; padding:6px 0; border-bottom:1px dashed rgba(0,0,0,.08); }
  .kv:last-child{ border-bottom:0; }
  .kv .k{ color: var(--tblr-muted); }
  .kv .v{ font-weight:600; text-align:right; }
  .section-title{ font-weight:700; margin: 8px 0 10px; }
  .table thead th{ white-space:nowrap; }
</style>

<input type="hidden" id="csrfToken" value="<?= e($token) ?>">

<!-- شريط علوي بسيط -->
<div class="page-header d-print-none">
  <div class="row align-items-center g-2">
    <div class="col">
      <div class="d-flex align-items-center gap-2">
        <span class="avatar bg-azure-lt"><i class="ti ti-file-invoice"></i></span>
        <div class="lh-sm">
          <div class="text-muted small">Venda</div>
          <div class="h2 m-0">
            <span class="mono">#<?= e($inv['invoice_number']) ?></span>
            <span class="badge text-white <?= e($badge) ?>" id="statusBadge" style="vertical-align:middle;">
              <span id="statusText"><?= e(status_label($statusRaw)) ?></span>
            </span>
          </div>
          <div class="text-muted">
            Cliente: <strong><?= e($inv['client_name'] ?? '—') ?></strong>
            <?php if (!empty($inv['pnr_code'])): ?>
              &nbsp;•&nbsp; PNR: <span class="mono fw-semibold" id="pnrText"><?= e($inv['pnr_code']) ?></span>
              <button type="button" class="btn btn-sm btn-ghost-secondary ms-1" id="btnCopyPnr" data-copy="<?= e($inv['pnr_code']) ?>">
                <i class="ti ti-copy me-1"></i>Copiar
              </button>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="col-auto ms-auto">
      <div class="btn-list">

        <!-- Status واضح (AJAX) -->
        <div class="d-flex align-items-center gap-2">
          <select class="form-select" id="statusSelect" style="width:170px"
                  data-id="<?= (int)$inv['id'] ?>" data-current="<?= e($status) ?>">
            <option value="nao pago"     <?= $status==='nao pago'?'selected':'' ?>>Não pago</option>
            <option value="pago parcial" <?= $status==='pago parcial'?'selected':'' ?>>Pago parcial</option>
            <option value="pago"         <?= $status==='pago'?'selected':'' ?>>Pago</option>
          </select>

          <button class="btn btn-success" type="button" id="btnMarkPaid">
            <i class="ti ti-check me-1"></i>Pago
          </button>
        </div>

        <a class="btn btn-outline-amber" href="/sales/edit.php?id=<?= (int)$inv['id'] ?>">
          <i class="ti ti-edit me-1"></i>Editar
        </a>
        <a class="btn btn-outline-primary" href="<?= e($publicInvoiceUrl) ?>" target="_blank" rel="noopener">
          <i class="ti ti-file-invoice me-1"></i>Venda
        </a>
        <a class="btn btn-outline-green" href="<?= e($publicVoucherUrl) ?>" target="_blank" rel="noopener">
          <i class="ti ti-ticket me-1"></i>Voucher
        </a>

        <form action="/sales/delete.php" method="post" class="d-inline"
              onsubmit="return confirm('Excluir a venda #<?= e($inv['invoice_number']) ?>? Esta ação não pode ser desfeita e será registrada.');">
          <input type="hidden" name="csrf" value="<?= e($token) ?>">
          <input type="hidden" name="id" value="<?= (int)$inv['id'] ?>">
          <button class="btn btn-outline-red"><i class="ti ti-trash me-1"></i>Excluir</button>
        </form>

        <a class="btn" href="/sales/index.php"><i class="ti ti-arrow-left me-1"></i>Voltar</a>
      </div>
    </div>
  </div>
</div>

<!-- معلومات أساسية (3 صناديق واضحة) -->
<div class="info-grid mb-3">
  <div class="info-box">
    <div class="section-title">Dados</div>
    <div class="kv"><div class="k">ID</div><div class="v mono"><?= (int)$inv['id'] ?></div></div>
    <div class="kv"><div class="k">Emissão</div><div class="v mono"><?= br_date($inv['issue_date'] ?? '') ?></div></div>
    <div class="kv"><div class="k">Viagem</div><div class="v mono"><?= br_date($inv['travel_date'] ?? '') ?></div></div>
    <div class="kv"><div class="k">Âmbito</div><div class="v"><?= e($inv['scope'] ?: '—') ?></div></div>
  </div>

  <div class="info-box">
    <div class="section-title">Cliente</div>
    <div class="kv"><div class="k">Nome</div><div class="v"><?= e($inv['client_name'] ?? '—') ?></div></div>
    <div class="kv"><div class="k">Documento</div><div class="v mono"><?= e($inv['client_document'] ?: '—') ?></div></div>
    <div class="kv"><div class="k">PNR</div><div class="v mono"><?= e($inv['pnr_code'] ?: '—') ?></div></div>
  </div>

  <div class="info-box">
    <div class="section-title">Fornecedor & Regras</div>
    <div class="kv"><div class="k">Fornecedor</div><div class="v"><?= e($inv['supplier_name'] ?? '—') ?></div></div>
    <div class="kv"><div class="k">Reembolso</div><div class="v"><?= e($inv['refund_rule'] ?: '—') ?></div></div>
    <div class="kv"><div class="k">Alteração</div><div class="v"><?= e($inv['change_rule'] ?: '—') ?></div></div>
  </div>
</div>

<!-- 1) Passageiros -->
<div class="card mb-3">
  <div class="card-header">
    <h3 class="card-title">Passageiros</h3>
    <div class="ms-auto text-muted">Total: <strong><?= money_br($sumPassengers) ?></strong></div>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter">
      <thead>
        <tr>
          <th>Nome</th>
          <th style="width:90px">Tipo</th>
          <th>Nº bilhete</th>
          <th class="text-end" style="width:140px">Valor</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($passengers as $p): ?>
          <tr>
            <td class="fw-semibold"><?= e($p['name']) ?></td>
            <td><span class="badge bg-secondary-lt"><?= e($p['ptype']) ?></span></td>
            <td class="mono"><?= e($p['ticket_no']) ?></td>
            <td class="text-end fw-semibold"><?= money_br($p['value']) ?></td>
          </tr>
        <?php endforeach; if (!$passengers): ?>
          <tr><td colspan="4" class="text-muted">Sem passageiros.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- 2) Voos -->
<div class="card mb-3">
  <div class="card-header">
    <h3 class="card-title">Segmentos (Voos)</h3>
  </div>
  <div class="table-responsive">
    <table class="table table-vcenter">
      <thead>
        <tr>
          <th style="width:70px">Cia</th>
          <th style="width:90px">Nº voo</th>
          <th>Origem</th>
          <th>Destino</th>
          <th style="width:90px">Classe</th>
          <th style="width:110px">Bagagem</th>
          <th style="width:120px">Loc Cia</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($segments as $s): ?>
          <tr>
            <td class="fw-semibold"><?= e($s['airline_code']) ?></td>
            <td class="mono"><?= e($s['flight_no']) ?></td>
            <td><?= e($s['origin']) ?></td>
            <td><?= e($s['destination']) ?></td>
            <td><span class="badge bg-azure-lt"><?= e($s['class']) ?></span></td>
            <td><?= e($s['baggage']) ?></td>
            <td class="mono"><?= e($s['record_locator']) ?></td>
          </tr>
        <?php endforeach; if (!$segments): ?>
          <tr><td colspan="7" class="text-muted">Sem segmentos.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- 3) Serviços (إن وجد) -->
<?php if ($aux_rows): ?>
  <div class="card mb-3">
    <div class="card-header">
      <h3 class="card-title">Serviços Auxiliares</h3>
      <div class="ms-auto text-muted">Total: <strong><?= money_br($sumAux) ?></strong></div>
    </div>
    <div class="table-responsive">
      <table class="table table-vcenter">
        <thead>
          <tr>
            <th style="width:140px">Código</th>
            <th>Serviço</th>
            <th class="text-end" style="width:140px">Valor</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($aux_rows as $a): ?>
            <tr>
              <td class="mono"><?= e($a['code'] ?: '—') ?></td>
              <td>
                <div class="fw-semibold"><?= e($a['service']) ?></div>
                <?php if (!empty($a['service_type']) || !empty($a['start_date']) || !empty($a['end_date']) || !empty($a['details'])): ?>
                  <div class="text-muted small">
                    <?= e($saleServiceTypes[$a['service_type'] ?? ''] ?? ($a['service_type'] ?? '')) ?>
                    <?php if (!empty($a['start_date']) || !empty($a['end_date'])): ?>
                      · <?= e($a['start_date'] ?: '—') ?> → <?= e($a['end_date'] ?: '—') ?>
                    <?php endif; ?>
                    <?php if (!empty($a['details'])): ?>
                      · <?= e($a['details']) ?>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </td>
              <td class="text-end fw-semibold"><?= money_br($a['value']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<!-- ملخص مالي في النهاية -->
<div class="card mb-3">
  <div class="card-header">
    <h3 class="card-title">Resumo financeiro</h3>
  </div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-3">
        <div class="text-muted">Total Cliente</div>
        <div class="h2 m-0"><?= money_br($totalCliente) ?></div>
      </div>
      <div class="col-md-3">
        <div class="text-muted">Líquido Fornecedor</div>
        <div class="h2 m-0"><?= money_br($liquid) ?></div>
      </div>
      <div class="col-md-3">
        <div class="text-muted">Margem/Lucro</div>
        <div class="h2 m-0"><?= money_br($margin) ?></div>
      </div>
      <div class="col-md-3">
        <div class="text-muted">Fornecedor pago?</div>
        <div class="h2 m-0"><?= !empty($inv['supplier_paid']) ? 'Sim' : 'Não' ?></div>
      </div>

      <div class="col-12"><hr class="my-1"></div>

      <div class="col-md-3">Tarifa fornecedor: <strong><?= money_br($inv['supplier_tarifa']) ?></strong></div>
      <div class="col-md-3">Comissão fornecedor: <strong><?= money_br($inv['supplier_comissao']) ?></strong></div>
      <div class="col-md-3">Passageiros: <strong><?= money_br($sumPassengers) ?></strong></div>
      <div class="col-md-3">Serviços: <strong><?= money_br($sumAux) ?></strong></div>
    </div>
  </div>
</div>

<!-- Prev/Next -->
<div class="d-flex justify-content-between align-items-center my-4">
  <div><a class="btn" href="/sales/index.php"><i class="ti ti-arrow-left me-1"></i>Voltar para lista</a></div>
  <div class="btn-list">
    <a class="btn btn-outline-secondary <?= $prev_id ? '' : 'disabled' ?>" href="<?= $prev_id ? '/sales/show.php?id='.$prev_id : '#' ?>">
      <i class="ti ti-arrow-left me-1"></i>Anterior
    </a>
    <a class="btn btn-outline-secondary <?= $next_id ? '' : 'disabled' ?>" href="<?= $next_id ? '/sales/show.php?id='.$next_id : '#' ?>">
      Próxima <i class="ti ti-arrow-right ms-1"></i>
    </a>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {

  function toast(msg, type='success') {
    const wrap = document.createElement('div');
    wrap.style.position = 'fixed';
    wrap.style.right = '16px';
    wrap.style.bottom = '16px';
    wrap.style.zIndex = 1050;
    wrap.innerHTML = `
      <div class="alert alert-${type} shadow-sm mb-0" role="alert">
        <div class="d-flex align-items-center gap-2">
          <i class="ti ti-${type==='success'?'circle-check':'alert-triangle'}"></i>
          <div>${String(msg)}</div>
        </div>
      </div>
    `;
    document.body.appendChild(wrap);
    setTimeout(()=> wrap.remove(), 2500);
  }

  function badgeClass(v){
    v = (v||'').toLowerCase().trim();
    if (v==='pago') return 'bg-success';
    if (v==='pago parcial') return 'bg-warning';
    if (v==='nao pago' || v==='não pago') return 'bg-danger';
    return 'bg-secondary';
  }

  function updateBadge(v){
    const badge = document.getElementById('statusBadge');
    const text  = document.getElementById('statusText');
    if (!badge || !text) return;
    text.textContent = statusLabel(v);
    badge.className = 'badge text-white ' + badgeClass(v);
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

<?php require __DIR__ . '/../inc/footer.php'; ?>
