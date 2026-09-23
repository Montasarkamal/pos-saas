<?php
// clients/show.php — عرض كامل للتفاصيل
require __DIR__ . '/../inc/auth.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
[$agencyCondition, $agencyParams] = agency_scope_sql('c.agency_id');
$st = $pdo->prepare("
  SELECT c.id, c.client_type, c.name, c.document, c.phone, c.email,
         c.birth_date, c.gender, c.address, c.notes,
         c.created_at, c.updated_at, c.created_by,
         e.name AS employer_name
  FROM clients c
  LEFT JOIN clients e ON e.id = c.employer_id AND e.agency_id = c.agency_id
  WHERE c.id=? AND $agencyCondition
");
$st->execute(array_merge([$id], $agencyParams));
$c = $st->fetch();
if (!$c) { http_response_code(404); exit('Cliente não encontrado'); }

$salesSort = $_GET['sort'] ?? 'issue';
$salesDir = strtolower((string)($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
$salesSortAllowed = ['id', 'number', 'pnr', 'travel', 'passenger', 'issue', 'status', 'total'];
if (!in_array($salesSort, $salesSortAllowed, true)) $salesSort = 'issue';

// جلب كل مبيعات العميل مع تفاصيل السفر الأساسية
$invoices = [];
try {
  $si = $pdo->prepare("
    SELECT
      i.id,
      i.invoice_number,
      i.issue_date,
      i.status,
      i.total_amount,
      i.pnr_code,
      i.travel_date,
      (
        SELECT GROUP_CONCAT(p.name ORDER BY p.id SEPARATOR ', ')
        FROM passengers p
        WHERE p.invoice_id = i.id
      ) AS passenger_names,
      (
        SELECT GROUP_CONCAT(DISTINCT it.pnr_code ORDER BY it.trip_no SEPARATOR ', ')
        FROM invoice_trips it
        WHERE it.invoice_id = i.id AND it.pnr_code IS NOT NULL AND it.pnr_code <> ''
      ) AS trip_pnrs,
      (
        SELECT GROUP_CONCAT(DISTINCT s.record_locator ORDER BY s.id SEPARATOR ', ')
        FROM segments s
        WHERE s.invoice_id = i.id AND s.record_locator IS NOT NULL AND s.record_locator <> ''
      ) AS segment_pnrs,
      (
        SELECT MIN(it.travel_date)
        FROM invoice_trips it
        WHERE it.invoice_id = i.id AND it.travel_date IS NOT NULL
      ) AS trip_travel_date
    FROM invoices i
    WHERE i.client_id=? AND " . (is_superadmin() ? "1=1" : "i.agency_id=?") . "
    ORDER BY i.issue_date DESC, i.id DESC
  ");
  $si->execute(array_merge([$id], is_superadmin() ? [] : [agency_id()]));
  $invoices = $si->fetchAll();
} catch (Throwable $e) { /* ignore */ }

foreach ($invoices as &$inv) {
  $status = strtolower(trim((string)($inv['status'] ?? '')));
  if ($status === 'não pago') $status = 'nao pago';
  $inv['_status_norm'] = $status;

  $pnrParts = [];
  foreach (['pnr_code', 'trip_pnrs', 'segment_pnrs'] as $pnrKey) {
    $value = trim((string)($inv[$pnrKey] ?? ''));
    if ($value !== '') $pnrParts[] = $value;
  }
  $inv['_pnr_display'] = $pnrParts ? implode(', ', array_unique($pnrParts)) : '';
  $inv['_travel_date'] = $inv['travel_date'] ?: ($inv['trip_travel_date'] ?? '');
  $inv['_passenger_names'] = trim((string)($inv['passenger_names'] ?? ''));
}
unset($inv);

usort($invoices, static function (array $a, array $b) use ($salesSort, $salesDir): int {
  $value = static function (array $row) use ($salesSort) {
    return match ($salesSort) {
      'id' => (int)($row['id'] ?? 0),
      'number' => strtolower((string)($row['invoice_number'] ?? '')),
      'pnr' => strtolower((string)($row['_pnr_display'] ?? '')),
      'travel' => $row['_travel_date'] ? strtotime((string)$row['_travel_date']) : null,
      'passenger' => strtolower((string)($row['_passenger_names'] ?? '')),
      'status' => strtolower((string)($row['_status_norm'] ?? '')),
      'total' => (float)($row['total_amount'] ?? 0),
      default => $row['issue_date'] ? strtotime((string)$row['issue_date']) : null,
    };
  };

  $va = $value($a);
  $vb = $value($b);

  if ($va === null && $vb === null) return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
  if ($va === null) return 1;
  if ($vb === null) return -1;

  $cmp = $va <=> $vb;
  if ($cmp === 0) $cmp = ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
  return $salesDir === 'asc' ? $cmp : -$cmp;
});

$salesSummary = [
  'count' => 0,
  'total' => 0.0,
  'paid' => 0.0,
  'open' => 0.0,
];
try {
  $ss = $pdo->prepare("
    SELECT
      COUNT(*) AS sale_count,
      COALESCE(SUM(total_amount), 0) AS total_amount,
      COALESCE(SUM(CASE WHEN LOWER(TRIM(status)) IN ('pago','paid') THEN total_amount ELSE 0 END), 0) AS paid_amount
    FROM invoices
    WHERE client_id=? AND " . (is_superadmin() ? "1=1" : "agency_id=?") . " AND LOWER(TRIM(status)) <> 'cancelado'
  ");
  $ss->execute(array_merge([$id], is_superadmin() ? [] : [agency_id()]));
  $summary = $ss->fetch(PDO::FETCH_ASSOC) ?: [];
  $salesSummary['count'] = (int)($summary['sale_count'] ?? 0);
  $salesSummary['total'] = (float)($summary['total_amount'] ?? 0);
  $salesSummary['paid'] = (float)($summary['paid_amount'] ?? 0);
  $salesSummary['open'] = max(0, $salesSummary['total'] - $salesSummary['paid']);
} catch (Throwable $e) { /* ignore */ }

// جلب اسم المستخدم الذي أنشأ السجل
$createdByName = null;
if (!empty($c['created_by'])) {
  try {
    $su = $pdo->prepare("SELECT name FROM users WHERE id=?");
    $su->execute([(int)$c['created_by']]);
    $u = $su->fetch();
    if ($u) $createdByName = $u['name'];
  } catch (Throwable $e) {}
}

// helpers
function fmt_date_br($s){
  if (!$s) return '—';
  $t = strtotime($s);
  return $t ? date('d/m/Y', $t) : htmlspecialchars($s);
}
function brl($n){
  return 'R$ ' . number_format((float)$n, 2, ',', '.');
}

function only_digits($s): string {
  return preg_replace('/\D+/', '', (string)$s) ?? '';
}

function client_sales_sort_link(string $key, string $label): string {
  $currentSort = $_GET['sort'] ?? 'issue';
  $currentDir = strtolower((string)($_GET['dir'] ?? 'desc'));
  $nextDir = ($currentSort === $key && $currentDir === 'asc') ? 'desc' : 'asc';
  $icon = $currentSort === $key ? ($currentDir === 'asc' ? ' ▲' : ' ▼') : '';

  $query = $_GET;
  $query['sort'] = $key;
  $query['dir'] = $nextDir;

  return '<a class="text-ink-500 hover:text-brand-600" href="?' .
    htmlspecialchars(http_build_query($query), ENT_QUOTES, 'UTF-8') .
    '">' . htmlspecialchars($label . $icon, ENT_QUOTES, 'UTF-8') . '</a>';
}

$token = csrf_token();
$pageTitle = 'Cliente: ' . $c['name'];
ob_start();
require_once __DIR__ . '/../inc/ui.php';
?>
<input type="hidden" id="csrfToken" value="<?= htmlspecialchars($token) ?>">

<!-- Header -->
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
  <div>
    <p class="text-xs font-bold uppercase tracking-wider text-brand-600">Cliente</p>
    <h2 class="text-xl font-bold text-ink-950"><?= htmlspecialchars($c['name']) ?></h2>
    <p class="mt-0.5 text-sm text-ink-500">Detalhes completos</p>
  </div>
  <div class="flex flex-wrap items-center gap-2">
    <a class="btn-ghost" href="/clients/index.php">
      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"></polyline></svg>
      Voltar
    </a>
    <a class="btn-ghost" href="/sales/create.php?client_id=<?= (int)$c['id'] ?>">
      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><path d="M14 2v6h6"></path><path d="M12 18v-6"></path><path d="M9 15h6"></path></svg>
      Nova Venda
    </a>
    <a class="btn-primary" href="/clients/edit.php?id=<?= (int)$c['id'] ?>">
      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
      Editar
    </a>
    <form action="/clients/delete.php" method="post" class="inline" onsubmit="return confirm('Excluir este cliente?');">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">
      <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
      <button class="btn-ghost hover:bg-red-50" type="submit">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
        Excluir
      </button>
    </form>
  </div>
</div>

<!-- Layout principal -->
<div class="grid grid-cols-1 gap-4 xl:grid-cols-12">
  <!-- Informações do cliente -->
  <div class="card overflow-hidden xl:col-span-7">
    <div class="border-b border-ink-100 px-5 py-4">
      <h3 class="text-sm font-bold text-ink-950">Informações do cliente</h3>
    </div>
    <div class="p-5">
      <div class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
        <div>
          <p class="text-[11px] font-bold uppercase tracking-wider text-ink-400">Tipo</p>
          <p class="mt-1"><?= $c['client_type'] === 'pj' ? 'Pessoa Jurídica' : 'Pessoa Física' ?></p>
        </div>
        <div>
          <p class="text-[11px] font-bold uppercase tracking-wider text-ink-400">Nome / Razão Social</p>
          <p class="mt-1 text-lg font-bold text-ink-950"><?= htmlspecialchars($c['name']) ?></p>
        </div>
        <div>
          <p class="text-[11px] font-bold uppercase tracking-wider text-ink-400"><?= $c['client_type'] === 'pj' ? 'CNPJ' : 'CPF' ?></p>
          <p class="mt-1 font-semibold text-ink-950"><?= htmlspecialchars($c['document'] ?: '—') ?></p>
        </div>
        <div>
          <p class="text-[11px] font-bold uppercase tracking-wider text-ink-400">Data de Nascimento</p>
          <p class="mt-1"><?= fmt_date_br($c['birth_date'] ?? '') ?></p>
        </div>
        <div>
          <p class="text-[11px] font-bold uppercase tracking-wider text-ink-400">Gênero</p>
          <p class="mt-1">
            <?php
              $g = $c['gender'] ?? null;
              echo $g === 'M' ? 'Masculino' : ($g === 'F' ? 'Feminino' : ($g === 'O' ? 'Outro/Não informado' : '—'));
            ?>
          </p>
        </div>
        <div>
          <p class="text-[11px] font-bold uppercase tracking-wider text-ink-400">Trabalha Na</p>
          <p class="mt-1"><?= htmlspecialchars($c['employer_name'] ?? '—') ?></p>
        </div>
        <div>
          <p class="text-[11px] font-bold uppercase tracking-wider text-ink-400">Telefone</p>
          <p class="mt-1"><?= htmlspecialchars($c['phone'] ?: '—') ?></p>
        </div>
        <div>
          <p class="text-[11px] font-bold uppercase tracking-wider text-ink-400">E-mail</p>
          <p class="mt-1"><?= htmlspecialchars($c['email'] ?: '—') ?></p>
        </div>
        <div class="sm:col-span-2">
          <p class="text-[11px] font-bold uppercase tracking-wider text-ink-400">Endereço</p>
          <p class="mt-1"><?= htmlspecialchars($c['address'] ?: '—') ?></p>
        </div>
        <div class="sm:col-span-2">
          <p class="text-[11px] font-bold uppercase tracking-wider text-ink-400">Notas</p>
          <p class="mt-1"><?= nl2br(htmlspecialchars($c['notes'] ?? '—')) ?></p>
        </div>
      </div>
      <div class="mt-5 border-t border-ink-100 pt-4 text-xs text-ink-400">
        Criado: <?= fmt_date_br($c['created_at'] ?? '') ?>
        <?php if ($createdByName): ?> • Por: <?= htmlspecialchars($createdByName) ?><?php endif; ?>
        <?php if (!empty($c['updated_at'])): ?> • Atualizado: <?= fmt_date_br($c['updated_at']) ?><?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Coluna direita: ações + resumo -->
  <div class="grid gap-4 xl:col-span-5">
    <div class="card overflow-hidden">
      <div class="border-b border-ink-100 px-5 py-4">
        <h3 class="text-sm font-bold text-ink-950">Ações rápidas</h3>
      </div>
      <div class="p-5">
        <div class="grid gap-2">
          <a class="btn-primary w-full" href="/sales/create.php?client_id=<?= (int)$c['id'] ?>">Nova Venda</a>
          <?php if (!empty($c['email'])): ?>
            <a class="btn-ghost w-full" href="mailto:<?= htmlspecialchars($c['email']) ?>">Enviar e-mail</a>
          <?php endif; ?>
          <?php if (!empty($c['phone'])): ?>
            <a class="btn-ghost w-full" href="tel:<?= htmlspecialchars(preg_replace('/\D+/', '', $c['phone'])) ?>">Ligar</a>
            <?php $waPhone = only_digits($c['phone']); ?>
            <?php if ($waPhone !== ''): ?>
              <a class="btn-ghost w-full hover:bg-emerald-50" href="https://wa.me/55<?= htmlspecialchars($waPhone) ?>" target="_blank" rel="noopener">WhatsApp</a>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="card overflow-hidden">
      <div class="border-b border-ink-100 px-5 py-4">
        <h3 class="text-sm font-bold text-ink-950">Resumo</h3>
      </div>
      <div class="p-5">
        <div class="grid grid-cols-2 gap-4">
          <div>
            <p class="stat-label">Vendas</p>
            <p class="stat-value"><?= (int)$salesSummary['count'] ?></p>
          </div>
          <div>
            <p class="stat-label">Total vendido</p>
            <p class="stat-value"><?= brl($salesSummary['total']) ?></p>
          </div>
          <div>
            <p class="stat-label">Pago</p>
            <p class="stat-value text-emerald-600"><?= brl($salesSummary['paid']) ?></p>
          </div>
          <div>
            <p class="stat-label">Em aberto</p>
            <p class="stat-value text-red-500"><?= brl($salesSummary['open']) ?></p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Todas as vendas -->
  <div class="card overflow-hidden xl:col-span-12">
    <div class="border-b border-ink-100 px-5 py-4">
      <h3 class="text-sm font-bold text-ink-950">Todas as vendas do cliente</h3>
    </div>
    <div class="overflow-x-auto">
      <table class="table-modern min-w-[980px]">
        <thead>
          <tr>
            <th><?= client_sales_sort_link('id', '#') ?></th>
            <th><?= client_sales_sort_link('number', 'Nº Venda') ?></th>
            <th><?= client_sales_sort_link('pnr', 'PNR') ?></th>
            <th><?= client_sales_sort_link('travel', 'Data da viagem') ?></th>
            <th><?= client_sales_sort_link('passenger', 'Passageiro') ?></th>
            <th><?= client_sales_sort_link('issue', 'Data venda') ?></th>
            <th><?= client_sales_sort_link('status', 'Status pagamento') ?></th>
            <th class="text-right"><?= client_sales_sort_link('total', 'Total') ?></th>
            <th class="text-right">Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($invoices): foreach ($invoices as $inv): ?>
            <?php
              $status = (string)($inv['_status_norm'] ?? '');
              $pnr = (string)($inv['_pnr_display'] ?? '');
              $travelDate = $inv['_travel_date'] ?? '';
              $passengerNames = (string)($inv['_passenger_names'] ?? '');
            ?>
            <tr>
              <td class="font-mono text-xs"><?= (int)$inv['id'] ?></td>
              <td><?= htmlspecialchars($inv['invoice_number'] ?? '—') ?></td>
              <td class="font-mono"><?= htmlspecialchars($pnr !== '' ? $pnr : '—') ?></td>
              <td><?= fmt_date_br($travelDate) ?></td>
              <td><?= htmlspecialchars($passengerNames !== '' ? $passengerNames : '—') ?></td>
              <td><?= fmt_date_br($inv['issue_date'] ?? '') ?></td>
              <td>
                <div class="flex items-center gap-2">
                  <span class="<?= ui_status_badge($status) ?> js-payment-badge"><?= htmlspecialchars(ui_status_label($status)) ?></span>
                  <select class="select-field js-payment-status"
                          data-id="<?= (int)$inv['id'] ?>"
                          data-invoice="<?= htmlspecialchars((string)($inv['invoice_number'] ?? '—')) ?>"
                          data-current="<?= htmlspecialchars($status) ?>"
                          aria-label="Status pagamento"
                          style="width:135px;">
                    <option value="nao pago" <?= $status === 'nao pago' ? 'selected' : '' ?>>Não pago</option>
                    <option value="pago parcial" <?= $status === 'pago parcial' ? 'selected' : '' ?>>Pago parcial</option>
                    <option value="pago" <?= $status === 'pago' ? 'selected' : '' ?>>Pago</option>
                  </select>
                </div>
              </td>
              <td class="text-right tabular-nums font-semibold"><?= brl($inv['total_amount'] ?? 0) ?></td>
              <td class="text-right">
                <div class="flex justify-end">
                  <a class="btn-xs border border-ink-200 bg-white text-ink-700 hover:bg-ink-50" href="/sales/show.php?id=<?= (int)$inv['id'] ?>">Ver</a>
                </div>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="9" class="px-4 py-8 text-center text-sm text-ink-400">Ainda não há vendas para este cliente.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const csrf = document.getElementById('csrfToken')?.value || '';

  function badgeClass(status) {
    const value = (status || '').toLowerCase().trim();
    if (value === 'pago') return 'badge-soft bg-emerald-100 text-emerald-700';
    if (value === 'pago parcial') return 'badge-soft bg-amber-100 text-amber-700';
    if (value === 'nao pago' || value === 'não pago') return 'badge-soft bg-red-100 text-red-700';
    return 'badge-soft bg-ink-100 text-ink-600';
  }

  function setBadge(row, status) {
    const badge = row.querySelector('.js-payment-badge');
    if (!badge) return;
    badge.textContent = status === 'nao pago' ? 'Não pago' : (status === 'pago parcial' ? 'Pago parcial' : (status === 'pago' ? 'Pago' : (status || '—')));
    badge.className = 'js-payment-badge ' + badgeClass(status);
  }

  async function updateStatus(id, status) {
    const response = await fetch('/sales/update_status.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({id: parseInt(id, 10), status, csrf})
    });

    let data = null;
    try { data = await response.json(); } catch (_) {}
    if (response.ok && data && data.ok) return data.status || status;
    throw new Error((data && data.error) ? data.error : 'Falha ao atualizar status.');
  }

  document.querySelectorAll('.js-payment-status').forEach(select => {
    select.addEventListener('change', async () => {
      const row = select.closest('tr');
      const previous = select.dataset.current || '';
      const next = select.value;

      const previousLabel = previous === 'nao pago' ? 'Não pago' : (previous === 'pago parcial' ? 'Pago parcial' : 'Pago');
      const nextLabel = next === 'nao pago' ? 'Não pago' : (next === 'pago parcial' ? 'Pago parcial' : 'Pago');
      if (!confirm(`Alterar o pagamento da venda #${select.dataset.invoice || '—'} de “${previousLabel}” para “${nextLabel}”? A alteração será registrada.`)) {
        select.value = previous;
        return;
      }

      select.disabled = true;
      setBadge(row, next);

      try {
        const savedStatus = await updateStatus(select.dataset.id, next);
        select.dataset.current = savedStatus;
        setBadge(row, savedStatus);
      } catch (err) {
        select.value = previous;
        setBadge(row, previous);
        alert(err.message || 'Erro ao atualizar status.');
      } finally {
        select.disabled = false;
      }
    });
  });
});
</script>
<?php
$body = ob_get_clean();
require __DIR__ . '/../inc/layout.php';