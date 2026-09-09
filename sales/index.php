<?php
// sales/index.php — Lista de Vendas
// UI حديثة + KPIs + فلاتر + PNR عمود مستقل (نسخ بالضغط + لون + Tooltip)
// + Ações صفّين (بدون Dropdown) + زر Pago يتحول أخضر فوراً بدون Reload
// إصلاح: منع تداخل Ações مع عمود السعر + منع كسر السعر لسطرين + إصلاح عنوان عمود السعر

require __DIR__ . '/../inc/auth.php';
require_login();

require __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/helpers.php';
@include_once __DIR__ . '/../inc/invoices_lib.php';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ==== إعدادات الفرز ==== */
$sortParam = $_GET['sort'] ?? 'id';
$dirParam  = strtolower($_GET['dir'] ?? 'desc');
$dirSQL    = $dirParam === 'asc' ? 'ASC' : 'DESC';

/* تحقق من وجود travel_date */
$hasTravel = function_exists('has_column') ? has_column($pdo, 'invoices', 'travel_date') : false;

/* خرائط الأعمدة المسموحة للفرز */
$allowed = [
  'id'     => 'i.id',
  'number' => 'i.invoice_number',
  'client' => 'c.name',
  'pnr'    => 'i.pnr_code',
  'issue'  => 'i.issue_date',
  'status' => 'i.status',
  'amount' => 'i.total_amount',
];
if ($hasTravel) { $allowed['travel'] = 'i.travel_date'; }

$colSQL  = $allowed[$sortParam] ?? $allowed['id'];
$orderBy = "$colSQL $dirSQL, i.id DESC";

function sort_link(string $key, string $label): string {
  $curSort = $_GET['sort'] ?? 'id';
  $curDir  = strtolower($_GET['dir'] ?? 'desc');
  $nextDir = ($curSort === $key && $curDir === 'asc') ? 'desc' : 'asc';

  $icon = '';
  if ($curSort === $key) { $icon = $curDir === 'asc' ? ' ▲' : ' ▼'; }

  $qs = $_GET; $qs['sort'] = $key; $qs['dir'] = $nextDir;
  $href = '?' . http_build_query($qs);

  return '<a href="'.h($href).'" class="link-secondary text-decoration-none">'.h($label).$icon.'</a>';
}

/* ==== فلاتر ==== */
$q       = trim($_GET['q'] ?? '');
$statusF = trim($_GET['status'] ?? '');
$from    = trim($_GET['from'] ?? '');
$to      = trim($_GET['to'] ?? '');
$perPage = (int)($_GET['per_page'] ?? 20);
if (!in_array($perPage, [20, 50, 100], true)) $perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));

if (function_exists('normalize_status')) {
  $statusF = normalize_status($statusF);
}
if ($statusF === 'não pago') $statusF = 'nao pago';

$params = [];
[$agencyCondition, $agencyParams] = agency_scope_sql('i.agency_id');
$where  = [$agencyCondition];
$params = $agencyParams;

$selectCols = "i.id, i.invoice_number, i.issue_date, i.status, i.total_amount, i.pnr_code";
if ($hasTravel) { $selectCols .= ", i.travel_date"; }
$selectCols .= ", c.name AS client_name";

$fromSql = " FROM invoices i
LEFT JOIN clients c ON c.id = i.client_id AND c.agency_id = i.agency_id";

if ($q !== '') {
  $where[] = "(i.invoice_number LIKE ? OR c.name LIKE ? OR i.pnr_code LIKE ?)";
  $like = "%$q%";
  $params[] = $like; $params[] = $like; $params[] = $like;
}
if ($statusF !== '') {
  $where[] = "LOWER(TRIM(i.status)) = LOWER(TRIM(?))";
  $params[] = $statusF;
}
if ($from !== '') { $where[] = "i.issue_date >= ?"; $params[] = $from; }
if ($to   !== '') { $where[] = "i.issue_date <= ?"; $params[] = $to; }

$whereSql = " WHERE " . implode(" AND ", $where);
$metricsSql = "SELECT
  COUNT(*) AS total_count,
  COALESCE(SUM(i.total_amount), 0) AS total_amount,
  COALESCE(SUM(CASE WHEN LOWER(TRIM(i.status)) IN ('pago','paid') THEN i.total_amount ELSE 0 END), 0) AS paid_amount,
  COALESCE(SUM(CASE WHEN LOWER(TRIM(i.status)) IN ('nao pago','não pago','unpaid') THEN i.total_amount ELSE 0 END), 0) AS unpaid_amount
