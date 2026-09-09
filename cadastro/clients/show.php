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

  return '<a class="link-secondary text-decoration-none" href="?' .
    htmlspecialchars(http_build_query($query), ENT_QUOTES, 'UTF-8') .
    '">' . htmlspecialchars($label . $icon, ENT_QUOTES, 'UTF-8') . '</a>';
}

$token = csrf_token();
$pageTitle = 'Cliente: ' . $c['name'];
require __DIR__ . '/../inc/header.php';
?>

<div class="page-header d-print-none">
  <div class="row align-items-center">
    <div class="col">
      <h2 class="page-title">Cliente</h2>
      <div class="text-muted mt-1">Detalhes completos</div>
    </div>
    <div class="col-auto ms-auto d-print-none">
      <a href="/clients/index.php" class="btn btn-outline-secondary me-2">
        <i class="ti ti-arrow-left"></i> Voltar
      </a>
      <a href="/sales/create.php?client_id=<?= (int)$c['id'] ?>" class="btn btn-outline-success">
        <i class="ti ti-receipt"></i> Nova Venda
      </a>
      <a href="/clients/edit.php?id=<?= (int)$c['id'] ?>" class="btn btn-primary">
        <i class="ti ti-edit me-1"></i>Editar
      </a>
      <form action="/clients/delete.php" method="post" class="d-inline"
            onsubmit="return confirm('Excluir este cliente?');">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">
        <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
        <button class="btn btn-outline-danger"><i class="ti ti-trash"></i> Excluir</button>
      </form>
    </div>
  </div>
</div>

<div class="row row-cards">
  <!-- معلومات أساسية -->
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">
        <h3 class="card-title">Informações do cliente</h3>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-4">
            <div class="text-muted">Tipo</div>
            <div><?= $c['client_type']==='pj' ? 'Pessoa Jurídica' : 'Pessoa Física' ?></div>
          </div>
          <div class="col-md-8">
            <div class="text-muted">Nome / Razão Social</div>
            <div class="h3 mb-1"><?= htmlspecialchars($c['name']) ?></div>
          </div>

          <div class="col-md-6">
            <div class="text-muted"><?= $c['client_type']==='pj' ? 'CNPJ' : 'CPF' ?></div>
            <div class="h3 mb-1"><?= htmlspecialchars($c['document'] ?: '—') ?></div>
          </div>
          <div class="col-md-6">
            <div class="text-muted">Data de Nascimento</div>
            <div><?= fmt_date_br($c['birth_date'] ?? '') ?></div>
          </div>
<div class="col-md-6">
  <div class="text-muted">Gênero</div>
  <div>
    <?php
      $g = $c['gender'] ?? null;
      echo $g==='M' ? 'Masculino' : ($g==='F' ? 'Feminino' : ($g==='O' ? 'Outro/Não informado' : '—'));
    ?>
  </div>
</div>

<div class="col-md-6">
  <div class="text-muted">Trabalha Na</div>
  <div><?= htmlspecialchars($c['employer_name'] ?? '—') ?></div>
