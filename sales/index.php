<?php
// sales/index.php — Lista de Vendas (modern layout, Phase 2)
// UI moderna + KPIs + filtros + sort + PNR copiável + "Marcar Pago" otimista

require __DIR__ . '/../inc/auth.php';
require_login();

require __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/ui.php';
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
  if ($curSort === $key) { $icon = $curDir === 'asc' ? '↑' : '↓'; }

  $qs = $_GET; $qs['sort'] = $key; $qs['dir'] = $nextDir;
  $href = '?' . http_build_query($qs);

  $active = $icon !== '' ? ' font-bold text-ink-900' : '';
  return '<a href="'.h($href).'" class="inline-flex items-center gap-1 whitespace-nowrap text-ink-500 transition hover:text-ink-900'.$active.'">'
       . h($label)
       . ($icon !== '' ? '<span class="text-brand-600">'.$icon.'</span>' : '')
       . '</a>';
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

/* badges + botão "Marcar Pago" state classes */
$PAID_SOLID   = 'btn-flex bg-emerald-500 text-white hover:bg-emerald-600';
$PAID_OUTLINE = 'btn-flex bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100';

ob_start();
?>
<!-- Header -->
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-xl font-bold text-ink-950">Vendas</h2>
        <p class="mt-0.5 text-sm text-ink-500"><?= (int)$kpiCount ?> resultado(s) com os filtros atuais</p>
    </div>
    <a href="/sales/create.php" class="btn-primary">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"></path><path d="M5 12h14"></path></svg>
        <span>Nova Venda</span>
    </a>
</div>

<?php if (!empty($_GET['msg'])): ?>
    <?php
      $msgs = [
        'deleted' => ['cls' => 'border-emerald-200 bg-emerald-50 text-emerald-800', 'text' => 'Venda excluída com sucesso.'],
        'error'   => ['cls' => 'border-red-200 bg-red-50 text-red-800', 'text' => 'Falha ao processar a ação.'],
        'csrf'    => ['cls' => 'border-amber-200 bg-amber-50 text-amber-800', 'text' => 'Sessão expirada, tente novamente.'],
      ];
      $m = $msgs[$_GET['msg']] ?? null;
    ?>
    <?php if ($m): ?>
        <div class="mb-4 flex items-start gap-2.5 rounded-xl border px-3.5 py-3 text-sm <?= h($m['cls']) ?>" role="alert">
            <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            <span><?= h($m['text']) ?></span>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- KPIs -->
<div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <div class="card p-5">
        <p class="stat-label">Registros</p>
        <p class="stat-value"><?= (int)$kpiCount ?></p>
    </div>
    <div class="card p-5">
        <p class="stat-label">Total (lista)</p>
        <p class="stat-value"><?= brl($kpiTotal) ?></p>
    </div>
    <div class="card p-5">
        <p class="stat-label">Pago (lista)</p>
        <p class="stat-value text-emerald-600"><?= brl($kpiPaid) ?></p>
    </div>
    <div class="card p-5">
        <p class="stat-label">Não pago (lista)</p>
        <p class="stat-value text-red-500"><?= brl($kpiUnpaid) ?></p>
    </div>
</div>

<!-- Filtros -->
<div class="card mb-5 p-5">
    <form method="get" class="grid grid-cols-1 items-end gap-4 md:grid-cols-2 xl:grid-cols-12">
        <div class="xl:col-span-4">
            <label class="label-field" for="f-q">Buscar</label>
            <input id="f-q" class="input-field" name="q" value="<?= h($q) ?>" placeholder="Nº, cliente ou PNR">
        </div>
        <div class="xl:col-span-2">
            <label class="label-field" for="f-status">Status</label>
            <select id="f-status" class="select-field" name="status">
                <option value="" <?= $statusF===''?'selected':'' ?>>Todos</option>
                <option value="nao pago" <?= $statusF==='nao pago'?'selected':'' ?>>Não pago</option>
                <option value="pago parcial" <?= $statusF==='pago parcial'?'selected':'' ?>>Pago parcial</option>
                <option value="pago" <?= $statusF==='pago'?'selected':'' ?>>Pago</option>
            </select>
        </div>
        <div class="xl:col-span-2">
            <label class="label-field" for="f-from">De (Emissão)</label>
            <input id="f-from" type="date" class="select-field" name="from" value="<?= h($from) ?>">
        </div>
        <div class="xl:col-span-2">
            <label class="label-field" for="f-to">Até (Emissão)</label>
            <input id="f-to" type="date" class="select-field" name="to" value="<?= h($to) ?>">
        </div>
        <div class="xl:col-span-2">
            <label class="label-field" for="f-per">Por página</label>
            <select id="f-per" class="select-field" name="per_page">
                <?php foreach ([20,50,100] as $size): ?>
                    <option value="<?= $size ?>" <?= $perPage===$size?'selected':'' ?>><?= $size ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <input type="hidden" name="sort" value="<?= h($sortParam) ?>">
        <input type="hidden" name="dir"  value="<?= h($dirParam) ?>">
        <div class="flex gap-2 md:col-span-2 xl:col-span-12">
            <button class="btn-primary flex-1 sm:flex-none sm:px-8" type="submit">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 3 2 3 10 12.46V19l4 2v-8.54L22 3z"></path></svg>
                Filtrar
            </button>
            <a class="btn-ghost" href="/sales/index.php">Limpar</a>
        </div>
    </form>

    <?php if ($q!=='' || $statusF!=='' || $from!=='' || $to!==''): ?>
    <div class="mt-4 flex flex-wrap items-center gap-2">
        <?php if ($q!==''): ?><span class="badge-soft bg-brand-100 text-brand-700">Busca: <?= h($q) ?></span><?php endif; ?>
        <?php if ($statusF!==''): ?><span class="badge-soft bg-brand-100 text-brand-700">Status: <?= h(function_exists('status_label') ? status_label($statusF) : $statusF) ?></span><?php endif; ?>
        <?php if ($from!==''): ?><span class="badge-soft bg-ink-100 text-ink-600">de <?= h($from) ?></span><?php endif; ?>
        <?php if ($to!==''): ?><span class="badge-soft bg-ink-100 text-ink-600">até <?= h($to) ?></span><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Tabela -->