" . $fromSql . $whereSql;

try {
  $metricsSt = $pdo->prepare($metricsSql);
  $metricsSt->execute($params);
  $metrics = $metricsSt->fetch(PDO::FETCH_ASSOC) ?: [];
  $kpiCount = (int)($metrics['total_count'] ?? 0);
  $kpiTotal = (float)($metrics['total_amount'] ?? 0);
  $kpiPaid = (float)($metrics['paid_amount'] ?? 0);
  $kpiUnpaid = (float)($metrics['unpaid_amount'] ?? 0);
  $totalPages = max(1, (int)ceil($kpiCount / $perPage));
  $page = min($page, $totalPages);
  $offset = ($page - 1) * $perPage;

  $sql = "SELECT $selectCols" . $fromSql . $whereSql
       . " ORDER BY $orderBy LIMIT {$perPage} OFFSET {$offset}";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  error_log('sales/index.php query error: '.$e->getMessage());
  $rows = [];
  $kpiCount = 0; $kpiTotal = 0.0; $kpiPaid = 0.0; $kpiUnpaid = 0.0;
  $totalPages = 1; $page = 1; $offset = 0;
}

function page_url(int $target): string {
  $qs = $_GET;
  $qs['page'] = $target;
  return '?' . http_build_query($qs);
}

$token = csrf_token();
$pageTitle = 'Vendas';
require __DIR__ . '/../inc/header.php';
?>