</div>

          <div class="col-md-6">
            <div class="text-muted">Telefone</div>
            <div><?= htmlspecialchars($c['phone'] ?: '—') ?></div>
          </div>
          <div class="col-md-6">
            <div class="text-muted">E-mail</div>
            <div><?= htmlspecialchars($c['email'] ?: '—') ?></div>
          </div>

          <div class="col-12">
            <div class="text-muted">Endereço</div>
            <div><?= htmlspecialchars($c['address'] ?: '—') ?></div>
          </div>

          <div class="col-12">
            <div class="text-muted">Notas</div>
            <div><?= nl2br(htmlspecialchars($c['notes'] ?? '—')) ?></div>
          </div>
        </div>
      </div>
      <div class="card-footer text-muted">
        Criado: <?= fmt_date_br($c['created_at'] ?? '') ?>
        <?php if ($createdByName): ?> • Por: <?= htmlspecialchars($createdByName) ?><?php endif; ?>
        <?php if (!empty($c['updated_at'])): ?> • Atualizado: <?= fmt_date_br($c['updated_at']) ?><?php endif; ?>
      </div>
    </div>
  </div>

  <!-- أزرار سريعة / ملخص -->
  <div class="col-lg-5">
    <div class="card">
      <div class="card-body">
        <div class="subheader">Ações rápidas</div>
        <div class="btn-list mt-2">
          <a class="btn btn-outline-primary" href="/sales/create.php?client_id=<?= (int)$c['id'] ?>">
            <i class="ti ti-file-invoice"></i> Nova Venda
          </a>
          <?php if (!empty($c['email'])): ?>
          <a class="btn btn-outline-secondary" href="mailto:<?= htmlspecialchars($c['email']) ?>">
            <i class="ti ti-mail"></i> Enviar e-mail
          </a>
          <?php endif; ?>
          <?php if (!empty($c['phone'])): ?>
          <a class="btn btn-outline-secondary" href="tel:<?= htmlspecialchars(preg_replace('/\D+/', '', $c['phone'])) ?>">
            <i class="ti ti-phone"></i> Ligar
          </a>
          <?php $waPhone = only_digits($c['phone']); ?>
          <?php if ($waPhone !== ''): ?>
          <a class="btn btn-outline-success" href="https://wa.me/55<?= htmlspecialchars($waPhone) ?>" target="_blank" rel="noopener">
            <i class="ti ti-brand-whatsapp"></i> WhatsApp
          </a>
          <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Resumo فواتير -->
    <div class="card mt-3">
      <div class="card-body">
        <div class="subheader">Resumo</div>
        <div class="row g-3">
          <div class="col-6">
            <div class="text-muted">Vendas</div>
            <div class="h2 mb-0"><?= (int)$salesSummary['count'] ?></div>
          </div>
          <div class="col-6">
            <div class="text-muted">Total vendido</div>
            <div class="h2 mb-0"><?= brl($salesSummary['total']) ?></div>
          </div>
          <div class="col-6">
            <div class="text-muted">Pago</div>
            <div class="text-success fw-bold"><?= brl($salesSummary['paid']) ?></div>
          </div>
          <div class="col-6">
            <div class="text-muted">Em aberto</div>
            <div class="text-danger fw-bold"><?= brl($salesSummary['open']) ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- آخر فواتير -->
  <div class="col-12">
    <div class="card">
      <input type="hidden" id="csrfToken" value="<?= htmlspecialchars($token) ?>">
      <div class="card-header"><h3 class="card-title">Todas as vendas do cliente</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter">
          <thead>
            <tr>
              <th><?= client_sales_sort_link('id', '#') ?></th>
              <th><?= client_sales_sort_link('number', 'Nº Venda') ?></th>
              <th><?= client_sales_sort_link('pnr', 'PNR') ?></th>
              <th><?= client_sales_sort_link('travel', 'Data da viagem') ?></th>
              <th><?= client_sales_sort_link('passenger', 'Passageiro') ?></th>
              <th><?= client_sales_sort_link('issue', 'Data venda') ?></th>
              <th><?= client_sales_sort_link('status', 'Status pagamento') ?></th>
              <th class="text-end"><?= client_sales_sort_link('total', 'Total') ?></th>
              <th class="text-end">Ações</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($invoices): foreach ($invoices as $inv): ?>
              <?php
                $status = (string)($inv['_status_norm'] ?? '');
                $badge = 'bg-secondary';
                if ($status === 'pago') $badge = 'bg-success';
                elseif ($status === 'nao pago' || $status === 'unpaid') $badge = 'bg-danger';
                elseif ($status === 'pago parcial' || $status === 'partial') $badge = 'bg-warning';

                $pnr = (string)($inv['_pnr_display'] ?? '');
                $travelDate = $inv['_travel_date'] ?? '';
                $passengerNames = (string)($inv['_passenger_names'] ?? '');
              ?>
              <tr>
                <td><?= (int)$inv['id'] ?></td>
                <td><?= htmlspecialchars($inv['invoice_number'] ?? '—') ?></td>
                <td class="font-monospace"><?= htmlspecialchars($pnr !== '' ? $pnr : '—') ?></td>
                <td><?= fmt_date_br($travelDate) ?></td>
                <td><?= htmlspecialchars($passengerNames !== '' ? $passengerNames : '—') ?></td>
                <td><?= fmt_date_br($inv['issue_date'] ?? '') ?></td>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <span class="badge <?= $badge ?> js-payment-badge"><?= htmlspecialchars(status_label($status)) ?></span>
                    <select class="form-select form-select-sm js-payment-status"
                            data-id="<?= (int)$inv['id'] ?>"
                            data-invoice="<?= htmlspecialchars((string)($inv['invoice_number'] ?? '—')) ?>"
                            data-current="<?= htmlspecialchars($status) ?>"
                            aria-label="Status pagamento"
                            style="width: 135px;">
                      <option value="nao pago" <?= $status === 'nao pago' ? 'selected' : '' ?>>Não pago</option>
                      <option value="pago parcial" <?= $status === 'pago parcial' ? 'selected' : '' ?>>Pago parcial</option>
                      <option value="pago" <?= $status === 'pago' ? 'selected' : '' ?>>Pago</option>
                    </select>
                  </div>
                </td>
                <td class="text-end"><?= brl($inv['total_amount'] ?? 0) ?></td>
                <td class="text-end">
                  <a class="btn btn-sm btn-outline-secondary" href="/sales/show.php?id=<?= (int)$inv['id'] ?>">
                    <i class="ti ti-eye"></i> Ver
                  </a>
                </td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="9" class="text-muted">Ainda não há vendas para este cliente.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const csrf = document.getElementById('csrfToken')?.value || '';

  function badgeClass(status) {
    const value = (status || '').toLowerCase().trim();
    if (value === 'pago') return 'bg-success';
    if (value === 'pago parcial') return 'bg-warning';
    if (value === 'nao pago' || value === 'não pago') return 'bg-danger';
    return 'bg-secondary';
  }

  function setBadge(row, status) {
    const badge = row.querySelector('.js-payment-badge');
    if (!badge) return;
    badge.textContent = status === 'nao pago' ? 'Não pago' : (status === 'pago parcial' ? 'Pago parcial' : (status === 'pago' ? 'Pago' : (status || '—')));
    badge.className = 'badge js-payment-badge ' + badgeClass(status);
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

<?php require __DIR__ . '/../inc/footer.php'; ?>
