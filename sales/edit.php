<?php
/* sales/edit.php — Editar Venda (id via GET) */
declare(strict_types=1);

require __DIR__ . '/../inc/auth.php'; require_login();
require_once __DIR__ . '/../inc/helpers.php';
require __DIR__ . '/../inc/invoices_lib.php';

function to_upper($s){ return mb_strtoupper(trim((string)$s), 'UTF-8'); }
function num($s){
  $s = trim((string)$s);
  if ($s==='') return 0.0;
  $s = str_replace([' ', "\u{00A0}"], '', $s);
  if (preg_match('/,\d{1,2}$/', $s)) { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); }
  return (float)$s;
}
function sanitize_date($s){ $s = trim((string)$s); return $s !== '' ? $s : null; }
if (!function_exists('airline_label_from_code')) {
  function airline_label_from_code($code, $airlines){
    foreach ((array)$airlines as $a){
      if (isset($a['code']) && strtoupper($a['code']) === strtoupper($code)){
        return $a['label'] ?? $code;
      }
    }
    return $code;
  }
}

/* carregar venda + passageiros + segmentos + serviços */
$id = (int)($_GET['id'] ?? 0);
if ($id<=0) { http_response_code(404); exit('ID inválido'); }

$err = '';
[$invoiceAgencyCondition, $invoiceAgencyParams] = agency_scope_sql('agency_id');
$st = $pdo->prepare("SELECT * FROM invoices WHERE id=? AND $invoiceAgencyCondition LIMIT 1");
$st->execute(array_merge([$id], $invoiceAgencyParams));
$inv = $st->fetch(PDO::FETCH_ASSOC);
if (!$inv) { http_response_code(404); exit('Venda não encontrada'); }
$invoiceAgencyId = (int)($inv['agency_id'] ?? agency_id());

$sp = $pdo->prepare("SELECT id, name, ptype, ticket_no, value FROM passengers WHERE invoice_id=? ORDER BY id");
$sp->execute([$id]);
$passengers = $sp->fetchAll(PDO::FETCH_ASSOC);