<div class="card overflow-hidden">
    <div class="flex items-center justify-between border-b border-ink-100 px-5 py-4">
        <h3 class="text-sm font-bold text-ink-950">Lista de vendas</h3>
        <span class="hidden text-xs text-ink-400 sm:block">Clique na linha para abrir</span>
    </div>

    <div class="overflow-x-auto">
        <table class="table-modern min-w-[1180px]">
            <thead>
                <tr>
                    <th style="width:1%;white-space:nowrap"><?= sort_link('id','#') ?></th>
                    <th style="width:180px"><?= sort_link('number','Nº Venda') ?></th>
                    <th class="min-w-[340px]"><?= sort_link('client','Cliente') ?></th>
                    <th class="whitespace-nowrap" style="width:170px"><?= sort_link('pnr','PNR') ?></th>
                    <?php if ($hasTravel): ?>
                        <th class="whitespace-nowrap" style="width:140px"><?= sort_link('travel','Viagem') ?></th>
                    <?php endif; ?>
                    <th class="whitespace-nowrap" style="width:140px"><?= sort_link('issue','Emissão') ?></th>
                    <th class="whitespace-nowrap" style="width:140px"><?= sort_link('status','Status') ?></th>
                    <th class="whitespace-nowrap text-right" style="width:190px"><?= sort_link('amount','Total') ?></th>
                    <th class="sticky right-0 bg-white whitespace-nowrap text-right" style="width:330px">Ações</th>
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
                        $pnr = trim((string)($r['pnr_code'] ?? ''));
                        $invNo = (string)($r['invoice_number'] ?? '—');
                        $openUrl = "/sales/show.php?id=".$id;
                        $amountHtml = str_replace('R$ ', 'R$&nbsp;', brl($r['total_amount'] ?? 0));
                        $paidBtnCls = $statusNorm==='pago' ? $PAID_SOLID : $PAID_OUTLINE;
                        $paidBtnTxt = $statusNorm==='pago' ? 'Pago' : 'Marcar Pago';
                    ?>
                    <tr class="inv-row cursor-pointer" data-open="<?= h($openUrl) ?>">
                        <td class="text-ink-400"><?= $id ?></td>
                        <td>
                            <a href="<?= h($openUrl) ?>" class="font-mono text-sm font-bold text-ink-950 hover:text-brand-600" onclick="event.stopPropagation();">
                                <?= h($invNo) ?>
                            </a>
                        </td>
                        <td>
                            <p class="font-semibold text-ink-950"><?= h($r['client_name'] ?? '—') ?></p>
                            <p class="text-xs text-ink-400">Cliente</p>
                        </td>
                        <td>
                            <?php if ($pnr !== ''): ?>
                                <div class="pnr-copy js-copy-pnr" data-copy="<?= h($pnr) ?>" title="Clique para copiar" onclick="event.stopPropagation();">
                                    <span><?= h($pnr) ?></span>
                                    <span class="pnr-tip">Copiado!</span>
                                </div>
                            <?php else: ?>
                                <span class="text-ink-300">—</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($hasTravel): ?>
                            <td class="font-mono text-ink-600"><?= h(ymd_to_br($r['travel_date'] ?? '')) ?></td>
                        <?php endif; ?>
                        <td class="font-mono text-ink-600"><?= h(ymd_to_br($r['issue_date'] ?? '')) ?></td>
                        <td>
                            <span class="badge-soft js-badge <?= ui_status_badge((string)$statusNorm) ?>"><?= h(ui_status_label((string)$statusNorm)) ?></span>
                        </td>
                        <td class="whitespace-nowrap text-right font-bold text-ink-950"><?= $amountHtml ?></td>
                        <td class="sticky right-0 bg-white" onclick="event.stopPropagation();">
                            <div class="flex flex-wrap justify-end gap-1.5">
                                <a class="btn-soft border-brand-200 text-brand-700 hover:bg-brand-50" href="<?= h($openUrl) ?>" title="Abrir rascunho da venda">
                                    Abrir
                                </a>
                                <a class="btn-soft border-amber-200 text-amber-700 hover:bg-amber-50" href="/sales/edit.php?id=<?= $id ?>">
                                    Editar
                                </a>
                                <a class="btn-soft" href="/sales/print.php?id=<?= $id ?>" target="_blank">
                                    Venda
                                </a>
                                <button type="button"
                                        class="js-mark-paid <?= $paidBtnCls ?>"
                                        data-id="<?= $id ?>"
                                        data-number="<?= h($invNo) ?>"
                                        data-current="<?= h($statusNorm) ?>">
                                    <?= h($paidBtnTxt) ?>
                                </button>
                                <div class="flex gap-1.5">
                                    <a class="btn-soft" href="/sales/tkt.php?id=<?= $id ?>" target="_blank" title="Bilhete">Bilhete</a>
                                    <a class="btn-soft" href="/sales/recibo.php?id=<?= $id ?>" target="_blank" title="Recibo">Recibo</a>
                                    <a class="btn-soft" href="/sales/voucher.php?id=<?= $id ?>" target="_blank" title="Voucher">Voucher</a>
                                </div>
                                <form action="/sales/delete.php" method="post" onsubmit="return confirm('Excluir permanentemente a venda <?= h($invNo) ?>? Esta ação ficará registrada.');">
                                    <input type="hidden" name="csrf" value="<?= h($token) ?>">
                                    <input type="hidden" name="id" value="<?= $id ?>">
                                    <button class="btn-soft border-red-200 text-red-700 hover:bg-red-50" type="submit">
                                        Excluir
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; if (!$rows): ?>
                    <tr>
                        <td colspan="<?= $hasTravel ? 9 : 8 ?>" class="px-4 py-10 text-center text-sm text-ink-400">Nenhuma venda encontrada.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="flex flex-wrap items-center gap-3 border-t border-ink-100 px-5 py-4">
        <span class="text-sm text-ink-500">
            Exibindo <?= $kpiCount ? ($offset + 1) : 0 ?>–<?= min($offset + count($rows), $kpiCount) ?> de <?= $kpiCount ?>
        </span>
        <nav class="ml-auto flex items-center gap-2" aria-label="Paginação de vendas">
            <a class="btn-soft <?= $page <= 1 ? 'pointer-events-none opacity-40' : '' ?>" href="<?= h(page_url(max(1, $page-1))) ?>">Anterior</a>
            <span class="px-2 text-sm font-medium text-ink-600">Página <?= $page ?> de <?= $totalPages ?></span>
            <a class="btn-soft <?= $page >= $totalPages ? 'pointer-events-none opacity-40' : '' ?>" href="<?= h(page_url(min($totalPages, $page+1))) ?>">Próxima</a>
        </nav>
    </div>