<style>
  .kpi-card .h1{ letter-spacing:-.02em; }
  .table thead th{ white-space:nowrap; }
  .row-hover tbody tr:hover{ background: rgba(0,0,0,.02); }
  .chip{ display:inline-flex; align-items:center; gap:.4rem; padding:.25rem .55rem; border-radius:999px; border:1px solid rgba(0,0,0,.08); font-size:.85rem; }
  .chip i{ opacity:.7; }
  .mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
  .nowrap{ white-space:nowrap; }

  tr.inv-row{ cursor:pointer; }

  /* عمود Cliente: زيادة عرض المساحة */
  th.col-client, td.col-client { min-width: 340px; }
  td.col-client { white-space: normal; }

  /* عمود السعر: لا ينكسر لسطرين + فوق طبقة Ações */
  th.col-amount, td.col-amount { white-space: nowrap !important; }
  td.col-amount{
    position: relative;
    z-index: 2;
    background: #fff;
  }

  /* Ações: sticky + يظهر كجزء من الجدول */
  .sticky-actions{
    position: sticky;
    right: 0;
    background: #fff;
    z-index: 3;
    border-left: 1px solid rgba(0,0,0,.06);
  }

  /* Ações صفّين — تصغير العرض لتجنب تداخل السعر */
  .acoes-2rows{
    display:flex;
    flex-direction:column;
    align-items:flex-end;
    gap:.38rem;
    min-width: 140px; /* أصغر من السابق */
  }
  .acoes-row{
    display:flex;
    gap:.3rem;
    flex-wrap:nowrap;
  }

  .acoes-row .btn,
  .acoes-row form .btn{
    min-width: 72px;
    min-height: 1.76rem;
    padding: .24rem .42rem;
    font-size: .74rem;
  }

  .acoes-row form{ margin:0; }

  /* PNR: نسخ بالضغط على الكود نفسه + Tooltip + تغيير لون */
  .pnr-box{
    position:relative;
    display:inline-flex;
    align-items:center;
    padding:.34rem .6rem;
    border-radius:10px;
    border:1px solid rgba(0,0,0,.15);
    background:#fff;
    cursor:pointer;
    transition:all .18s ease;
    user-select:none;
  }
  .pnr-box:hover{ background:#f1f5f9; }
  .pnr-code{ font-weight:800; letter-spacing:.10em; }
  .pnr-box.copied{ background:#dcfce7; border-color:#22c55e; }
  .pnr-tooltip{
    position:absolute;
    top:-30px;
    right:0;
    background:#22c55e;
    color:#fff;
    font-size:.74rem;
    padding:2px 7px;
    border-radius:6px;
    opacity:0;
    pointer-events:none;
    transform:translateY(4px);
    transition:.18s ease;
    box-shadow: 0 6px 18px rgba(0,0,0,.12);
  }
  .pnr-box.copied .pnr-tooltip{ opacity:1; transform:translateY(0); }

  /* زر Limpar: واضح وباسم */
  .btn-clear{
    min-width: 140px;
    height: 42px;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:.45rem;
    font-weight:600;
  }
  .sales-table{ min-width: 1180px; }
  @media (max-width: 991.98px) {
    .sticky-actions{ position:static; right:auto; }
    th.col-client, td.col-client{ min-width:240px; }
    .acoes-2rows{ min-width:310px; }
  }
  @media (max-width: 575.98px) {
    .kpi-card .h1{ font-size:1.15rem; }
    .btn-clear{ min-width:0; }
    .page-header .page-title{ font-size:1.35rem; }
  }
</style>

<div class="page-header d-print-none" id="top">
  <div class="row g-2 align-items-center">
    <div class="col">
      <div class="d-flex align-items-center gap-2">
        <span class="avatar avatar-sm bg-azure-lt"><i class="ti ti-file-invoice"></i></span>
        <div>
          <h2 class="page-title mb-0">Vendas</h2>
          <div class="text-muted"><?= (int)$kpiCount ?> resultado(s) com filtros</div>
        </div>
      </div>
    </div>
    <div class="col-auto ms-auto">
      <a href="/sales/create.php" class="btn btn-primary">
        <i class="ti ti-plus me-1"></i> Nova Venda
      </a>
    </div>
  </div>
</div>

<?php if (!empty($_GET['msg'])): ?>
  <?php
    $msgs = [
      'deleted' => ['class'=>'success','text'=>'Venda excluída com sucesso.'],
      'error'   => ['class'=>'danger','text'=>'Falha ao processar a ação.'],
      'csrf'    => ['class'=>'warning','text'=>'Sessão expirada, tente novamente.'],
    ];
    $m = $msgs[$_GET['msg']] ?? null;
  ?>
  <?php if ($m): ?>
    <div class="alert alert-<?= h($m['class']) ?>"><?= h($m['text']) ?></div>
  <?php endif; ?>
<?php endif; ?>

<!-- KPIs -->
<div class="row row-cards mb-3">
  <div class="col-12 col-md-3">
    <div class="card kpi-card">
      <div class="card-body">
        <div class="subheader">Registros</div>
        <div class="h1 mb-0"><?= (int)$kpiCount ?></div>
      </div>
    </div>
  </div>
  <div class="col-12 col-md-3">
    <div class="card kpi-card">
      <div class="card-body">
        <div class="subheader">Total (lista)</div>
        <div class="h1 mb-0"><?= brl($kpiTotal) ?></div>
      </div>
    </div>
  </div>
  <div class="col-12 col-md-3">
    <div class="card kpi-card">
      <div class="card-body">
        <div class="subheader">Pago (lista)</div>
        <div class="h1 mb-0 text-success"><?= brl($kpiPaid) ?></div>
      </div>
    </div>
  </div>
  <div class="col-12 col-md-3">
    <div class="card kpi-card">
      <div class="card-body">
        <div class="subheader">Não pago (lista)</div>
        <div class="h1 mb-0 text-danger"><?= brl($kpiUnpaid) ?></div>
      </div>
    </div>
  </div>
</div>

<!-- Filtros -->
<div class="card mb-3">
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-12 col-md-4">
        <label class="form-label">Buscar</label>
        <div class="input-icon">
          <span class="input-icon-addon"><i class="ti ti-search"></i></span>
          <input class="form-control" name="q" value="<?= h($q) ?>" placeholder="Nº, cliente ou PNR">
        </div>
      </div>

      <div class="col-12 col-md-2">
        <label class="form-label">Status</label>
        <select class="form-select" name="status">
          <option value="" <?= $statusF===''?'selected':'' ?>>Todos</option>
          <option value="nao pago" <?= $statusF==='nao pago'?'selected':'' ?>>Não pago</option>
          <option value="pago parcial" <?= $statusF==='pago parcial'?'selected':'' ?>>Pago parcial</option>
          <option value="pago" <?= $statusF==='pago'?'selected':'' ?>>Pago</option>
        </select>
      </div>

      <div class="col-6 col-md-2">
        <label class="form-label">De (Emissão)</label>
        <input type="date" class="form-control" name="from" value="<?= h($from) ?>">
      </div>

      <div class="col-6 col-md-2">
        <label class="form-label">Até (Emissão)</label>
        <input type="date" class="form-control" name="to" value="<?= h($to) ?>">
      </div>

      <input type="hidden" name="sort" value="<?= h($sortParam) ?>">
      <input type="hidden" name="dir"  value="<?= h($dirParam) ?>">

      <div class="col-6 col-md-1">
        <label class="form-label">Por página</label>
        <select class="form-select" name="per_page">
          <?php foreach ([20,50,100] as $size): ?>
            <option value="<?= $size ?>" <?= $perPage===$size?'selected':'' ?>><?= $size ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 col-md-3 d-flex gap-2">
        <button class="btn btn-primary w-100" type="submit">
          <i class="ti ti-filter me-1"></i> Filtrar
        </button>
        <a class="btn btn-outline-secondary btn-clear" href="/sales/index.php" title="Limpar filtros">
          <i class="ti ti-refresh"></i> Limpar
        </a>
      </div>
    </form>

    <div class="mt-3 d-flex flex-wrap gap-2">
      <?php if ($q!==''): ?><span class="chip"><i class="ti ti-search"></i><?= h($q) ?></span><?php endif; ?>
      <?php if ($statusF!==''): ?><span class="chip"><i class="ti ti-flag"></i><?= h(status_label($statusF)) ?></span><?php endif; ?>
      <?php if ($from!==''): ?><span class="chip"><i class="ti ti-calendar"></i>de <?= h($from) ?></span><?php endif; ?>
      <?php if ($to!==''): ?><span class="chip"><i class="ti ti-calendar"></i>até <?= h($to) ?></span><?php endif; ?>
      <?php if (!$q && !$statusF && !$from && !$to): ?><span class="text-muted">Sem filtros aplicados.</span><?php endif; ?>
    </div>
  </div>
</div>

<!-- Tabela -->
<div class="card">
  <div class="card-header">
    <div class="card-title">Lista</div>
    <div class="ms-auto text-muted small d-none d-md-block">Clique na linha para abrir</div>
  </div>

  <div class="table-responsive">
    <table class="table table-vcenter row-hover sales-table">
      <thead>
        <tr>
          <th style="width:1%"><?= sort_link('id','#') ?></th>
          <th style="width:180px"><?= sort_link('number','Nº Venda') ?></th>
          <th class="col-client"><?= sort_link('client','Cliente') ?></th>
          <th class="nowrap" style="width:170px"><?= sort_link('pnr','PNR') ?></th>
          <?php if ($hasTravel): ?>
            <th class="nowrap" style="width:140px"><?= sort_link('travel','Viagem') ?></th>
          <?php endif; ?>
          <th class="nowrap" style="width:140px"><?= sort_link('issue','Emissão') ?></th>
          <th class="nowrap" style="width:140px"><?= sort_link('status','Status') ?></th>

          <!-- إصلاح عنوان عمود السعر: اجعله واضح (ليس "T") -->
          <th class="text-end nowrap col-amount" style="width:190px"><?= sort_link('amount','Total') ?></th>

          <!-- تصغير عمود الأزرار -->
          <th class="text-end sticky-actions nowrap" style="width:360px">Ações</th>
        </tr>
      </thead>

      <tbody>
        <?php foreach ($rows as $r): ?>
          <?php
            $id = (int)$r['id'];

            $statusNorm = function_exists('normalize_status')
              ? normalize_status($r['status'] ?? '')
              : strtolower(trim((string)$r['status']));
            if ($statusNorm === 'não pago') $statusNorm = 'nao pago';

            $badge = 'secondary';
            if ($statusNorm==='pago') $badge='success';
            elseif ($statusNorm==='pago parcial') $badge='warning';
            elseif ($statusNorm==='nao pago') $badge='danger';

            $pnr = trim((string)($r['pnr_code'] ?? ''));
            $invNo = (string)($r['invoice_number'] ?? '—');
            $openUrl = "/sales/show.php?id=".$id;

            // منع كسر "R$ 9.135,50" لسطرين
            $amountHtml = str_replace('R$ ', 'R$&nbsp;', brl($r['total_amount'] ?? 0));
          ?>

          <tr class="inv-row" data-open="<?= h($openUrl) ?>">
            <td class="text-muted"><?= $id ?></td>

            <td>
              <a href="<?= h($openUrl) ?>" class="fw-bold text-reset text-decoration-none mono" onclick="event.stopPropagation();">
                <?= h($invNo) ?>
              </a>
            </td>

            <td class="col-client">
              <div class="fw-semibold"><?= h($r['client_name'] ?? '—') ?></div>
              <div class="text-muted small">Cliente</div>
            </td>

            <td>
              <?php if ($pnr !== ''): ?>
                <div class="pnr-box js-copy-pnr" data-copy="<?= h($pnr) ?>" onclick="event.stopPropagation();">
                  <span class="pnr-code mono"><?= h($pnr) ?></span>
                  <span class="pnr-tooltip">Copiado!</span>
                </div>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>

            <?php if ($hasTravel): ?>
              <td class="mono"><?= h(ymd_to_br($r['travel_date'] ?? '')) ?></td>
            <?php endif; ?>

            <td class="mono"><?= h(ymd_to_br($r['issue_date'] ?? '')) ?></td>

            <td>
              <span class="badge bg-<?= h($badge) ?> js-badge"><?= h(status_label($statusNorm)) ?></span>
            </td>

            <!-- السعر: صف واحد + فوق طبقة Ações -->
            <td class="text-end fw-bold col-amount"><?= $amountHtml ?></td>

            <td class="text-end sticky-actions">
              <div class="acoes-2rows" onclick="event.stopPropagation();">
                <div class="acoes-row">
                  <a class="btn btn-sm btn-outline-primary" href="<?= h($openUrl) ?>">
                    <i class="ti ti-eye me-1"></i>Abrir
                  </a>

                  <a class="btn btn-sm btn-outline-amber" href="/sales/edit.php?id=<?= $id ?>">
                    <i class="ti ti-edit me-1"></i>Editar
                  </a>
<a class="btn btn-sm btn-outline-success" href="/sales/print.php?id=<?= $id ?>" target="_blank">
                    <i class="ti ti-file-invoice me-1"></i>Venda
                  </a>
                  <button type="button"
                          class="btn btn-sm <?= $statusNorm==='pago' ? 'btn-success' : 'btn-outline-success' ?> js-mark-paid"
                          data-id="<?= $id ?>"
                          data-number="<?= h($invNo) ?>"
                          data-current="<?= h($statusNorm) ?>">
                    <i class="ti ti-check me-1"></i><?= $statusNorm==='pago' ? 'Pago' : 'Marcar Pago' ?>
                  </button>
                </div>

                <div class="acoes-row">
                  
<a class="btn btn-sm btn-outline-secondary" href="/sales/tkt.php?id=<?= $id ?>" target="_blank">
  <i class="ti ti-ticket me-1"></i>Bilhete
</a>
<a class="btn btn-sm btn-outline-secondary" href="/sales/recibo.php?id=<?= $id ?>" target="_blank">
  <i class="ti ti-ticket me-1"></i>Recibo
</a>
                  <a class="btn btn-sm btn-outline-info" href="/sales/voucher.php?id=<?= $id ?>" target="_blank">
                    <i class="ti ti-ticket me-1"></i>Voucher
                  </a>

                  <form action="/sales/delete.php" method="post" onsubmit="return confirm('Excluir permanentemente a venda <?= h($invNo) ?>? Esta ação ficará registrada.');">
                    <input type="hidden" name="csrf" value="<?= h($token) ?>">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit">
                      <i class="ti ti-trash me-1"></i>Excluir
                    </button>
                  </form>
                </div>
              </div>
            </td>
          </tr>
        <?php endforeach; if (!$rows): ?>
          <tr>
            <td colspan="<?= $hasTravel ? 10 : 9 ?>" class="text-muted p-4">Nenhuma venda encontrada.</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="card-footer d-flex flex-wrap align-items-center gap-2">
    <div class="text-muted">Exibindo <?= $kpiCount ? ($offset + 1) : 0 ?>–<?= min($offset + count($rows), $kpiCount) ?> de <?= $kpiCount ?>.</div>
    <nav class="ms-auto" aria-label="Paginação de vendas">
      <div class="btn-list">
        <a class="btn btn-outline-secondary btn-sm <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= h(page_url(max(1, $page-1))) ?>">Anterior</a>
        <span class="btn btn-light btn-sm disabled">Página <?= $page ?> de <?= $totalPages ?></span>
        <a class="btn btn-outline-secondary btn-sm <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= h(page_url(min($totalPages, $page+1))) ?>">Próxima</a>
      </div>
    </nav>
  </div>
</div>

<input type="hidden" id="csrfToken" value="<?= h($token) ?>">

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
    setTimeout(()=> wrap.remove(), 2200);
  }

  function badgeClass(v){
    v = (v||'').toLowerCase().trim();
    if (v==='pago') return 'bg-success';
    if (v==='pago parcial') return 'bg-warning';
    if (v==='nao pago' || v==='não pago') return 'bg-danger';
    return 'bg-secondary';
  }

  function setBadgeText(row, text) {
    const b = row.querySelector('.js-badge');
    if (!b) return;
    const normalized = (text || '').toLowerCase().trim();
    b.textContent = normalized === 'nao pago' || normalized === 'não pago'
      ? 'Não pago'
      : (normalized === 'pago parcial' ? 'Pago parcial' : (normalized === 'pago' ? 'Pago' : (text || '—')));
    b.className = 'badge js-badge ' + badgeClass(text);
  }

  async function sendStatus(id, status){
    const csrf = document.getElementById('csrfToken')?.value || '';
    const resp = await fetch('/sales/update_status.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({ id: parseInt(id,10), status, csrf })
    });

    let data = null;
    try { data = await resp.json(); } catch(_) {}

    if (resp.ok && data && data.ok) return true;
    const err = (data && data.error) ? data.error : 'Falha ao atualizar.';
    throw new Error(err);
  }

  // فتح الفاتورة عند الضغط على الصف
  document.querySelectorAll('tr.inv-row').forEach(tr => {
    tr.addEventListener('click', () => {
      const url = tr.getAttribute('data-open');
      if (url) window.location.href = url;
    });
  });

  // PNR: نسخ بالضغط على الكود نفسه + تغيير لون + Tooltip
  document.querySelectorAll('.js-copy-pnr').forEach(box => {
    box.addEventListener('click', async (e) => {
      e.stopPropagation();
      const txt = box.dataset.copy || '';
      if (!txt) return;

      try {
        await navigator.clipboard.writeText(txt);
        box.classList.add('copied');
        setTimeout(() => box.classList.remove('copied'), 1600);
      } catch (err) {
        toast('Falha ao copiar', 'danger');
      }
    });
  });

  // زر Pago: أخضر فوراً بدون Reload (Optimistic UI + rollback عند الفشل)
  document.querySelectorAll('.js-mark-paid').forEach(btn => {
    btn.addEventListener('click', async (e) => {
      e.stopPropagation();

      const id = btn.dataset.id;
      const tr = btn.closest('tr');
      const current = (btn.dataset.current || '').toLowerCase().trim();
      if (current === 'pago') return toast('Já está Pago.', 'success');
      const number = btn.dataset.number || id;
      if (!window.confirm(`Confirmar o recebimento integral da venda ${number}? Esta ação ficará registrada.`)) return;

      btn.disabled = true;

      // optimistic UI
      btn.classList.remove('btn-outline-success');
      btn.classList.add('btn-success');
      btn.innerHTML = '<i class="ti ti-check me-1"></i>Pago';
      setBadgeText(tr, 'Pago');

      try {
        await sendStatus(id, 'pago');
        btn.dataset.current = 'pago';
        toast('Marcado como Pago!', 'success');
      } catch (err) {
        // rollback
        btn.classList.remove('btn-success');
        btn.classList.add('btn-outline-success');
        btn.innerHTML = '<i class="ti ti-check me-1"></i>Marcar Pago';
        setBadgeText(tr, current === 'nao pago' ? 'Não pago' : (current || '—'));
        toast(err.message || 'Erro', 'danger');
      } finally {
        btn.disabled = false;
      }
    });
  });
});
</script>

<?php require __DIR__ . '/../inc/footer.php'; ?>