$ss = $pdo->prepare("SELECT id, airline_code, flight_no, `origin`, `destination`, `class`, `baggage`, record_locator
                     FROM segments WHERE invoice_id=? ORDER BY id");
$ss->execute([$id]);
$segments = $ss->fetchAll(PDO::FETCH_ASSOC);

$sa = $pdo->prepare("SELECT id, code, service, value FROM aux_services WHERE invoice_id=? ORDER BY id");
$sa->execute([$id]);
$aux_rows = $sa->fetchAll(PDO::FETCH_ASSOC);

/* salvar (POST) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check($_POST['csrf'] ?? '')) {
    $err = 'Sessão expirada. Recarregue a página e tente novamente.';
  } else {
    // 1) campos principais
    $client_id   = (int)($_POST['client_id'] ?? 0);
    $supplier_id = ($_POST['supplier_id'] ?? '') !== '' ? (int)$_POST['supplier_id'] : null;
    $issue_date  = $_POST['issue_date'] ?? $inv['issue_date'];
    $status      = $_POST['status'] ?? $inv['status'];
    $currency    = $_POST['currency'] ?? $inv['currency'];
    $pnr_code    = to_upper(preg_replace('/\s+/', '', (string)($_POST['pnr_code'] ?? '')));
    $travel_date = sanitize_date($_POST['travel_date'] ?? (string)$inv['travel_date']);
    $scope       = $_POST['scope'] ?? ($inv['scope'] ?? 'nacional');

    // fornecedor
    $tarifa   = num($_POST['supplier_tarifa']   ?? $inv['supplier_tarifa']);
    $comissao = num($_POST['supplier_comissao'] ?? $inv['supplier_comissao']);
    $supplier_liquid = max(0, $tarifa - $comissao);
    $supplier_paid   = isset($_POST['supplier_paid']) ? 1 : 0;

    // regras
    $refund_rule = $_POST['refund_rule'] ?? ($inv['refund_rule'] ?? 'nao reembolsavel');
    $change_rule = $_POST['change_rule'] ?? ($inv['change_rule'] ?? 'nao permite');

    // arrays passageiros
    $p_names   = $_POST['p_name']   ?? [];
    $p_types   = $_POST['p_type']   ?? [];
    $p_tickets = $_POST['p_ticket'] ?? [];
    $p_values  = $_POST['p_value']  ?? [];

    // arrays segmentos
    $s_air = $_POST['s_airline'] ?? [];
    $s_fno = $_POST['s_flight']  ?? [];
    $s_org = $_POST['s_origin']  ?? [];
    $s_dst = $_POST['s_dest']    ?? [];
    $s_cls = $_POST['s_class']   ?? [];
    $s_bag = $_POST['s_bag']     ?? [];
    $s_loc = $_POST['s_loc']     ?? [];

    // serviços auxiliares
    $aux_code    = $_POST['aux_code']    ?? [];
    $aux_service = $_POST['aux_service'] ?? [];
    $aux_value   = $_POST['aux_value']   ?? [];

    // novo: valor pago no serviço
    $service_paid = num($_POST['service_paid'] ?? ($inv['service_paid'] ?? 0));

    // 2) validação mínima
    if ($client_id <= 0) {
      $err = 'Selecione um cliente.';
    } else {
      $vc = $pdo->prepare("SELECT id FROM clients WHERE id=? AND agency_id=? LIMIT 1");
      $vc->execute([$client_id, $invoiceAgencyId]);
      if (!$vc->fetch()) $err = 'Cliente inválido.';
    }

    if (!$err && $supplier_id !== null && function_exists('has_table') && has_table($pdo, 'suppliers')) {
      $vs = $pdo->prepare("SELECT id FROM suppliers WHERE id=? AND agency_id=? LIMIT 1");
      $vs->execute([$supplier_id, $invoiceAgencyId]);
      if (!$vs->fetch()) $err = 'Fornecedor inválido.';
    }

    // 3) totals passageiros
    $passengers_total = 0.0; $cleanPassengers = [];
    if (!$err) {
      $n = max(count($p_names), count($p_types), count($p_tickets), count($p_values));
      for ($i=0; $i<$n; $i++) {
        $nm = to_upper($p_names[$i] ?? '');
        if ($nm === '') continue;
        $tpRaw = $p_types[$i] ?? 'ADT';
        $tp = ($tpRaw === 'CHD') ? 'CHD' : (($tpRaw === 'INF') ? 'INF' : 'ADT');
        $tk = trim($p_tickets[$i] ?? '');
        $vv = num($p_values[$i] ?? 0);
        $passengers_total += $vv;
        $cleanPassengers[] = [$nm,$tp,$tk,$vv];
      }
    }

    // 3-b) total serviços auxiliares
    $aux_total = 0.0;
    $nA = max(count($aux_code), count($aux_service), count($aux_value));
    for ($i=0; $i<$nA; $i++){
      $sv = trim($aux_service[$i] ?? '');
      $vv = num($aux_value[$i] ?? 0);
      if ($sv === '' && $vv==0) continue;
      $aux_total += $vv;
    }

    // 3-c) totais financeiros
    $total_amount = $passengers_total + $aux_total;      // Total do Cliente
    $supplier_paid_amount = $supplier_paid ? $supplier_liquid : 0.0;
    $total_paid   = $supplier_paid_amount + $service_paid;    // Total Pago
    $margin_value = $total_amount - $total_paid;              // Margem/Lucro
    $amount_paid  = $inv['amount_paid'] ?? 0;

    // 4) salvar em transação
    if (!$err) {
      try {
        $pdo->beginTransaction();

        // update invoice
        $up = $pdo->prepare("UPDATE invoices SET
          client_id=?, supplier_id=?, issue_date=?, status=?, currency=?, pnr_code=?, travel_date=?, scope=?,
          passengers_total=?, supplier_tarifa=?, supplier_comissao=?, supplier_liquid=?, supplier_paid=?,
          service_paid=?, total_paid=?, margin_value=?, amount_paid=?, refund_rule=?, change_rule=?, updated_at=NOW(), total_amount=?
          WHERE id=? AND agency_id=?");
        $up->execute([
          $client_id, $supplier_id, $issue_date, $status, $currency, $pnr_code, $travel_date, $scope,
          $passengers_total, $tarifa, $comissao, $supplier_liquid, $supplier_paid,
          $service_paid, $total_paid, $margin_value, $amount_paid, $refund_rule, $change_rule, $total_amount,
          $id,
          $invoiceAgencyId
        ]);

        // replace passengers
        $pdo->prepare("DELETE FROM passengers WHERE invoice_id=? AND agency_id=?")->execute([$id, $invoiceAgencyId]);
        if ($cleanPassengers) {
          $ip = $pdo->prepare("INSERT INTO passengers (invoice_id, name, ptype, ticket_no, value, agency_id) VALUES (?,?,?,?,?,?)");
          foreach ($cleanPassengers as [$nm,$tp,$tk,$vv]) {
            $ip->execute([$id, $nm, $tp, to_upper($tk), $vv, $invoiceAgencyId]);
          }
        }

        // replace segments
        $pdo->prepare("DELETE FROM segments WHERE invoice_id=? AND agency_id=?")->execute([$id, $invoiceAgencyId]);
        $nSeg = max(count($s_air),count($s_fno),count($s_org),count($s_dst),count($s_cls),count($s_bag),count($s_loc));
        if ($nSeg > 0) {
          $is = $pdo->prepare("INSERT INTO segments
            (invoice_id, airline_code, flight_no, `origin`, `destination`, `class`, `baggage`, record_locator, agency_id)
            VALUES (?,?,?,?,?,?,?,?,?)");

          $airlines = [];
          if (function_exists('airlines_pairs')) { $airlines = airlines_pairs(); }

          for ($i=0; $i<$nSeg; $i++) {
            $air = trim($s_air[$i] ?? '');
            $fno = to_upper(preg_replace('/\s+/', '', (string)($s_fno[$i] ?? '')));
            $org = str_replace(["\r\n","\r"], "\n", (string)($s_org[$i] ?? ''));
            $dst = str_replace(["\r\n","\r"], "\n", (string)($s_dst[$i] ?? ''));
            $cls = trim($s_cls[$i] ?? '');
            $bag = trim($s_bag[$i] ?? '');
            $loc = to_upper(trim($s_loc[$i] ?? ''));

            if ($fno==='' && $org==='' && $dst==='' && $air==='' && $cls==='' && $bag==='' && $loc==='') continue;
            if ($air === '' && $fno !== '') {
              if (preg_match('/^[A-Z0-9]{2,3}/', $fno, $m)) { $air = $m[0]; }
            }
            if ($fno === '' || trim($org)==='' || trim($dst)==='') continue;

            if ($air !== '') {
              if (strpos($air, '–') !== false) {
                [, $label] = array_map('trim', explode('–', $air, 2));
                $air = $label;
              } else {
                $air = airline_label_from_code($air, $airlines);
              }
            } elseif ($fno !== '' && preg_match('/^([A-Z0-9]{2,3})/', $fno, $m)) {
              $air = airline_label_from_code($m[1], $airlines);
            }

            $org = mb_substr($org, 0, 255, 'UTF-8');
            $dst = mb_substr($dst, 0, 255, 'UTF-8');

            $is->execute([$id, $air, $fno, $org, $dst, $cls, $bag, $loc, $invoiceAgencyId]);
          }
        }

        // replace aux_services
        $pdo->prepare("DELETE FROM aux_services WHERE invoice_id=? AND agency_id=?")->execute([$id, $invoiceAgencyId]);
        if ($nA > 0) {
          $ia = $pdo->prepare("INSERT INTO aux_services (invoice_id, code, service, value, agency_id) VALUES (?,?,?,?,?)");
          for ($i=0; $i<$nA; $i++){
            $cd = trim($aux_code[$i] ?? '');
            $sv = trim($aux_service[$i] ?? '');
            $vv = num($aux_value[$i] ?? 0);
            if ($sv === '' && $vv==0) continue;
            $ia->execute([$id, mb_substr($cd,0,50,'UTF-8'), mb_substr($sv,0,255,'UTF-8'), $vv, $invoiceAgencyId]);
          }
        }

        $pdo->commit();
        header('Location: /sales/show.php?id='.$id); exit;

      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[INVOICES_EDIT] uid='.($_SESSION['uid']??'null').' :: '.$e->getMessage());
        $err = 'Erro ao salvar.';
      }
    }
  }
}

$token = csrf_token();
$pageTitle = 'Editar Venda '.$inv['invoice_number'];
ob_start();
?>
<form method="post" id="invoiceForm" autocomplete="off" class="space-y-5">
  <?php if ($err): ?>
    <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800" role="alert">
      <?= htmlspecialchars($err) ?>
    </div>
  <?php endif; ?>

  <datalist id="clientsNames"></datalist>

  <!-- ===== Dados da Venda ===== -->
  <div class="card overflow-hidden">
    <div class="border-b border-ink-100 px-5 py-4">
      <h3 class="text-sm font-bold text-ink-950">Dados da Venda</h3>
    </div>
    <div class="p-5">
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-12">
        <div class="xl:col-span-3">
          <label class="label-field">Nº Venda</label>
          <input type="text" class="input-field" value="<?= htmlspecialchars($inv['invoice_number']) ?>" readonly>
        </div>
        <div class="xl:col-span-3">
          <label class="label-field">Data de emissão</label>
          <input type="date" class="select-field" name="issue_date" value="<?= htmlspecialchars($inv['issue_date']) ?>" required>
        </div>
        <div class="xl:col-span-3">
          <label class="label-field">Status</label>
          <select class="select-field" name="status" required>
            <?php $opts = ['nao pago' => 'Não pago', 'pago parcial' => 'Pago parcial', 'pago' => 'Pago']; ?>
            <?php foreach ($opts as $val => $lab): ?>
              <option value="<?= $val ?>" <?= ($inv['status'] === $val ? 'selected' : '') ?>><?= $lab ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="xl:col-span-3">
          <label class="label-field">Moeda</label>
          <select class="select-field" name="currency">
            <?php foreach (['BRL', 'USD', 'EUR'] as $cc): ?>
              <option <?= ($inv['currency'] === $cc ? 'selected' : '') ?>><?= $cc ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="xl:col-span-4">
          <label class="label-field">Código de reserva (PNR)</label>
          <input class="input-field" name="pnr_code" value="<?= htmlspecialchars($inv['pnr_code'] ?? '') ?>" placeholder="ABC123">
        </div>
        <div class="xl:col-span-4">
          <label class="label-field">Data de viagem</label>
          <input type="date" class="select-field" name="travel_date" value="<?= htmlspecialchars($inv['travel_date'] ?? '') ?>">
        </div>
        <div class="xl:col-span-4">
          <label class="label-field">Âmbito</label>
          <select class="select-field" name="scope">
            <?php foreach (['nacional' => 'Nacional', 'internacional' => 'Internacional'] as $v => $t): ?>
              <option value="<?= $v ?>" <?= (($inv['scope'] ?? 'nacional') === $v ? 'selected' : '') ?>><?= $t ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
  </div>

  <!-- ===== Cliente & Fornecedor ===== -->
  <div class="card overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-100 px-5 py-4">
      <h3 class="text-sm font-bold text-ink-950">Cliente &amp; Fornecedor</h3>
      <div class="flex flex-wrap items-center gap-2">
        <a class="btn-soft border-brand-200 text-brand-700 hover:bg-brand-50" href="/clients/create.php" target="_blank" rel="noopener">Novo Cliente</a>
        <a class="btn-soft border-brand-200 text-brand-700 hover:bg-brand-50" href="/suppliers/create.php" target="_blank" rel="noopener">Novo Fornecedor</a>
      </div>
    </div>
    <div class="p-5">
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
          <label class="label-field">Cliente *</label>
          <select class="select-field" id="clientSelect" name="client_id" required>
            <option value="">— selecione —</option>
          </select>
        </div>
        <div>
          <label class="label-field">Fornecedor</label>
          <select class="select-field" id="supplierSelect" name="supplier_id">
            <option value="">— nenhum —</option>
          </select>
        </div>
      </div>
    </div>
  </div>

  <!-- ===== Aéreo: Passageiros ===== -->
  <div class="card overflow-hidden">
    <div class="border-b border-ink-100 px-5 py-4">
      <h3 class="text-sm font-bold text-ink-950">Aéreo - Passageiros</h3>
    </div>
    <div class="p-5">
      <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div class="trip-subsection-title">Passageiros</div>
        <button type="button" id="addPassenger" class="btn-soft border-brand-200 text-brand-700 hover:bg-brand-50">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><line x1="20" y1="8" x2="20" y2="14"></line><line x1="23" y1="11" x2="17" y2="11"></line></svg>
          Adicionar passageiro
        </button>
      </div>
      <div class="overflow-x-auto">
        <table class="table-modern min-w-[760px]" id="passengersTable">
          <thead>
            <tr>
              <th>Nome</th>
              <th style="width:120px">Tipo</th>
              <th>Nº bilhete</th>
              <th style="width:160px">Valor</th>
              <th style="width:110px"></th>
            </tr>
          </thead>
          <tbody></tbody>
          <tfoot>
            <tr>
              <th colspan="3" class="border-0 px-3 py-3 text-right text-sm text-ink-500">Total Passageiros:</th>
              <th class="border-0 px-3 py-3 text-right text-sm font-bold text-ink-950"><span id="sumPassengers">R$ 0,00</span></th>
              <th class="border-0"></th>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

  <!-- ===== Aéreo: Segmentos ===== -->
  <div class="card overflow-hidden">
    <div class="border-b border-ink-100 px-5 py-4">
      <h3 class="text-sm font-bold text-ink-950">Aéreo - Segmentos</h3>
    </div>
    <div class="p-5">
      <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div class="trip-subsection-title">Segmentos</div>
        <div class="flex flex-wrap items-center gap-2">
          <button type="button" id="dupSegment" class="btn-soft">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
            Duplicar segmento
          </button>
          <button type="button" id="addSegment" class="btn-soft border-brand-200 text-brand-700 hover:bg-brand-50">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"></path><path d="M5 12h14"></path></svg>
            Adicionar segmento
          </button>
        </div>
      </div>
      <div class="overflow-x-auto">
        <table class="table-modern min-w-[1100px]" id="segmentsTable">
          <thead>
            <tr>
              <th>Cia</th>
              <th>Nº voo</th>
              <th>Origem</th>
              <th>Destino</th>
              <th>Classe</th>
              <th>Bagagem</th>
              <th>Loc Cia</th>
              <th style="width:110px"></th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ===== Serviços adicionais ===== -->
  <div class="card overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-100 px-5 py-4">
      <h3 class="text-sm font-bold text-ink-950">Serviços adicionais</h3>
      <button type="button" id="addAux" class="btn-soft border-brand-200 text-brand-700 hover:bg-brand-50">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"></path><path d="M5 12h14"></path></svg>
        Adicionar serviço
      </button>
    </div>
    <div class="p-5">
      <div class="overflow-x-auto">
        <table class="table-modern min-w-[560px]" id="auxTable">
          <thead>
            <tr>
              <th style="width:140px">Código</th>
              <th>Serviço</th>
              <th style="width:160px">Valor</th>
              <th style="width:110px"></th>
            </tr>
          </thead>
          <tbody></tbody>
          <tfoot>
            <tr>
              <th colspan="2" class="border-0 px-3 py-3 text-right text-sm text-ink-500">Total Serviços:</th>
              <th class="border-0 px-3 py-3 text-right text-sm font-bold text-ink-950"><span id="sumAux">R$ 0,00</span></th>
              <th class="border-0"></th>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

  <!-- ===== Regras ===== -->
  <div class="card overflow-hidden">
    <div class="border-b border-ink-100 px-5 py-4">
      <h3 class="text-sm font-bold text-ink-950">Regras</h3>
    </div>
    <div class="p-5">
      <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div>
          <label class="label-field">Reembolso</label>
          <div class="rule-group">
            <?php $rr = $inv['refund_rule'] ?? 'nao reembolsavel'; ?>
            <label class="rule-radio">
              <input type="radio" name="refund_rule" value="nao reembolsavel" <?= $rr === 'nao reembolsavel' ? 'checked' : '' ?>>
              <span>Não reembolsável</span>
            </label>
            <label class="rule-radio">
              <input type="radio" name="refund_rule" value="multa" <?= $rr === 'multa' ? 'checked' : '' ?>>
              <span>Permitido com multa</span>
            </label>
            <label class="rule-radio">
              <input type="radio" name="refund_rule" value="reembolso total" <?= $rr === 'reembolso total' ? 'checked' : '' ?>>
              <span>Reembolso total</span>
            </label>
          </div>
        </div>
        <div>
          <label class="label-field">Alteração</label>
          <div class="rule-group">
            <?php $cr = $inv['change_rule'] ?? 'nao permite'; ?>
            <label class="rule-radio">
              <input type="radio" name="change_rule" value="nao permite" <?= $cr === 'nao permite' ? 'checked' : '' ?>>
              <span>Não permite alteração</span>
            </label>
            <label class="rule-radio">
              <input type="radio" name="change_rule" value="sem multa" <?= $cr === 'sem multa' ? 'checked' : '' ?>>
              <span>Alteração sem multa (diferença tarifária pode aplicar)</span>
            </label>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- ===== Resumo Financeiro ===== -->
  <div class="card overflow-hidden">
    <div class="border-b border-ink-100 px-5 py-4">
      <h3 class="text-sm font-bold text-ink-950">Resumo Financeiro</h3>
    </div>
    <div class="p-5">
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div>
          <label class="label-field">Tarifa (Fornecedor)</label>
          <input class="input-field" name="supplier_tarifa" id="tarifa" value="<?= number_format((float)$inv['supplier_tarifa'], 2, ',', '.') ?>">
        </div>
        <div>
          <label class="label-field">Comissão (Fornecedor)</label>
          <input class="input-field" name="supplier_comissao" id="comissao" value="<?= number_format((float)$inv['supplier_comissao'], 2, ',', '.') ?>">
        </div>
        <div>
          <label class="label-field">Valor pago ao fornecedor</label>
          <input class="input-field" id="valorFornecedor" value="R$ 0,00" disabled>
        </div>
        <div>
          <label class="label-field">Fornecedor pago?</label>
          <label class="switch-item mt-1">
            <input type="checkbox" name="supplier_paid" id="supplierPaid" <?= !empty($inv['supplier_paid']) ? 'checked' : '' ?>>
            <span class="switch-track"><span class="switch-thumb"></span></span>
            <span class="switch-text">Sim</span>
          </label>
        </div>
      </div>

      <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div>
          <label class="label-field">Valor pago no serviço</label>
          <input class="input-field" name="service_paid" id="servicePaid" value="<?= number_format((float)($inv['service_paid'] ?? 0), 2, ',', '.') ?>">
        </div>
        <div>
          <label class="label-field">Total Pago</label>
          <input class="input-field" id="totalPago" value="R$ 0,00" disabled>
        </div>
        <div>
          <label class="label-field">Total do Cliente</label>
          <input class="input-field" id="totalCliente" value="R$ 0,00" disabled>
        </div>
        <div>
          <label class="label-field">Margem/Lucro</label>
          <input class="input-field" id="margem" value="R$ 0,00" disabled>
        </div>
      </div>

      <input type="hidden" name="passengers_total" id="passengersTotalHidden" value="0.00">
      <input type="hidden" name="total_paid" id="totalPaidHidden" value="<?= number_format((float)($inv['total_paid'] ?? 0), 2, '.', '') ?>">
      <input type="hidden" name="total_amount" id="totalAmountHidden" value="<?= number_format((float)($inv['total_amount'] ?? 0), 2, '.', '') ?>">
    </div>
  </div>

  <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">

  <div class="sticky bottom-4 z-30 rounded-2xl border border-ink-200 bg-white/95 p-3 shadow-lg backdrop-blur">
    <div class="flex flex-wrap items-center justify-end gap-2">
      <button type="button" id="refreshLists" class="btn-ghost">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg>
        Atualizar listas
      </button>
      <a href="/sales/index.php" class="btn-ghost">Cancelar</a>
      <button class="btn-primary px-6" type="submit" id="btnSave">Salvar</button>
    </div>
  </div>
</form>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.css">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>

<script>
function parseMoney(str){ str=(str||'').toString().trim(); if(/,\d{1,2}$/.test(str)){str=str.replace(/\./g,'').replace(',','.');} return parseFloat(str||'0')||0; }
function fmtBRL(n){ return 'R$ ' + (Number(n||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2})); }

document.addEventListener('DOMContentLoaded', function(){
  const form = document.getElementById('invoiceForm');
  const btnSave = document.getElementById('btnSave');
  form.addEventListener('submit', function(){ btnSave.disabled = true; btnSave.innerHTML = 'Salvando…'; });

  const pnr = document.querySelector('input[name="pnr_code"]');
  if (pnr) {
    pnr.addEventListener('input', () => {
      const pos = pnr.selectionStart;
      pnr.value = pnr.value.toUpperCase().replace(/\s+/g,'');
      pnr.setSelectionRange(pos,pos);
    });
  }

  const sumPassengersEl   = document.getElementById('sumPassengers');
  const tarifaEl          = document.getElementById('tarifa');
  const comissaoEl        = document.getElementById('comissao');
  const valorFornecedorEl = document.getElementById('valorFornecedor');
  const supplierPaidEl    = document.getElementById('supplierPaid');
  const servicePaidEl     = document.getElementById('servicePaid');
  const totalPagoEl       = document.getElementById('totalPago');
  const totalClienteEl    = document.getElementById('totalCliente');
  const margemEl          = document.getElementById('margem');
  const hiddenPassTotal   = document.getElementById('passengersTotalHidden');
  const hiddenTotalPaid   = document.getElementById('totalPaidHidden');
  const hiddenTotalAmount = document.getElementById('totalAmountHidden');

  function recalcTotals(){
    let totalPax = 0;
    document.querySelectorAll('.p_value').forEach(inp => { totalPax += parseMoney(inp.value); });
    if (sumPassengersEl) sumPassengersEl.textContent = fmtBRL(totalPax);
    if (hiddenPassTotal) hiddenPassTotal.value = Number(totalPax || 0).toFixed(2);

    let totalAux = 0;
    document.querySelectorAll('.aux_value').forEach(inp => { totalAux += parseMoney(inp.value); });
    const sumAuxEl = document.getElementById('sumAux');
    if (sumAuxEl) sumAuxEl.textContent = fmtBRL(totalAux);

    const totalCliente = totalPax + totalAux;
    if (totalClienteEl) totalClienteEl.value = fmtBRL(totalCliente);
    if (hiddenTotalAmount) hiddenTotalAmount.value = totalCliente.toFixed(2);

    const tarifa = parseMoney(tarifaEl?.value);
    const comis  = parseMoney(comissaoEl?.value);
    const liquid = Math.max(0, tarifa - comis);
    if (valorFornecedorEl) valorFornecedorEl.value = fmtBRL(liquid);

    const servicePaid = parseMoney(servicePaidEl?.value);
    const supplierPaidAmount = supplierPaidEl?.checked ? liquid : 0;
    const totalPago   = supplierPaidAmount + servicePaid;
    if (totalPagoEl) totalPagoEl.value = fmtBRL(totalPago);
    if (hiddenTotalPaid) hiddenTotalPaid.value = totalPago.toFixed(2);

    const margem = totalCliente - totalPago;
    if (margemEl) {
      margemEl.value = fmtBRL(margem);
      if (margem < 0) margemEl.classList.add('is-invalid'); else margemEl.classList.remove('is-invalid');
    }
  }
  [tarifaEl, comissaoEl, servicePaidEl].forEach(el => el && el.addEventListener('input', recalcTotals));
  supplierPaidEl?.addEventListener('change', recalcTotals);

  /* Serviços Auxiliares UI */
  const auxBody = document.querySelector('#auxTable tbody');
  const addAuxBtn = document.getElementById('addAux');
  function addAuxRow(data){
    data = data || { code:'', service:'', value:'' };
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><input name="aux_code[]" class="input-field aux_code" placeholder="COD" value="${data.code||''}"></td>
      <td><input name="aux_service[]" class="input-field aux_service" placeholder="Descrição do serviço" value="${data.service||''}"></td>
      <td><input name="aux_value[]" class="input-field aux_value" placeholder="0,00" value="${data.value||''}"></td>
      <td class="text-right">
        <div class="flex justify-end">
          <button type="button" class="btn-xs btn-soft border-red-200 text-red-700 hover:bg-red-50 delRow">Remover</button>
        </div>
      </td>
    `;
    auxBody.appendChild(tr);
    const v = tr.querySelector('.aux_value');
    v.addEventListener('input', recalcTotals);
    v.addEventListener('paste', ()=> setTimeout(recalcTotals,0));
    tr.querySelector('.delRow').addEventListener('click', ()=>{ tr.remove(); recalcTotals(); });
    recalcTotals();
  }
  if (addAuxBtn) addAuxBtn.addEventListener('click', ()=> addAuxRow());

  /* Passageiros UI */
  const pBody = document.querySelector('#passengersTable tbody');
  document.getElementById('addPassenger').addEventListener('click', ()=> addPassengerRow());
  function addPassengerRow(data){
    data = data || {name:'', type:'ADT', ticket:'', value:''};
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><input name="p_name[]" list="clientsNames" class="input-field p_name" placeholder="NOME COMPLETO (digite ou escolha)" value="${(data.name||'').toString().toUpperCase()}"></td>
      <td>
        <select name="p_type[]" class="select-field p_type">
          <option value="ADT" ${data.type==='ADT'?'selected':''}>ADT</option>
          <option value="CHD" ${data.type==='CHD'?'selected':''}>CHD</option>
          <option value="INF" ${data.type==='INF'?'selected':''}>INF</option>
        </select>
      </td>
      <td><input name="p_ticket[]" class="input-field" placeholder="000-1234567890" value="${data.ticket||''}"></td>
      <td><input name="p_value[]" class="input-field p_value" placeholder="0,00" value="${data.value||''}"></td>
      <td class="text-right">
        <div class="flex justify-end">
          <button type="button" class="btn-xs btn-soft border-red-200 text-red-700 hover:bg-red-50 delRow">Remover</button>
        </div>
      </td>
    `;
    pBody.appendChild(tr);
    const nameInp = tr.querySelector('.p_name');
    nameInp.addEventListener('input', e=>{ const p=e.target.selectionStart; e.target.value=e.target.value.toUpperCase(); e.target.setSelectionRange(p,p); });
    const val = tr.querySelector('.p_value');
    val.addEventListener('input', recalcTotals);
    val.addEventListener('paste', ()=> setTimeout(recalcTotals,0));
    tr.querySelector('.delRow').addEventListener('click', ()=>{ tr.remove(); recalcTotals(); });
    recalcTotals();
  }

  // carregar existentes
  const CURRENT_PAX = <?= json_encode(array_map(function($r){
    return [
      'name'  => (string)$r['name'],
      'type'  => (string)$r['ptype'],
      'ticket'=> (string)$r['ticket_no'],
      'value' => number_format((float)$r['value'],2,',','.')
    ];
  }, $passengers), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  if (CURRENT_PAX.length) { CURRENT_PAX.forEach(p=>addPassengerRow(p)); } else { addPassengerRow(); }

  /* Segmentos UI */
  const sBody = document.querySelector('#segmentsTable tbody');
  const addSegBtn = document.getElementById('addSegment');
  if (addSegBtn) addSegBtn.addEventListener('click', ()=> addSegmentRow());
  const dupSegBtn = document.getElementById('dupSegment');
  if (dupSegBtn) dupSegBtn.addEventListener('click', ()=> duplicateSegment());

  window.K_LISTS = window.K_LISTS || { airlines: [], classes: [], baggage: [] };

  function buildAirlineSelect(val){
    if (!window.K_LISTS.airlines.length) {
      return `<input name="s_airline[]" class="input-field s_airline" placeholder="LA – LATAM" value="${val||''}">`;
    }
    let opts = `<option value=""></option>`;
    window.K_LISTS.airlines.forEach(a=>{
      const v = `${a.code} – ${a.label}`;
      opts += `<option value="${v}" ${v===val?'selected':''}>${v}</option>`;
    });
    return `<select name="s_airline[]" class="select-field s_airline">${opts}</select>`;
  }
  function buildClassSelect(val){
    if (!window.K_LISTS.classes.length) {
      return `<input name="s_class[]" class="input-field s_class" placeholder="Y / J / ..." value="${val||''}">`;
    }
    let opts = `<option value=""></option>`;
    window.K_LISTS.classes.forEach(c=>{ opts += `<option value="${c}" ${c===val?'selected':''}>${c}</option>`; });
    return `<select name="s_class[]" class="select-field s_class">${opts}</select>`;
  }
  function buildBagSelect(val){
    if (!window.K_LISTS.baggage.length) {
      return `<input name="s_bag[]" class="input-field s_bag" placeholder="1PC / 23KG" value="${val||''}">`;
    }
    let opts = `<option value=""></option>`;
    window.K_LISTS.baggage.forEach(b=>{ opts += `<option value="${b}" ${b===val?'selected':''}>${b}</option>`; });
    return `<select name="s_bag[]" class="select-field s_bag">${opts}</select>`;
  }

  function addSegmentRow(data){
    data = data || { airline:'', flight:'', origin:'', dest:'', cls:'', bag:'', loc:'' };
    if (!data.cls) data.cls = (window.K_LISTS.classes?.[0] || 'Y');
    if (!data.bag) data.bag = (window.K_LISTS.baggage?.[0] || '');
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${buildAirlineSelect(data.airline||'')}</td>
      <td><input name="s_flight[]"  class="input-field s_flight" placeholder="LA1234" value="${data.flight||''}"></td>
      <td><textarea name="s_origin[]" class="input-field s_origin" rows="3" placeholder="BSB&#10;Terminal 1">${(data.origin||'')}</textarea></td>
      <td><textarea name="s_dest[]" class="input-field s_dest" rows="3" placeholder="GRU&#10;Terminal 3">${(data.dest||'')}</textarea></td>
      <td>${buildClassSelect(data.cls||'')}</td>
      <td>${buildBagSelect(data.bag||'')}</td>
      <td><input name="s_loc[]" class="input-field s_loc" placeholder="PNR/LOC" value="${data.loc||''}"></td>
      <td class="text-right">
        <div class="flex justify-end">
          <button type="button" class="btn-xs btn-soft border-red-200 text-red-700 hover:bg-red-50 delRow">Remover</button>
        </div>
      </td>
    `;
    sBody.appendChild(tr);
    tr.querySelector('.s_flight')?.focus();

    const f   = tr.querySelector('.s_flight');
    const loc = tr.querySelector('.s_loc');
    const airSelOrInp = () => tr.querySelector('[name="s_airline[]"]');

    if (f) {
      f.addEventListener('input', ()=>{
        f.value = f.value.toUpperCase().replace(/\s+/g,'');
        const m = f.value.match(/^[A-Z0-9]{2}/);
        if (!m) return;
        const code = m[0];
        const el = airSelOrInp();
        if (!el) return;
        if (el.tagName === 'SELECT') {
          const upperCode = code.toUpperCase();
          let matchedOpt = null;
          for (const o of el.options) {
            const v = (o.value || '').toUpperCase().trim();
            const vCode = v.split(/[\s–-]/)[0];
            if (vCode === upperCode) { matchedOpt = o; break; }
          }
          el.value = matchedOpt ? matchedOpt.value : '';
        } else {
          el.value = code;
        }
      });
    }
    if (loc) loc.addEventListener('input', ()=>{ loc.value = loc.value.toUpperCase(); });

    tr.querySelector('.delRow').addEventListener('click', ()=> tr.remove());
  }

  function duplicateSegment(){
    const rows = sBody.querySelectorAll('tr');
    if (!rows.length) { addSegmentRow(); return; }
    const last = rows[rows.length-1];
    addSegmentRow({
      airline: last.querySelector('[name="s_airline[]"]').value,
      flight:  last.querySelector('[name="s_flight[]"]').value,
      origin:  '',
      dest:    '',
      cls:     last.querySelector('[name="s_class[]"]').value,
      bag:     last.querySelector('[name="s_bag[]"]').value,
      loc:     last.querySelector('[name="s_loc[]"]').value
    });
  }

  // dados atuais
  const CURRENT = {
    client_id:   <?= (int)$inv['client_id'] ?>,
    supplier_id: <?= $inv['supplier_id']!==null ? (int)$inv['supplier_id'] : 'null' ?>,
    segments: <?= json_encode(array_map(function($r){
      return [
        'airline' => (string)$r['airline_code'],
        'flight'  => (string)$r['flight_no'],
        'origin'  => (string)$r['origin'],
        'dest'    => (string)$r['destination'],
        'cls'     => (string)$r['class'],
        'bag'     => (string)$r['baggage'],
        'loc'     => (string)$r['record_locator'],
      ];
    }, $segments), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
  };

  if (CURRENT.segments && CURRENT.segments.length) {
    CURRENT.segments.forEach(s => addSegmentRow(s));
  } else {
    addSegmentRow();
  }

  const CURRENT_AUX = <?= json_encode(array_map(function($r){
    return [
      'code'    => (string)$r['code'],
      'service' => (string)$r['service'],
      'value'   => number_format((float)$r['value'],2,',','.')
    ];
  }, $aux_rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
  if (CURRENT_AUX.length) { CURRENT_AUX.forEach(a=>addAuxRow(a)); }

  // validação de segmentos
  form.addEventListener('submit', function(e){
    let invalid = false;
    document.querySelectorAll('#segmentsTable tbody tr').forEach(tr=>{
      const f = tr.querySelector('.s_flight')?.value.trim();
      const o = tr.querySelector('.s_origin')?.value.trim();
      const d = tr.querySelector('.s_dest')?.value.trim();
      const isEmpty = !f && !o && !d;
      if (!isEmpty && (!f || !o || !d)) {
        invalid = true;
        tr.querySelectorAll('.s_flight, .s_origin, .s_dest').forEach(x=>{
          if (!x.value.trim()) x.classList.add('is-invalid'); else x.classList.remove('is-invalid');
        });
      } else {
        tr.querySelectorAll('.s_flight, .s_origin, .s_dest').forEach(x=> x.classList.remove('is-invalid'));
      }
    });
    if (invalid) {
      e.preventDefault();
      alert('أكمل الحقول (Número do voo + Origem + Destino) ou deixe a linha totalmente vazia.');
    }
  });

  // carregar listas
  let tsClient = null, tsSupplier = null;
  const clientSel   = document.getElementById('clientSelect');
  const supplierSel = document.getElementById('supplierSelect');

  async function loadListsAndOptions(preserveSelection = true) {
    try {
      const resOpt = await fetch('/inc/options.php', { cache: 'no-store' });
      if (!resOpt.ok) throw new Error('HTTP ' + resOpt.status);
      const opt = await resOpt.json();

      if (Array.isArray(opt.clients)) {
        clientSel.length = 1;
        for (const o of opt.clients) clientSel.add(new Option(o.text, o.id));
        if (!tsClient) {
          tsClient = new TomSelect(clientSel, { create:false, maxOptions:1000, searchField:'text', plugins:['dropdown_input'], dropdownParent:'body' });
        } else {
          tsClient.clearOptions();
          [...clientSel.options].slice(1).forEach(x => tsClient.addOption({ value: x.value, text: x.text }));
          tsClient.refreshOptions(false);
        }
        if (CURRENT.client_id) tsClient.setValue(String(CURRENT.client_id), true);

        const dl = document.getElementById('clientsNames');
        if (dl) { dl.innerHTML = ''; for (const c of opt.clients) {
          const op = document.createElement('option'); op.value = c.text; dl.appendChild(op);
        } }
      }

      if (Array.isArray(opt.suppliers)) {
        supplierSel.length = 1;
        for (const o of opt.suppliers) supplierSel.add(new Option(o.text, o.id));
        if (opt.suppliers.length > 0) {
          if (!tsSupplier) {
            tsSupplier = new TomSelect(supplierSel, { create:false, maxOptions:1000, searchField:'text', plugins:['dropdown_input'], dropdownParent:'body' });
          } else {
            tsSupplier.clearOptions();
            [...supplierSel.options].slice(1).forEach(x => tsSupplier.addOption({ value: x.value, text: x.text }));
            tsSupplier.refreshOptions(false);
          }
          if (CURRENT.supplier_id) tsSupplier.setValue(String(CURRENT.supplier_id), true);
        } else if (tsSupplier) {
          tsSupplier.destroy();
          tsSupplier = null;
        }
      }

      const resLists = await fetch('/inc/lists.php', { cache: 'no-store' });
      if (resLists.ok) {
        const lists = await resLists.json();
        window.K_LISTS = lists || window.K_LISTS;

        document.querySelectorAll('#segmentsTable tbody tr').forEach(tr=>{
          const curAir = tr.querySelector('[name="s_airline[]"]').value;
          const curCls = tr.querySelector('[name="s_class[]"]').value;
          const curBag = tr.querySelector('[name="s_bag[]"]').value;
          tr.querySelector('[name="s_airline[]"]').outerHTML = buildAirlineSelect(curAir);
          tr.querySelector('[name="s_class[]"]').outerHTML   = buildClassSelect(curCls);
          tr.querySelector('[name="s_bag[]"]').outerHTML     = buildBagSelect(curBag);
        });
      }

      recalcTotals();
    } catch (e) {
      console.error('Falha ao carregar listas/opções', e);
    }
  }
  loadListsAndOptions();
  document.getElementById('refreshLists').addEventListener('click', () => loadListsAndOptions(false));
  document.addEventListener('visibilitychange', () => { if (!document.hidden) loadListsAndOptions(true); });

  recalcTotals();
});
</script>
<?php
$body = ob_get_clean();
require __DIR__ . '/../inc/layout.php';