</div>

<input type="hidden" id="csrfToken" value="<?= h($token) ?>">

<script>
document.addEventListener('DOMContentLoaded', () => {
  const PAID_SOLID   = 'btn-flex bg-emerald-500 text-white hover:bg-emerald-600';
  const PAID_OUTLINE = 'btn-flex bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100';

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
    setTimeout(()=> wrap.remove(), 2200);
  }

  function badgeClass(v){
    v = (v||'').toLowerCase().trim();
    if (v==='nao pago' || v==='não pago') return 'badge-soft bg-red-100 text-red-700';
    if (v==='pago parcial' || v==='parcial') return 'badge-soft bg-amber-100 text-amber-700';
    if (v==='pago' || v==='paid') return 'badge-soft bg-emerald-100 text-emerald-700';
    return 'badge-soft bg-ink-100 text-ink-600';
  }

  function labelOf(v){
    v = (v||'').toLowerCase().trim();
    if (v==='nao pago' || v==='não pago') return 'Não pago';
    if (v==='pago parcial' || v==='parcial') return 'Pago parcial';
    if (v==='pago' || v==='paid') return 'Pago';
    return v || '—';
  }

  function setBadge(row, text) {
    const b = row.querySelector('.js-badge');
    if (!b) return;
    b.textContent = labelOf(text);
    b.className = badgeClass(text);
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
    throw new Error((data && data.error) ? data.error : 'Falha ao atualizar.');
  }

  // abrir fatura ao clicar na linha
  document.querySelectorAll('tr.inv-row').forEach(tr => {
    tr.addEventListener('click', () => {
      const url = tr.getAttribute('data-open');
      if (url) window.location.href = url;
    });
  });

  // PNR: copiar ao clicar
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

  // Marcar Pago: otimista
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
      // optimistic
      btn.className = PAID_SOLID;
      btn.textContent = 'Pago';
      setBadge(tr, 'Pago');

      try {
        await sendStatus(id, 'pago');
        btn.dataset.current = 'pago';
        toast('Marcado como Pago!', 'success');
      } catch (err) {
        btn.className = PAID_OUTLINE;
        btn.textContent = 'Marcar Pago';
        setBadge(tr, current);
        toast(err.message || 'Erro', 'danger');
      } finally {
        btn.disabled = false;
      }
    });
  });
});
</script>
<?php
$body = ob_get_clean();
require __DIR__ . '/../inc/layout.php';