<?php
// sales/create.php — Nova Venda (com Passageiros & Segmentos + melhorias)
declare(strict_types=1);
ob_start();

require __DIR__ . '/../inc/auth.php'; require_login();
require_once __DIR__ . '/../inc/helpers.php';
require __DIR__ . '/../inc/invoices_lib.php';
require_once __DIR__ . '/../inc/list_store.php';

function to_upper($s){ return mb_strtoupper(trim((string)$s), 'UTF-8'); }
function num($s){
  $s = trim((string)$s);
  if ($s==='') return 0.0;
  $s = str_replace([' ', "\u{00A0}"], '', $s);
  if (preg_match('/,\d{1,2}$/', $s)) { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); }
  return (float)$s;
}

// "LA – LATAM" => pega só o nome; caso contrário mantém valor
function normalize_airline_label(string $air): string{
  $air = trim($air);
  if ($air === '') return '';
  if (strpos($air, '–') !== false) { [, $label] = array_map('trim', explode('–', $air, 2)); return $label ?: $air; }
  if (strpos($air, '-')  !== false) { [, $label] = array_map('trim', explode('-',  $air, 2)); return $label ?: $air; }
  return $air;
}

function normalize_passenger_client_name(string $name): string {
  $name = trim($name);
  if ($name === '') return '';
  if (strpos($name, '–') !== false) {
    [$name] = array_map('trim', explode('–', $name, 2));
  }
  return to_upper($name);
}

function airline_label_from_flight(string $flightNo, string $fallbackAirline): string {
  $flightNo = to_upper(preg_replace('/\s+/', '', $flightNo));
  $fallback = normalize_airline_label($fallbackAirline);
  if ($flightNo === '') return $fallback;

  $airlines = list_store_decode('airlines');
  usort($airlines, static fn(array $a, array $b): int => strlen((string)$b['code']) <=> strlen((string)$a['code']));
  foreach ($airlines as $airline) {
    $code = to_upper((string)($airline['code'] ?? ''));
    if ($code !== '' && str_starts_with($flightNo, $code)) {
      return trim((string)($airline['label'] ?? '')) ?: $fallback;
    }
  }

  return $fallback;
}

function ensure_passenger_clients(PDO $pdo, array $passengers): void {
  $agencyId = agency_id();
  $seen = [];
  $find = $pdo->prepare("SELECT id FROM clients WHERE UPPER(name)=? AND agency_id=? LIMIT 1");
  $insert = $pdo->prepare("
    INSERT INTO clients
      (client_type, name, document, phone, email, birth_date, address, notes, employer_id, gender, agency_id, created_by, created_at, updated_at)
    VALUES
      ('pf', ?, NULL, '', '', NULL, '', NULL, NULL, NULL, ?, ?, NOW(), NOW())
  ");

  foreach ($passengers as $passenger) {
    $name = normalize_passenger_client_name((string)($passenger[0] ?? ''));
    if ($name === '' || isset($seen[$name])) continue;
    $seen[$name] = true;

    $find->execute([$name, $agencyId]);
    if ($find->fetch()) continue;

    $insert->execute([$name, $agencyId, $_SESSION['uid'] ?? null]);
  }
}

if (!function_exists('csrf_token')) {
  function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['_csrf'])) {
      $_SESSION['_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['_csrf'];
  }
}

if (!function_exists('csrf_check')) {
  function csrf_check(?string $token): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    return isset($_SESSION['_csrf']) && is_string($token) && hash_equals($_SESSION['_csrf'], $token);
  }
}

$err = '';
$saleServiceTypes = [
  'hotel' => 'Hotel',
  'car' => 'Aluguel de carro',
  'insurance' => 'Seguro saúde',
  'reception' => 'Recepção',
  'guide' => 'Guia turístico',
  'transfer' => 'Transfer',
  'other' => 'Outro serviço',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $is_draft = !empty($_POST['is_draft']);

  if (!csrf_check($_POST['csrf'] ?? '')) {
    $err = 'Sessão expirada. Recarregue a página e tente novamente.';
  } else {
    // 1) Campos principais
    $client_id   = (int)($_POST['client_id'] ?? 0);
    $trip_numbers = array_values((array)($_POST['trip_no'] ?? []));
    $trip_supplier_ids = array_values((array)($_POST['trip_supplier_id'] ?? []));
    $trip_pnrs = array_values((array)($_POST['trip_pnr'] ?? []));
    $trip_travel_dates = array_values((array)($_POST['trip_travel_date'] ?? []));
    $trip_supplier_tarifas = array_values((array)($_POST['trip_supplier_tarifa'] ?? []));
    $trip_supplier_comissoes = array_values((array)($_POST['trip_supplier_comissao'] ?? []));
    $trip_supplier_paid_nos = array_flip(array_map('strval', (array)($_POST['trip_supplier_paid'] ?? [])));
    $supplier_id = null;
    foreach ($trip_supplier_ids as $sid) {
      if ((int)$sid > 0) { $supplier_id = (int)$sid; break; }
    }
    $issue_date  = ($_POST['issue_date'] ?? '') ?: date('Y-m-d');

    $status_in = strtolower((string)($_POST['status'] ?? 'nao pago'));
    $allowed_status = ['nao pago','pago parcial','pago'];
    $status = in_array($status_in, $allowed_status, true) ? $status_in : 'nao pago';

    $currency    = $_POST['currency'] ?? 'BRL';
    $pnr_code    = to_upper(preg_replace('/\s+/', '', (string)($_POST['pnr_code'] ?? '')));
    $travel_date = ($_POST['travel_date'] ?? '') ?: null;
    $scope       = $_POST['scope'] ?? 'nacional';
    $show_signature = isset($_POST['show_signature']) ? 1 : 0;

    // fornecedor por viagem
    $tarifa = 0.0;
    $comissao = 0.0;
    $supplier_paid_amount = 0.0;
    foreach ($trip_numbers as $idx => $tripNoRaw) {
      $tripTarifa = num($trip_supplier_tarifas[$idx] ?? 0);
      $tripComissao = num($trip_supplier_comissoes[$idx] ?? 0);
      $tripLiquid = max(0, $tripTarifa - $tripComissao);
      $tarifa += $tripTarifa;
      $comissao += $tripComissao;
      if (isset($trip_supplier_paid_nos[(string)max(1, (int)$tripNoRaw)])) {
        $supplier_paid_amount += $tripLiquid;
      }
    }
    $supplier_liquid = max(0, $tarifa - $comissao);
    $supplier_paid   = ($supplier_liquid > 0 && $supplier_paid_amount >= $supplier_liquid) ? 1 : 0;

    // valor pago em serviços não aéreos: calculado pelos serviços marcados como pagos
    $service_paid = 0.0;

    // passageiros
    $p_names   = $_POST['p_name']   ?? [];
    $p_types   = $_POST['p_type']   ?? [];
    $p_tickets = $_POST['p_ticket'] ?? [];
    $p_values  = $_POST['p_value']  ?? [];
    $p_trips   = $_POST['p_trip']   ?? [];

    // segmentos
    $s_air = $_POST['s_airline'] ?? [];
    $s_fno = $_POST['s_flight']  ?? [];
    $s_org = $_POST['s_origin']  ?? [];
    $s_dst = $_POST['s_dest']    ?? [];
    $s_cls = $_POST['s_class']   ?? [];
    $s_bag = $_POST['s_bag']     ?? [];
    $s_loc = $_POST['s_loc']     ?? [];
    $s_trip = $_POST['s_trip']    ?? [];

    // serviços auxiliares
    $aux_type        = $_POST['aux_type']        ?? [];
    $aux_supplier_id = $_POST['aux_supplier_id'] ?? [];
    $aux_code        = $_POST['aux_code']        ?? [];
    $aux_service     = $_POST['aux_service']     ?? [];
    $aux_start_date  = $_POST['aux_start_date']  ?? [];
    $aux_end_date    = $_POST['aux_end_date']    ?? [];
    $aux_value       = $_POST['aux_value']       ?? [];
    $aux_cost        = $_POST['aux_cost']        ?? [];
    $aux_details     = $_POST['aux_details']     ?? [];
    $aux_paid_nos    = array_flip(array_map('strval', (array)($_POST['aux_paid'] ?? [])));

    $hasAuxServiceInput = false;
    $nAuxCheck = max(count($aux_service), count($aux_value), count($aux_cost), count($aux_details));
    for ($i = 0; $i < $nAuxCheck; $i++) {
      if (trim((string)($aux_service[$i] ?? '')) !== '' || num($aux_value[$i] ?? 0) != 0.0 || num($aux_cost[$i] ?? 0) != 0.0 || trim((string)($aux_details[$i] ?? '')) !== '') {
        $hasAuxServiceInput = true;
        break;
      }
    }

    // 2) Validações
    if ($client_id <= 0 && !$is_draft) {
      $err = 'Selecione um cliente.';
    } elseif ($client_id > 0) {
      $vc = $pdo->prepare("SELECT id FROM clients WHERE id=? AND agency_id=? LIMIT 1");
      $vc->execute([$client_id, agency_id()]);
      if (!$vc->fetch()) $err = 'Cliente inválido.';
    }

    if (!$err) {
      $validSupplierIds = [];
      $supplierTableAvailable = function_exists('has_table') && has_table($pdo, 'suppliers');
      if ($supplierTableAvailable) {
        $vs = $pdo->prepare("SELECT id FROM suppliers WHERE id=? AND agency_id=? LIMIT 1");
        foreach ($trip_supplier_ids as $sid) {
          $sid = (int)$sid;
          if ($sid <= 0 || isset($validSupplierIds[$sid])) continue;
          $vs->execute([$sid, agency_id()]);
          if (!$vs->fetch()) { $err = 'Fornecedor inválido.'; break; }
          $validSupplierIds[$sid] = true;
        }
        foreach ($aux_supplier_id as $sid) {
          $sid = (int)$sid;
          if ($sid <= 0 || isset($validSupplierIds[$sid])) continue;
          $vs->execute([$sid, agency_id()]);
          if (!$vs->fetch()) { $err = 'Fornecedor do serviço inválido.'; break; }
          $validSupplierIds[$sid] = true;
        }
      } else {
        foreach (array_merge($trip_supplier_ids, $aux_supplier_id) as $sid) {
          if ((int)$sid > 0) { $err = 'Cadastro de fornecedores indisponível.'; break; }
        }
      }
    }

    $hasTrips = count($trip_numbers) > 0;
    if (!$err && !$is_draft && $hasTrips) {
      $hasPassenger = false;
      for ($i=0; $i<count($p_names); $i++) {
        if (trim($p_names[$i] ?? '') !== '') { $hasPassenger = true; break; }
      }
      if (!$hasPassenger && !$hasAuxServiceInput) $err = 'Adicione pelo menos um passageiro ou serviço.';
    }

    // 3) Totais
    $passengers_total = 0.0;
    $cleanPassengers = [];
    if (!$err) {
      $n = max(count($p_names), count($p_types), count($p_tickets), count($p_values));
      for ($i=0; $i<$n; $i++) {
        $nm = normalize_passenger_client_name((string)($p_names[$i] ?? ''));
        if ($nm === '') { if ($is_draft) continue; else continue; }
        $tpRaw = $p_types[$i] ?? 'ADT';
        $tp = ($tpRaw === 'CHD') ? 'CHD' : (($tpRaw === 'INF') ? 'INF' : 'ADT');
        $tk = trim($p_tickets[$i] ?? '');
        $vv = num($p_values[$i] ?? 0);
        $passengers_total += $vv;
        $tripNo = max(1, (int)($p_trips[$i] ?? 1));
        $cleanPassengers[] = [$nm,$tp,$tk,$vv,$tripNo];
      }
      if (!$cleanPassengers && !$is_draft && !$hasAuxServiceInput) $err = 'Adicione pelo menos um passageiro ou serviço válido.';
    }

    $aux_total = 0.0;
    $cleanAuxServices = [];
    $nA = max(count($aux_type), count($aux_supplier_id), count($aux_code), count($aux_service), count($aux_start_date), count($aux_end_date), count($aux_value), count($aux_cost), count($aux_details));
    for ($i=0; $i<$nA; $i++){
      $tp = (string)($aux_type[$i] ?? 'other');
      if (!isset($saleServiceTypes[$tp])) $tp = 'other';
      $sid = (int)($aux_supplier_id[$i] ?? 0);
      $sv = trim($aux_service[$i] ?? '');
      $vv = num($aux_value[$i] ?? 0);
      $cost = num($aux_cost[$i] ?? 0);
      $rowNo = (string)($i + 1);
      $paid = isset($aux_paid_nos[$rowNo]) ? 1 : 0;
      if ($sv === '' && $vv == 0 && $cost == 0) continue;
      $aux_total += $vv;
      if ($paid) $service_paid += $cost;
      $cleanAuxServices[] = [
        'type' => $tp,
        'supplier_id' => $sid > 0 ? $sid : null,
        'code' => trim((string)($aux_code[$i] ?? '')),
        'service' => $sv,
        'start_date' => trim((string)($aux_start_date[$i] ?? '')) ?: null,
        'end_date' => trim((string)($aux_end_date[$i] ?? '')) ?: null,
        'value' => $vv,
        'cost' => $cost,
        'paid' => $paid,
        'details' => trim((string)($aux_details[$i] ?? '')),
      ];
    }

    // Total do Cliente
    $total_amount = $passengers_total + $aux_total;

    // Total Pago = pago ao fornecedor + pago no serviço
    $total_paid   = $supplier_paid_amount + $service_paid;

    // Margem/Lucro
$margin_value = \Kamaltur\Money::margin((float)$total_amount, (float)$total_paid);

    // 4) Persistência
    if (!$err) {
      try {
        $pdo->beginTransaction();

        ensure_passenger_clients($pdo, $cleanPassengers);

        $invoice_number = next_invoice_number($pdo);
        $refund_rule = $_POST['refund_rule'] ?? 'nao reembolsavel';
        $change_rule = $_POST['change_rule'] ?? 'nao permite';
        $amount_paid  = 0.00; // manter se tiver outro fluxo de recebimentos

        $ins = $pdo->prepare("
          INSERT INTO invoices
          (invoice_number, client_id, supplier_id, issue_date, status, currency, pnr_code, travel_date, scope,
           passengers_total, supplier_tarifa, supplier_comissao, supplier_liquid, supplier_paid,
           service_paid, total_paid, margin_value, amount_paid, refund_rule, change_rule,
           agency_id, created_by, show_signature, created_at, updated_at, total_amount)
          VALUES (?,?,?,?,?,?,?,?,?,
                  ?,?,?,?,?,?,
          ?,?,?,?,?,?,?,?,
                  NOW(),NOW(),?)
        ");

        $ins->execute([
          $invoice_number, ($client_id > 0 ? $client_id : 0), $supplier_id, $issue_date, $status, $currency, $pnr_code, $travel_date, $scope,
          $passengers_total, $tarifa, $comissao, $supplier_liquid, $supplier_paid,
          $service_paid, $total_paid, $margin_value, $amount_paid, $refund_rule, $change_rule,
          agency_id(),
          $_SESSION['uid'] ?? null, $show_signature, $total_amount
        ]);

        $invoice_id = (int)$pdo->lastInsertId();

        $tripMap = [];
        $tripHasTravelDate = function_exists('has_column') && has_column($pdo, 'invoice_trips', 'travel_date');
        $tripHasRules = function_exists('has_column') && has_column($pdo, 'invoice_trips', 'refund_rule') && has_column($pdo, 'invoice_trips', 'change_rule');
        $tripCols = ['invoice_id', 'trip_no', 'pnr_code'];
        if ($tripHasTravelDate) $tripCols[] = 'travel_date';
        array_push($tripCols, 'supplier_id', 'currency', 'supplier_tarifa', 'supplier_comissao', 'supplier_liquid', 'supplier_pay_status', 'supplier_paid_amount');
        if ($tripHasRules) array_push($tripCols, 'refund_rule', 'change_rule');
        array_push($tripCols, 'agency_id');
        $tripInsertSql = "INSERT INTO invoice_trips (`" . implode('`,`', $tripCols) . "`, created_at, updated_at)
             VALUES (" . implode(',', array_fill(0, count($tripCols), '?')) . ", NOW(), NOW())";
        $it = $pdo->prepare($tripInsertSql);
        $postedTripNos = $trip_numbers;
        $tripHasPassenger = [];
        foreach ($cleanPassengers as $_p) {
          $tripHasPassenger[(int)($_p[4] ?? 1)] = true;
        }
        $tripHasSegment = [];
        $nSegCheck = max(count($s_air), count($s_fno), count($s_org), count($s_dst), count($s_cls), count($s_bag), count($s_loc), count($s_trip));
        for ($si = 0; $si < $nSegCheck; $si++) {
          $segTripNo = max(1, (int)($s_trip[$si] ?? 1));
          if (
            trim((string)($s_air[$si] ?? '')) !== '' ||
            trim((string)($s_fno[$si] ?? '')) !== '' ||
            trim((string)($s_org[$si] ?? '')) !== '' ||
            trim((string)($s_dst[$si] ?? '')) !== '' ||
            trim((string)($s_cls[$si] ?? '')) !== '' ||
            trim((string)($s_bag[$si] ?? '')) !== '' ||
            trim((string)($s_loc[$si] ?? '')) !== ''
          ) {
            $tripHasSegment[$segTripNo] = true;
          }
        }
        foreach ($postedTripNos as $idx => $tripNoRaw) {
          $tripNo = max(1, (int)$tripNoRaw);
          if (isset($tripMap[$tripNo])) continue;
          $tripSupplierId = isset($trip_supplier_ids[$idx]) && (int)$trip_supplier_ids[$idx] > 0 ? (int)$trip_supplier_ids[$idx] : null;
          $tripPnr = to_upper(preg_replace('/\s+/', '', (string)($trip_pnrs[$idx] ?? $pnr_code)));
          $tripDate = trim((string)($trip_travel_dates[$idx] ?? '')) ?: $travel_date;
          $tripTarifa = num($trip_supplier_tarifas[$idx] ?? 0);
          $tripComissao = num($trip_supplier_comissoes[$idx] ?? 0);
          $tripLiquid = max(0, $tripTarifa - $tripComissao);
          $hasTripData = $tripSupplierId || $tripPnr !== '' || $tripDate || $tripTarifa != 0.0 || $tripComissao != 0.0 || !empty($tripHasPassenger[$tripNo]) || !empty($tripHasSegment[$tripNo]);
          if (!$hasTripData) continue;
          $tripSupplierPaid = isset($trip_supplier_paid_nos[(string)$tripNo]);
          $tripRefundRule = $_POST['trip_refund_rule_'.$tripNo] ?? $refund_rule;
          if (!in_array($tripRefundRule, ['nao reembolsavel','multa','reembolso total'], true)) $tripRefundRule = $refund_rule;
          $tripChangeRule = $_POST['trip_change_rule_'.$tripNo] ?? $change_rule;
          if (!in_array($tripChangeRule, ['nao permite','sem multa'], true)) $tripChangeRule = $change_rule;

          $tripVals = [$invoice_id, $tripNo, $tripPnr ?: null];
          if ($tripHasTravelDate) $tripVals[] = $tripDate ?: null;
          array_push(
            $tripVals,
            $tripSupplierId,
            $currency,
            $tripTarifa,
            $tripComissao,
            $tripLiquid,
            $tripSupplierPaid ? 'pago' : 'nao pago',
            $tripSupplierPaid ? $tripLiquid : 0
          );
          if ($tripHasRules) array_push($tripVals, $tripRefundRule, $tripChangeRule);
          $tripVals[] = agency_id();
          $it->execute($tripVals);
          $tripMap[$tripNo] = (int)$pdo->lastInsertId();
        }

        // passageiros
        if ($cleanPassengers) {
          $ip = $pdo->prepare("INSERT INTO passengers (invoice_id, name, ptype, ticket_no, value, agency_id) VALUES (?,?,?,?,?,?)");
          foreach ($cleanPassengers as [$nm,$tp,$tk,$vv,$_tripNo]) {
            $ip->execute([$invoice_id, $nm, $tp, to_upper($tk), $vv, agency_id()]);
          }
        }

        // segmentos
        $nSeg = max(count($s_air),count($s_fno),count($s_org),count($s_dst),count($s_cls),count($s_bag),count($s_loc));
        if ($nSeg > 0) {
          $is = $pdo->prepare("
            INSERT INTO segments
            (invoice_id, trip_id, airline_code, flight_no, `origin`, `destination`, `class`, `baggage`, record_locator, agency_id)
            VALUES (?,?,?,?,?,?,?,?,?,?)
          ");
          for ($i=0; $i<$nSeg; $i++) {
            $fno = to_upper(preg_replace('/\s+/', '', (string)($s_fno[$i] ?? '')));
            $air = airline_label_from_flight($fno, (string)($s_air[$i] ?? ''));
            $org = str_replace(["\r\n","\r"], "\n", (string)($s_org[$i] ?? ''));
            $dst = str_replace(["\r\n","\r"], "\n", (string)($s_dst[$i] ?? ''));
            $cls = trim((string)($s_cls[$i] ?? ''));
            $bag = trim((string)($s_bag[$i] ?? ''));
$loc = to_upper(trim((string)($s_loc[$i] ?? '')));
            $tripNo = max(1, (int)($s_trip[$i] ?? 1));

            if ($fno==='' && $org==='' && $dst==='' && $air==='' && $cls==='' && $bag==='' && $loc==='') continue;

            if ($fno === '' || trim($org)==='' || trim($dst)==='') continue;

            $org = mb_substr($org, 0, 255, 'UTF-8');
            $dst = mb_substr($dst, 0, 255, 'UTF-8');

            $is->execute([$invoice_id, $tripMap[$tripNo] ?? null, $air, $fno, $org, $dst, $cls, $bag, $loc, agency_id()]);
          }
        }

        // serviços da venda: hotel, carro, seguro, recepção, guia, transfer e outros
        if ($cleanAuxServices) {
          $auxCols = ['invoice_id'];
          if (has_column($pdo, 'aux_services', 'service_type')) $auxCols[] = 'service_type';
          if (has_column($pdo, 'aux_services', 'supplier_id')) $auxCols[] = 'supplier_id';
          array_push($auxCols, 'code', 'service');
          if (has_column($pdo, 'aux_services', 'start_date')) $auxCols[] = 'start_date';
          if (has_column($pdo, 'aux_services', 'end_date')) $auxCols[] = 'end_date';
          $auxCols[] = 'value';
          if (has_column($pdo, 'aux_services', 'cost_value')) $auxCols[] = 'cost_value';
          if (has_column($pdo, 'aux_services', 'supplier_paid')) $auxCols[] = 'supplier_paid';
          if (has_column($pdo, 'aux_services', 'details')) $auxCols[] = 'details';
          $auxCols[] = 'agency_id';

          $ia = $pdo->prepare("INSERT INTO aux_services (`" . implode('`,`', $auxCols) . "`) VALUES (" . implode(',', array_fill(0, count($auxCols), '?')) . ")");
          foreach ($cleanAuxServices as $aux) {
            $vals = [$invoice_id];
            if (in_array('service_type', $auxCols, true)) $vals[] = $aux['type'];
            if (in_array('supplier_id', $auxCols, true)) $vals[] = $aux['supplier_id'];
            $vals[] = mb_substr($aux['code'], 0, 50, 'UTF-8');
            $vals[] = mb_substr($aux['service'], 0, 255, 'UTF-8');
            if (in_array('start_date', $auxCols, true)) $vals[] = $aux['start_date'];
            if (in_array('end_date', $auxCols, true)) $vals[] = $aux['end_date'];
            $vals[] = $aux['value'];
            if (in_array('cost_value', $auxCols, true)) $vals[] = $aux['cost'];
            if (in_array('supplier_paid', $auxCols, true)) $vals[] = $aux['paid'];
            if (in_array('details', $auxCols, true)) $vals[] = $aux['details'];
            $vals[] = agency_id();
            $ia->execute($vals);
          }
        }

        $pdo->commit();
        header('Location: /sales/show.php?id='.$invoice_id); exit;

      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[INVOICES_CREATE] uid='.($_SESSION['uid']??'null').' :: '.$e->getMessage());
        $err = 'Erro ao salvar.';
      }
    }
  }
}

$token = csrf_token();
$display_invoice_number = '';
$supplierOptionsSeed = [];
try {
  $display_invoice_number = next_invoice_number($pdo);
} catch (Throwable $e) {
  error_log('[INVOICES_CREATE_PREVIEW_NUMBER] uid='.($_SESSION['uid'] ?? 'null').' :: '.$e->getMessage());
}
try {
  if (function_exists('has_table') && has_table($pdo, 'suppliers')) {
    $stSupplierSeed = $pdo->prepare("SELECT id, name, document, supplier_type FROM suppliers WHERE agency_id=? ORDER BY name");
    $stSupplierSeed->execute([agency_id()]);
    $supplierOptionsSeed = array_map(static function(array $s): array {
      return [
        'id' => (int)$s['id'],
        'text' => $s['name'] . ' — ' . $s['document'] . ' (' . (($s['supplier_type'] ?? 'pj') === 'pj' ? 'PJ' : 'PF') . ')',
      ];
    }, $stSupplierSeed->fetchAll(PDO::FETCH_ASSOC) ?: []);
  }
} catch (Throwable $e) {
  error_log('[SALES_CREATE_SUPPLIERS_SEED] uid='.($_SESSION['uid'] ?? 'null').' :: '.$e->getMessage());
}
$pageTitle = 'Nova Venda';
?>
<form method="post" id="invoiceForm" autocomplete="off" class="space-y-5">
  <?php if ($err): ?>
    <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800" role="alert">
      <?= htmlspecialchars($err) ?>
    </div>
  <?php endif; ?>

  <datalist id="clientsNames"></datalist>

  <!-- Dados da Venda -->
  <div class="card overflow-hidden">
    <div class="border-b border-ink-100 px-5 py-4">
      <h3 class="text-sm font-bold text-ink-950">Dados da Venda</h3>
    </div>
    <div class="p-5">
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-12">
        <div class="xl:col-span-2">
          <label class="label-field">Nº Venda</label>
          <input type="text" class="input-field" value="<?= htmlspecialchars($display_invoice_number ?: 'Automático ao salvar') ?>" readonly>
        </div>
        <div class="xl:col-span-4">
          <label class="label-field">Cliente *</label>
          <select class="select-field" id="clientSelect" name="client_id" required>
            <option value="">— selecione —</option>
          </select>
        </div>
        <div class="xl:col-span-3">
          <label class="label-field">Data de emissão</label>
          <input type="date" class="select-field" name="issue_date" value="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="xl:col-span-3">
          <label class="label-field">Status</label>
          <select class="select-field" name="status" id="statusSelect" required>
            <option value="nao pago">Não pago</option>
            <option value="pago parcial">Pago parcial</option>
            <option value="pago">Pago</option>
          </select>
        </div>
        <div class="xl:col-span-2">
          <label class="label-field">Moeda</label>
          <select class="select-field" name="currency">
            <option>BRL</option><option>USD</option><option>EUR</option>
          </select>
        </div>
        <div class="xl:col-span-3">
          <label class="label-field">Código de reserva (PNR)</label>
          <input id="pnr_code" class="input-field" name="pnr_code" placeholder="ABC123">
        </div>
        <div class="xl:col-span-2">
          <label class="label-field">Data de viagem</label>
          <input type="date" class="select-field" name="travel_date">
        </div>
        <div class="xl:col-span-2">
          <label class="label-field">Âmbito</label>
          <select class="select-field" name="scope">
            <option value="nacional">Nacional</option>
            <option value="internacional">internacional</option>
          </select>
        </div>
        <div class="xl:col-span-3">
          <label class="label-field">Assinatura / carimbo</label>
          <label class="switch-item mt-1">
            <input type="checkbox" name="show_signature" value="1" checked>
            <span class="switch-track"><span class="switch-thumb"></span></span>
            <span class="switch-text">Mostrar na impressão</span>
          </label>
        </div>
      </div>

      <hr class="my-5 border-ink-100">

      <h4 class="mb-3 text-sm font-bold text-ink-950">Resumo Financeiro</h4>
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div>
          <label class="label-field">Valor pago no serviço</label>
          <input class="input-field" name="service_paid" id="servicePaid" value="0,00" readonly>
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
      <input type="hidden" name="total_paid" id="totalPaidHidden" value="0.00">
      <input type="hidden" name="total_amount" id="totalAmountHidden" value="0.00">
      <input type="hidden" name="refund_rule" id="refundRuleHidden" value="nao reembolsavel">
      <input type="hidden" name="change_rule" id="changeRuleHidden" value="nao permite">
    </div>
  </div>

  <!-- Aéreo -->
  <div class="card overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-100 px-5 py-4">
      <h3 class="text-sm font-bold text-ink-950">Aéreo</h3>
      <div class="flex flex-wrap items-center gap-2">
        <button type="button" id="dupTrip" class="btn-soft">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="13" height="13" rx="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>
          Duplicar último aéreo
        </button>
        <button type="button" id="addTrip" class="btn-primary">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"></path><path d="M5 12h14"></path></svg>
          Adicionar aéreo
        </button>
      </div>
    </div>
    <div class="p-5">
      <div id="tripsContainer"></div>
    </div>
  </div>

  <!-- Serviços adicionais -->
  <div class="card overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ink-100 px-5 py-4">
      <h3 class="text-sm font-bold text-ink-950">Serviços adicionais</h3>
      <button type="button" id="addAux" class="btn-primary">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"></path><path d="M5 12h14"></path></svg>
        Adicionar serviço
      </button>
    </div>
    <div class="p-5">
      <div id="auxServicesContainer"></div>
      <div class="mt-4 flex justify-end">
        <div class="text-sm font-bold text-ink-950">Total Serviços: <span id="sumAux">R$ 0,00</span></div>
      </div>
    </div>
  </div>

  <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">
  <input type="hidden" name="is_draft" id="is_draft" value="">

  <div class="sticky bottom-4 z-30 rounded-2xl border border-ink-200 bg-white/95 p-3 shadow-lg backdrop-blur">
    <div class="flex flex-wrap items-center justify-end gap-2">
      <button type="button" id="refreshLists" class="btn-ghost">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg>
        Atualizar listas
      </button>
      <a href="/sales/index.php" class="btn-ghost">Cancelar</a>
      <button class="btn-soft" type="button" id="btnDraft">Salvar rascunho</button>
      <button class="btn-primary px-6" type="submit" id="btnSave">Salvar</button>
    </div>
  </div>
</form>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.css">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function(){
	  function parseMoney(str){ str=(str||'').toString().trim(); if(/,\d{1,2}$/.test(str)){str=str.replace(/\./g,'').replace(',','.');} return parseFloat(str||'0')||0; }
	  function fmtBRL(n){ return 'R$ ' + (Number(n||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2})); }
	  function escapeHtml(value){
	    return String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]));
	  }
	  function escapeAttr(value){
	    return escapeHtml(value).replace(/`/g, '&#096;');
	  }

  const form = document.getElementById('invoiceForm');
  const btnSave = document.getElementById('btnSave');
  const btnDraft = document.getElementById('btnDraft');

  form.addEventListener('submit', function(){
    btnSave.disabled = true; btnSave.innerHTML = 'Salvando…';
    btnDraft.disabled = true;
  });

  const pnr = document.querySelector('input[name="pnr_code"]');
  if (pnr) {
    pnr.addEventListener('input', () => {
      const pos = pnr.selectionStart;
      pnr.value = pnr.value.toUpperCase().replace(/\s+/g,'');
      pnr.setSelectionRange(pos,pos);
    });
  }

  const sumPassengersEl   = document.getElementById('sumPassengers');
  const valorFornecedorEl = document.getElementById('valorFornecedor');
  const servicePaidEl     = document.getElementById('servicePaid');
  const totalPagoEl       = document.getElementById('totalPago');
  const totalClienteEl    = document.getElementById('totalCliente');
  const margemEl          = document.getElementById('margem');
  const hiddenPassTotal   = document.getElementById('passengersTotalHidden');
  const hiddenTotalPaid   = document.getElementById('totalPaidHidden');
  const hiddenTotalAmount = document.getElementById('totalAmountHidden');
  const hiddenRefundRule  = document.getElementById('refundRuleHidden');
  const hiddenChangeRule  = document.getElementById('changeRuleHidden');

  function syncInvoiceRulesFromFirstTrip(){
    const first = document.querySelector('.trip-card');
    if (!first) return;
    const refund = first.querySelector('.trip_refund_rule:checked')?.value || 'nao reembolsavel';
    const change = first.querySelector('.trip_change_rule:checked')?.value || 'nao permite';
    if (hiddenRefundRule) hiddenRefundRule.value = refund;
    if (hiddenChangeRule) hiddenChangeRule.value = change;
  }

  window.recalcTotals = function recalcTotals(){
    syncInvoiceRulesFromFirstTrip();
    let totalPax = 0;
    document.querySelectorAll('.p_value').forEach(inp => { totalPax += parseMoney(inp.value); });
    if (sumPassengersEl) sumPassengersEl.textContent = fmtBRL(totalPax);
    if (hiddenPassTotal) hiddenPassTotal.value = Number(totalPax || 0).toFixed(2);

    let totalAux = 0;
    let paidAuxCost = 0;
    document.querySelectorAll('.aux-service-card').forEach(card => {
      totalAux += parseMoney(card.querySelector('.aux_value')?.value);
      if (card.querySelector('.aux_paid')?.checked) {
        paidAuxCost += parseMoney(card.querySelector('.aux_cost')?.value);
      }
    });
    const sumAuxEl = document.getElementById('sumAux');
    if (sumAuxEl) sumAuxEl.textContent = fmtBRL(totalAux);

    const totalCliente = totalPax + totalAux;
    if (totalClienteEl) totalClienteEl.value = fmtBRL(totalCliente);
    if (hiddenTotalAmount) hiddenTotalAmount.value = totalCliente.toFixed(2);

    let tarifa = 0;
    let comis = 0;
	    let liquid = 0;
	    let paidSupplierLiquid = 0;
	    document.querySelectorAll('.trip-card').forEach(card => {
	      const tripTarifa = parseMoney(card.querySelector('.trip_supplier_tarifa')?.value);
	      const tripComis = parseMoney(card.querySelector('.trip_supplier_comissao')?.value);
	      const tripLiquid = Math.max(0, tripTarifa - tripComis);
	      tarifa += tripTarifa;
	      comis += tripComis;
	      liquid += tripLiquid;
	      if (card.querySelector('.trip_supplier_paid')?.checked) paidSupplierLiquid += tripLiquid;
	      const out = card.querySelector('.trip_supplier_liquid');
	      if (out) out.value = fmtBRL(tripLiquid);
	    });
	    if (valorFornecedorEl) valorFornecedorEl.value = fmtBRL(liquid);

	    if (servicePaidEl) servicePaidEl.value = fmtBRL(paidAuxCost).replace('R$ ', '');
	    const servicePaid = paidAuxCost;
	    const totalPago   = paidSupplierLiquid + servicePaid;
    if (totalPagoEl) totalPagoEl.value = fmtBRL(totalPago);
    if (hiddenTotalPaid) hiddenTotalPaid.value = totalPago.toFixed(2);

    const margem = totalCliente - totalPago;
    if (margemEl) {
      margemEl.value = fmtBRL(margem);
      if (margem < 0) margemEl.classList.add('is-invalid'); else margemEl.classList.remove('is-invalid');
    }
  };

  [servicePaidEl].forEach(el => el && el.addEventListener('input', window.recalcTotals));

  const tripsContainer = document.getElementById('tripsContainer');
  const mainPnrInput = document.getElementById('pnr_code');
  const mainTravelInput = document.querySelector('input[name="travel_date"]');
  let tripCounter = 0;
  let clientOptions = [];
  let supplierOptions = <?= json_encode($supplierOptionsSeed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const saleServiceTypes = <?= json_encode($saleServiceTypes, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
  window.SALE_SERVICE_TYPES = saleServiceTypes;
  window.SUPPLIER_OPTIONS = supplierOptions;
  window.K_LISTS = window.K_LISTS || { airlines: [], classes: [], baggage: [] };

	  function buildAirlineSelect(val){
	    const safeVal = escapeAttr(val || '');
	    if (!window.K_LISTS.airlines.length) {
	      return `<input name="s_airline[]" class="input-field s_airline" placeholder="LA – LATAM" value="${safeVal}">`;
	    }
	    let opts = `<option value=""></option>`;
	    window.K_LISTS.airlines.forEach(a=>{
	      const v = `${a.code} – ${a.label}`;
	      opts += `<option value="${escapeAttr(v)}" ${v===val?'selected':''}>${escapeHtml(v)}</option>`;
	    });
	    return `<select name="s_airline[]" class="select-field s_airline">${opts}</select>`;
	  }

  function airlineValue(airline){
    if (!airline) return '';
    return `${airline.code} – ${airline.label}`;
  }

  function airlineForFlight(flightNo){
    const flight = String(flightNo || '').toUpperCase().replace(/\s+/g, '');
    if (!flight || !Array.isArray(window.K_LISTS.airlines)) return null;
    return [...window.K_LISTS.airlines]
      .filter(a => a && a.code)
      .sort((a, b) => String(b.code).length - String(a.code).length)
      .find(a => flight.startsWith(String(a.code).toUpperCase())) || null;
  }

  function setAirlineFromFlight(row){
    const flight = row.querySelector('.s_flight')?.value || '';
    const airline = airlineForFlight(flight);
    if (!airline) return;
    const value = airlineValue(airline);
    const el = row.querySelector('[name="s_airline[]"]');
    if (!el) return;
    el.value = value;
  }
	  function buildClassSelect(val){
	    const safeVal = escapeAttr(val || '');
	    if (!window.K_LISTS.classes.length) {
	      return `<input name="s_class[]" class="input-field s_class" placeholder="Y / J / ..." value="${safeVal}">`;
	    }
	    let opts = `<option value=""></option>`;
	    window.K_LISTS.classes.forEach(c => { opts += `<option value="${escapeAttr(c)}" ${c===val?'selected':''}>${escapeHtml(c)}</option>`; });
	    return `<select name="s_class[]" class="select-field s_class">${opts}</select>`;
	  }
	  function buildBagSelect(val){
	    const safeVal = escapeAttr(val || '');
	    if (!window.K_LISTS.baggage.length) {
	      return `<input name="s_bag[]" class="input-field s_bag" placeholder="1PC / 23KG" value="${safeVal}">`;
	    }
	    let opts = `<option value=""></option>`;
	    window.K_LISTS.baggage.forEach(b => { opts += `<option value="${escapeAttr(b)}" ${b===val?'selected':''}>${escapeHtml(b)}</option>`; });
	    return `<select name="s_bag[]" class="select-field s_bag">${opts}</select>`;
	  }

	  function buildSupplierOptions(value){
	    let opts = `<option value="">— nenhum —</option>`;
	    supplierOptions.forEach(o => { opts += `<option value="${escapeAttr(o.id)}" ${String(o.id)===String(value||'')?'selected':''}>${escapeHtml(o.text)}</option>`; });
	    return opts;
	  }

  function buildSupplierSelect(value){
    const opts = buildSupplierOptions(value);
    return `<select class="select-field trip_supplier" name="trip_supplier_id[]">${opts}</select>`;
  }

	  function initSupplierSelect(sel){
	    if (!sel || sel.tomselect) return sel;
	    if (!supplierOptions.length) return sel;
	    new TomSelect(sel, {
	      create: false,
      maxOptions: 1000,
      searchField: 'text',
      plugins: ['dropdown_input'],
      dropdownParent: 'body'
    });
    return sel;
  }

  function refreshSupplierSelect(sel, value){
    if (!sel) return;
    const old = value ?? (sel.tomselect ? sel.tomselect.getValue() : sel.value);
    if (sel.tomselect) sel.tomselect.destroy();
    sel.innerHTML = buildSupplierOptions(old);
    initSupplierSelect(sel);
    if (old && sel.tomselect) sel.tomselect.setValue(String(old), true);
  }

	  function buildPassengerOptions(value){
	    let opts = value ? `<option value="${escapeAttr(value)}" selected>${escapeHtml(value)}</option>` : `<option value=""></option>`;
	    clientOptions.forEach(o => {
	      const name = (o.name || o.text || '').toString().split('–')[0].trim().toUpperCase();
	      const text = (o.text || name).toString().toUpperCase();
	      if (!name || name === value) return;
	      opts += `<option value="${escapeAttr(name)}">${escapeHtml(text)}</option>`;
	    });
	    return opts;
	  }

  function buildPassengerSelect(value){
    const val = (value || '').toString().toUpperCase();
    return `<select name="p_name[]" class="select-field p_name">${buildPassengerOptions(val)}</select>`;
  }

  function initPassengerSelect(sel){
    if (!sel || sel.tomselect) return sel;
    new TomSelect(sel, {
      create: true,
      maxOptions: 1000,
      searchField: 'text',
      plugins: ['dropdown_input'],
      dropdownParent: 'body',
      createFilter: input => input.trim().length > 0,
      onItemAdd: function(value){
        const upper = String(value || '').toUpperCase();
        if (upper && upper !== value) {
          this.updateOption(value, { value: upper, text: upper });
          this.setValue(upper, true);
        }
      }
    });
    return sel;
  }

  function refreshPassengerSelect(sel, value){
    if (!sel) return;
    const old = (value ?? (sel.tomselect ? sel.tomselect.getValue() : sel.value) ?? '').toString().toUpperCase();
    if (sel.tomselect) sel.tomselect.destroy();
    sel.innerHTML = buildPassengerOptions(old);
    initPassengerSelect(sel);
    if (old && sel.tomselect) sel.tomselect.setValue(old, true);
  }

  function refreshTripEmptyState(){
    let empty = tripsContainer.querySelector('.trip-empty');
    const hasCards = !!tripsContainer.querySelector('.trip-card');
    if (hasCards) {
      empty?.remove();
      return;
    }
    if (!empty) {
	      empty = document.createElement('div');
	      empty.className = 'trip-empty';
	      empty.textContent = 'Nenhum aéreo adicionado.';
	      tripsContainer.appendChild(empty);
	    }
	  }

  function refreshTripNumbers(){
    tripsContainer.querySelectorAll('.trip-card').forEach((card, idx) => {
	      const tripNo = idx + 1;
	      card.dataset.tripNo = String(tripNo);
	      card.querySelector('.trip-title').textContent = `Aéreo ${tripNo}`;
	      card.querySelector('.trip-no-input').value = String(tripNo);
      card.querySelector('.trip_supplier_paid')?.setAttribute('value', String(tripNo));
      card.querySelectorAll('.trip_refund_rule').forEach(inp => { inp.name = `trip_refund_rule_${tripNo}`; });
      card.querySelectorAll('.trip_change_rule').forEach(inp => { inp.name = `trip_change_rule_${tripNo}`; });
      card.querySelectorAll('.p_trip').forEach(inp => inp.value = String(tripNo));
      card.querySelectorAll('.s_trip').forEach(inp => inp.value = String(tripNo));
    });
    tripCounter = tripsContainer.querySelectorAll('.trip-card').length;
    refreshTripEmptyState();
  }

  function defaultTripData(data){
    const d = data || {};
    if (typeof d.pnr === 'undefined') d.pnr = mainPnrInput?.value || '';
    if (typeof d.travel_date === 'undefined') d.travel_date = mainTravelInput?.value || '';
    return d;
  }

  function syncTripDefaults(source){
    document.querySelectorAll('.trip-card').forEach(card => {
      const pnrInp = card.querySelector('.trip-pnr');
      const dateInp = card.querySelector('.trip-travel-date');
      if (source === 'pnr' && pnrInp && (!pnrInp.value || pnrInp.dataset.defaulted === '1')) {
        pnrInp.value = mainPnrInput?.value || '';
        pnrInp.dataset.defaulted = '1';
      }
      if (source === 'date' && dateInp && (!dateInp.value || dateInp.dataset.defaulted === '1')) {
        dateInp.value = mainTravelInput?.value || '';
        dateInp.dataset.defaulted = '1';
      }
    });
  }

	  function addTrip(data){
	    data = defaultTripData(data);
	    tripsContainer.querySelector('.trip-empty')?.remove();
	    tripCounter++;
	    const tripPnrValue = escapeAttr((data.pnr || '').toString().toUpperCase());
	    const tripDateValue = escapeAttr(data.travel_date || '');
	    const tripTarifaValue = escapeAttr(data.supplier_tarifa || '0,00');
	    const tripComissaoValue = escapeAttr(data.supplier_comissao || '0,00');
	    const card = document.createElement('div');
	    card.className = 'trip-card';
	    card.dataset.tripNo = String(tripCounter);
    card.innerHTML = `
	      <input type="hidden" class="trip-no-input" name="trip_no[]" value="${tripCounter}">
	      <div class="mb-3 flex flex-wrap items-center gap-2">
	        <div class="trip-title">Aéreo ${tripCounter}</div>
	        <div class="ml-auto">
	          <button type="button" class="btn-xs btn-soft border-red-200 text-red-700 hover:bg-red-50 removeTrip">Remover aéreo</button>
	        </div>
	      </div>
      <div class="mb-3 grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-12">
        <div class="xl:col-span-3">
          <label class="label-field">Fornecedor</label>
          ${buildSupplierSelect(data.supplier_id || '')}
        </div>
	        <div class="xl:col-span-3">
	          <label class="label-field">Código de reserva (PNR)</label>
	          <input class="input-field trip-pnr" name="trip_pnr[]" placeholder="ABC123" value="${tripPnrValue}">
	        </div>
	        <div class="xl:col-span-3">
	          <label class="label-field">Data de viagem</label>
	          <input type="date" class="select-field trip-travel-date" name="trip_travel_date[]" value="${tripDateValue}">
	        </div>
	        <div class="xl:col-span-3">
	          <label class="label-field">Tarifa (Fornecedor)</label>
	          <input class="input-field trip_supplier_tarifa" name="trip_supplier_tarifa[]" value="${tripTarifaValue}">
	        </div>
	        <div class="xl:col-span-3">
	          <label class="label-field">Comissão (Fornecedor)</label>
	          <input class="input-field trip_supplier_comissao" name="trip_supplier_comissao[]" value="${tripComissaoValue}">
	        </div>
        <div class="xl:col-span-3">
          <label class="label-field">Valor pago ao fornecedor</label>
          <input class="input-field trip_supplier_liquid" value="R$ 0,00" disabled>
        </div>
        <div class="xl:col-span-3">
          <label class="label-field">Fornecedor pago?</label>
          <label class="switch-item mt-1">
            <input class="trip_supplier_paid" type="checkbox" name="trip_supplier_paid[]" value="${tripCounter}" ${data.supplier_paid ? 'checked' : ''}>
            <span class="switch-track"><span class="switch-thumb"></span></span>
            <span class="switch-text">Sim</span>
          </label>
        </div>
      </div>
	      <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
	        <div>
	          <label class="label-field">Reembolso</label>
	          <div class="rule-group">
	            <label class="rule-radio">
	              <input type="radio" name="trip_refund_rule_${tripCounter}" value="nao reembolsavel" class="trip_refund_rule" ${(data.refund_rule || 'nao reembolsavel') === 'nao reembolsavel' ? 'checked' : ''}>
	              <span>Não reembolsável</span>
	            </label>
	            <label class="rule-radio">
	              <input type="radio" name="trip_refund_rule_${tripCounter}" value="multa" class="trip_refund_rule" ${data.refund_rule === 'multa' ? 'checked' : ''}>
	              <span>Permitido com multa</span>
	            </label>
	            <label class="rule-radio">
	              <input type="radio" name="trip_refund_rule_${tripCounter}" value="reembolso total" class="trip_refund_rule" ${data.refund_rule === 'reembolso total' ? 'checked' : ''}>
	              <span>Reembolso total</span>
	            </label>
	          </div>
	        </div>
	        <div>
	          <label class="label-field">Alteração</label>
	          <div class="rule-group">
	            <label class="rule-radio">
	              <input type="radio" name="trip_change_rule_${tripCounter}" value="nao permite" class="trip_change_rule" ${(data.change_rule || 'nao permite') === 'nao permite' ? 'checked' : ''}>
	              <span>Não permite alteração</span>
	            </label>
	            <label class="rule-radio">
	              <input type="radio" name="trip_change_rule_${tripCounter}" value="sem multa" class="trip_change_rule" ${data.change_rule === 'sem multa' ? 'checked' : ''}>
	              <span>Alteração sem multa</span>
	            </label>
	          </div>
	        </div>
	      </div>
	      <div class="trip-subsection">
	        <div class="trip-subsection-title">Passageiros</div>
	        <button type="button" class="btn-xs btn-soft border-brand-200 text-brand-700 hover:bg-brand-50 addPassengerTrip">+ Adicionar passageiro</button>
	      </div>
	      <div class="trip-table-wrap mb-3">
	        <table class="table-modern min-w-[900px] passengersTable">
          <thead>
            <tr>
              <th>Passageiro</th>
              <th style="width:120px">Tipo</th>
              <th>Nº bilhete</th>
              <th style="width:170px">Valor</th>
              <th style="width:110px"></th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
	      <div class="trip-subsection">
	        <div class="trip-subsection-title">Segmentos</div>
	        <div class="flex flex-wrap gap-2">
	          <button type="button" class="btn-xs btn-soft border-brand-200 text-brand-700 hover:bg-brand-50 addSegmentTrip">+ Adicionar segmento</button>
	          <button type="button" class="btn-xs btn-soft dupSegmentTrip">Duplicar segmento</button>
	        </div>
	      </div>
	      <div class="trip-table-wrap">
        <table class="table-modern min-w-[1100px] segmentsTable">
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
    `;
    tripsContainer.appendChild(card);
    initSupplierSelect(card.querySelector('.trip_supplier'));
    const tripPnr = card.querySelector('.trip-pnr');
    const tripDate = card.querySelector('.trip-travel-date');
    if (tripPnr && data.pnr === (mainPnrInput?.value || '')) tripPnr.dataset.defaulted = '1';
    if (tripDate && data.travel_date === (mainTravelInput?.value || '')) tripDate.dataset.defaulted = '1';
	    tripPnr?.addEventListener('input', () => { tripPnr.value = tripPnr.value.toUpperCase().replace(/\s+/g, ''); tripPnr.dataset.defaulted = '0'; });
	    tripDate?.addEventListener('input', () => { tripDate.dataset.defaulted = '0'; });
	    card.querySelectorAll('.trip_supplier_tarifa, .trip_supplier_comissao').forEach(inp => inp.addEventListener('input', window.recalcTotals));
	    card.querySelector('.trip_supplier_paid')?.addEventListener('change', window.recalcTotals);
	    card.querySelectorAll('.trip_refund_rule, .trip_change_rule').forEach(inp => inp.addEventListener('change', syncInvoiceRulesFromFirstTrip));

    card.querySelector('.addPassengerTrip').addEventListener('click', () => addPassengerRow(card));
    card.querySelector('.addSegmentTrip').addEventListener('click', () => addSegmentRow(card));
    card.querySelector('.dupSegmentTrip').addEventListener('click', () => duplicateSegment(card));
    card.querySelector('.removeTrip').addEventListener('click', () => {
      card.remove();
      refreshTripNumbers();
      window.recalcTotals();
    });
    (data.passengers || [{}]).forEach(p => addPassengerRow(card, p));
    (data.segments || [{}]).forEach(s => addSegmentRow(card, s));
    refreshTripNumbers();
    return card;
  }

	  function addPassengerRow(card, data){
	    data = data || {name:'', type:'ADT', ticket:'', value:''};
	    const tr = document.createElement('tr');
	    const tripNo = card.dataset.tripNo || '1';
	    const ticketValue = escapeAttr(data.ticket || '');
	    const valueValue = escapeAttr(data.value || '');
	    tr.innerHTML = `
	      <td><input type="hidden" class="p_trip" name="p_trip[]" value="${tripNo}">${buildPassengerSelect(data.name || '')}</td>
      <td>
        <select name="p_type[]" class="select-field p_type">
          <option value="ADT" ${data.type==='ADT'?'selected':''}>ADT</option>
          <option value="CHD" ${data.type==='CHD'?'selected':''}>CHD</option>
	          <option value="INF" ${data.type==='INF'?'selected':''}>INF</option>
	        </select>
	      </td>
	      <td><input name="p_ticket[]" class="input-field" placeholder="000-1234567890" value="${ticketValue}"></td>
	      <td><input name="p_value[]" class="input-field p_value" placeholder="0,00" value="${valueValue}"></td>
	      <td class="text-right"><button type="button" class="btn-xs btn-soft border-red-200 text-red-700 hover:bg-red-50 delRow">Remover</button></td>
	    `;
    card.querySelector('.passengersTable tbody').appendChild(tr);
    const nameInp = tr.querySelector('.p_name');
    refreshPassengerSelect(nameInp, data.name || '');
    const val = tr.querySelector('.p_value');
    val.addEventListener('input', window.recalcTotals);
    val.addEventListener('paste', ()=> setTimeout(window.recalcTotals,0));
    tr.querySelector('.delRow').addEventListener('click', ()=>{ tr.remove(); window.recalcTotals(); });
    window.recalcTotals();
  }

	  function addSegmentRow(card, data){
	    data = data || { airline:'', flight:'', origin:'', dest:'', cls:'', bag:'', loc:'' };
	    if (!data.cls) data.cls = (window.K_LISTS.classes?.[0] || 'Y');
	    if (!data.bag) data.bag = (window.K_LISTS.baggage?.[0] || '');

	    const tr = document.createElement('tr');
	    const tripNo = card.dataset.tripNo || '1';
	    const flightValue = escapeAttr(data.flight || '');
	    const originValue = escapeHtml(data.origin || '');
	    const destValue = escapeHtml(data.dest || '');
	    const locValue = escapeAttr(data.loc || '');
	    tr.innerHTML = `
	      <td><input type="hidden" class="s_trip" name="s_trip[]" value="${tripNo}">${buildAirlineSelect(data.airline||'')}</td>
	      <td><input name="s_flight[]"  class="input-field s_flight" placeholder="LA1234" value="${flightValue}"></td>
	      <td><textarea name="s_origin[]" class="input-field s_origin" rows="3" placeholder="BSB&#10;Terminal 1">${originValue}</textarea></td>
	      <td><textarea name="s_dest[]" class="input-field s_dest" rows="3" placeholder="GRU&#10;Terminal 3">${destValue}</textarea></td>
	      <td>${buildClassSelect(data.cls||'')}</td>
	      <td>${buildBagSelect(data.bag||'')}</td>
	      <td><input name="s_loc[]" class="input-field s_loc" placeholder="PNR/LOC" value="${locValue}"></td>
      <td class="text-right">
        <button type="button" class="btn-xs btn-soft border-red-200 text-red-700 hover:bg-red-50 delRow">Remover</button>
      </td>
    `;
    card.querySelector('.segmentsTable tbody').appendChild(tr);

	    const f   = tr.querySelector('.s_flight');
	    const loc = tr.querySelector('.s_loc');

    if (f) {
      f.addEventListener('input', ()=>{
        f.value = f.value.toUpperCase().replace(/\s+/g,'');
        const m = f.value.match(/^[A-Z0-9]{2}/);
        if (!m) return;
        setAirlineFromFlight(tr);
      });
    }

    if (loc) loc.addEventListener('input', ()=>{ loc.value = loc.value.toUpperCase(); });

    tr.querySelector('.delRow').addEventListener('click', ()=> tr.remove());
  }

  function duplicateSegment(card){
    const rows = card.querySelectorAll('.segmentsTable tbody tr');
    if (!rows.length) { addSegmentRow(card); return; }
    const last = rows[rows.length-1];
    addSegmentRow(card, {
      airline: last.querySelector('[name="s_airline[]"]').value,
      flight:  last.querySelector('[name="s_flight[]"]').value,
      origin:  '',
      dest:    '',
      cls:     last.querySelector('[name="s_class[]"]').value,
      bag:     last.querySelector('[name="s_bag[]"]').value,
      loc:     last.querySelector('[name="s_loc[]"]').value
    });
  }

  function duplicateTrip(){
    const cards = tripsContainer.querySelectorAll('.trip-card');
    if (!cards.length) { addTrip(); return; }
    const last = cards[cards.length - 1];
    const data = {
      supplier_id: last.querySelector('.trip_supplier')?.value || '',
      pnr: last.querySelector('.trip-pnr')?.value || '',
      travel_date: last.querySelector('.trip-travel-date')?.value || '',
      supplier_tarifa: last.querySelector('.trip_supplier_tarifa')?.value || '0,00',
      supplier_comissao: last.querySelector('.trip_supplier_comissao')?.value || '0,00',
      supplier_paid: last.querySelector('.trip_supplier_paid')?.checked || false,
      refund_rule: last.querySelector('.trip_refund_rule:checked')?.value || 'nao reembolsavel',
      change_rule: last.querySelector('.trip_change_rule:checked')?.value || 'nao permite',
      passengers: [...last.querySelectorAll('.passengersTable tbody tr')].map(tr => ({
        name: tr.querySelector('[name="p_name[]"]').value,
        type: tr.querySelector('[name="p_type[]"]').value,
        ticket: tr.querySelector('[name="p_ticket[]"]').value,
        value: tr.querySelector('[name="p_value[]"]').value,
      })),
      segments: [...last.querySelectorAll('.segmentsTable tbody tr')].map(tr => ({
        airline: tr.querySelector('[name="s_airline[]"]').value,
        flight: tr.querySelector('[name="s_flight[]"]').value,
        origin: tr.querySelector('[name="s_origin[]"]').value,
        dest: tr.querySelector('[name="s_dest[]"]').value,
        cls: tr.querySelector('[name="s_class[]"]').value,
        bag: tr.querySelector('[name="s_bag[]"]').value,
        loc: tr.querySelector('[name="s_loc[]"]').value,
      }))
    };
    addTrip(data);
  }

  const auxServicesContainer = document.getElementById('auxServicesContainer');
  let auxCounter = 0;

	  function buildAuxTypeOptions(value){
	    let opts = '';
	    Object.entries(saleServiceTypes).forEach(([key, label]) => {
	      opts += `<option value="${escapeAttr(key)}" ${key === value ? 'selected' : ''}>${escapeHtml(label)}</option>`;
	    });
	    return opts;
	  }

	  function buildAuxSupplierOptions(value){
	    let opts = `<option value="">— nenhum —</option>`;
	    supplierOptions.forEach(o => {
	      opts += `<option value="${escapeAttr(o.id)}" ${String(o.id) === String(value || '') ? 'selected' : ''}>${escapeHtml(o.text)}</option>`;
	    });
	    return opts;
	  }

	  function initAuxSupplierSelect(sel){
	    if (!sel || sel.tomselect) return;
	    if (!supplierOptions.length) return;
	    new TomSelect(sel, {
      create: false,
      maxOptions: 1000,
      searchField: 'text',
      plugins: ['dropdown_input'],
      dropdownParent: 'body'
    });
  }

  function refreshAuxSupplierSelect(sel, value){
    if (!sel) return;
    const old = value ?? (sel.tomselect ? sel.tomselect.getValue() : sel.value);
    if (sel.tomselect) sel.tomselect.destroy();
    sel.innerHTML = buildAuxSupplierOptions(old);
    initAuxSupplierSelect(sel);
    if (old && sel.tomselect) sel.tomselect.setValue(String(old), true);
  }

  function refreshAuxNumbers(){
    auxServicesContainer.querySelectorAll('.aux-service-card').forEach((card, idx) => {
      const no = idx + 1;
      card.dataset.auxNo = String(no);
      card.querySelector('.aux-service-title').textContent = `Serviço ${no}`;
      const paid = card.querySelector('.aux_paid');
      if (paid) paid.value = String(no);
    });
    auxCounter = auxServicesContainer.querySelectorAll('.aux-service-card').length;
  }

  function applyAuxPlaceholders(card){
    const type = card.querySelector('.aux_type')?.value || 'other';
    const service = card.querySelector('.aux_service');
    const details = card.querySelector('.aux_details');
    const labels = {
      hotel: ['Nome do hotel / hospedagem', 'Quartos, regime, hóspedes, check-in/out...'],
      car: ['Locadora / categoria do carro', 'Retirada, devolução, condutor, franquia...'],
      insurance: ['Seguradora / plano', 'Cobertura, destino, segurados...'],
      reception: ['Recepção', 'Local, horário, responsável...'],
      guide: ['Guia turístico', 'Roteiro, idioma, duração...'],
      transfer: ['Transfer', 'Origem, destino, horário, veículo...'],
      other: ['Descrição do serviço', 'Detalhes adicionais...']
    };
    if (service) service.placeholder = labels[type]?.[0] || labels.other[0];
    if (details) details.placeholder = labels[type]?.[1] || labels.other[1];
  }

	  function addAuxService(data){
	    data = data || { type: 'hotel', supplier_id: '', code: '', service: '', start_date: '', end_date: '', value: '', cost: '', paid: false, details: '' };
	    auxCounter++;
	    const auxCodeValue = escapeAttr(data.code || '');
	    const auxServiceValue = escapeAttr(data.service || '');
	    const auxStartValue = escapeAttr(data.start_date || '');
	    const auxEndValue = escapeAttr(data.end_date || '');
	    const auxValueValue = escapeAttr(data.value || '');
	    const auxCostValue = escapeAttr(data.cost || '');
	    const auxDetailsValue = escapeHtml(data.details || '');
	    const card = document.createElement('div');
    card.className = 'aux-service-card';
    card.dataset.auxNo = String(auxCounter);
    card.innerHTML = `
      <div class="mb-2 flex flex-wrap items-center gap-2">
        <div class="aux-service-title">Serviço ${auxCounter}</div>
        <div class="ml-auto">
          <button type="button" class="btn-xs btn-soft border-red-200 text-red-700 hover:bg-red-50 removeAuxService">Remover serviço</button>
        </div>
      </div>
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-12">
        <div class="xl:col-span-3">
          <label class="label-field">Tipo</label>
          <select class="select-field aux_type" name="aux_type[]">${buildAuxTypeOptions(data.type || 'other')}</select>
        </div>
        <div class="xl:col-span-3">
          <label class="label-field">Fornecedor</label>
          <select class="select-field aux_supplier" name="aux_supplier_id[]">${buildAuxSupplierOptions(data.supplier_id || '')}</select>
        </div>
	        <div class="xl:col-span-3">
	          <label class="label-field">Código / Reserva</label>
	          <input name="aux_code[]" class="input-field aux_code" placeholder="RES / VOUCHER" value="${auxCodeValue}">
	        </div>
	        <div class="xl:col-span-3">
	          <label class="label-field">Serviço</label>
	          <input name="aux_service[]" class="input-field aux_service" value="${auxServiceValue}">
	        </div>
	        <div class="xl:col-span-3">
	          <label class="label-field">Data inicial</label>
	          <input type="date" name="aux_start_date[]" class="select-field" value="${auxStartValue}">
	        </div>
	        <div class="xl:col-span-3">
	          <label class="label-field">Data final</label>
	          <input type="date" name="aux_end_date[]" class="select-field" value="${auxEndValue}">
	        </div>
	        <div class="xl:col-span-2">
	          <label class="label-field">Valor cliente</label>
	          <input name="aux_value[]" class="input-field aux_value" placeholder="0,00" value="${auxValueValue}">
	        </div>
	        <div class="xl:col-span-2">
	          <label class="label-field">Custo fornecedor</label>
	          <input name="aux_cost[]" class="input-field aux_cost" placeholder="0,00" value="${auxCostValue}">
        </div>
        <div class="xl:col-span-2">
          <label class="label-field">Fornecedor pago?</label>
          <label class="switch-item mt-1">
            <input class="aux_paid" type="checkbox" name="aux_paid[]" value="${auxCounter}" ${data.paid ? 'checked' : ''}>
            <span class="switch-track"><span class="switch-thumb"></span></span>
            <span class="switch-text">Sim</span>
          </label>
        </div>
	        <div class="xl:col-span-12">
	          <label class="label-field">Detalhes</label>
	          <textarea name="aux_details[]" class="input-field aux_details" rows="3">${auxDetailsValue}</textarea>
	        </div>
      </div>
    `;
    auxServicesContainer.appendChild(card);
    initAuxSupplierSelect(card.querySelector('.aux_supplier'));
    applyAuxPlaceholders(card);
    card.querySelector('.aux_type')?.addEventListener('change', () => applyAuxPlaceholders(card));
    card.querySelectorAll('.aux_value, .aux_cost').forEach(inp => {
      inp.addEventListener('input', window.recalcTotals);
      inp.addEventListener('paste', () => setTimeout(window.recalcTotals, 0));
    });
    card.querySelector('.aux_paid')?.addEventListener('change', window.recalcTotals);
    card.querySelector('.removeAuxService')?.addEventListener('click', () => {
      card.remove();
      refreshAuxNumbers();
      window.recalcTotals();
    });
    refreshAuxNumbers();
    window.recalcTotals();
  }

  document.getElementById('addTrip').addEventListener('click', () => addTrip());
  document.getElementById('dupTrip').addEventListener('click', duplicateTrip);
	  document.getElementById('addAux')?.addEventListener('click', () => addAuxService());
	  addTrip();
	  mainPnrInput?.addEventListener('input', () => syncTripDefaults('pnr'));
  mainTravelInput?.addEventListener('input', () => syncTripDefaults('date'));

	  form.addEventListener('submit', function(e){
	    let invalid = false;
	    const savingDraft = document.getElementById('is_draft')?.value === '1';
	    if (savingDraft) return;
	    document.querySelectorAll('.segmentsTable tbody tr').forEach(tr=>{
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
      btnSave.disabled = false; btnSave.innerHTML = 'Salvar';
      btnDraft.disabled = false;
      alert('Preencha os campos (Número do voo + Origem + Destino) ou deixe a linha totalmente vazia.');
    }
  });

  // carregar listas
  let tsClient = null;
  const clientSel   = document.getElementById('clientSelect');

  async function loadListsAndOptions(preserveSelection = true) {
    try {
      const resOpt = await fetch('/inc/options.php', { cache: 'no-store' });
      if (!resOpt.ok) throw new Error('HTTP ' + resOpt.status);
      const opt = await resOpt.json();

      if (Array.isArray(opt.clients)) {
        clientOptions = opt.clients;
        const oldVal = tsClient ? tsClient.getValue() : clientSel.value;
        clientSel.length = 1;
        for (const o of opt.clients) clientSel.add(new Option(o.text, o.id));
        if (!tsClient) {
	          tsClient = new TomSelect(clientSel, { create:false, maxOptions:1000, searchField:'text', plugins:['dropdown_input'], dropdownParent:'body' });
	          tsClient.on('change', function(v){
            const client = clientOptions.find(o => String(o.id) === String(v));
	            const txt = ((client?.name || tsClient.getItem(v)?.textContent || '').toString().split('–')[0].trim()).toUpperCase();
	            const firstP = document.querySelector('select[name="p_name[]"]');
	            const firstVal = firstP?.tomselect ? firstP.tomselect.getValue() : firstP?.value;
            if (firstP && !String(firstVal || '').trim()) {
              if (firstP.tomselect) {
                if (!firstP.tomselect.options[txt]) firstP.tomselect.addOption({ value: txt, text: txt });
                firstP.tomselect.setValue(txt, true);
              } else {
                firstP.value = txt;
              }
            }
          });
        } else {
          tsClient.clearOptions();
          [...clientSel.options].slice(1).forEach(op => tsClient.addOption({ value: op.value, text: op.text }));
          tsClient.refreshOptions(false);
        }
        if (preserveSelection && oldVal) tsClient.setValue(oldVal, true);

        const dl = document.getElementById('clientsNames');
        if (dl) { dl.innerHTML = ''; for (const c of opt.clients) {
          const op = document.createElement('option'); op.value = c.text; dl.appendChild(op);
        } }
        document.querySelectorAll('select.p_name').forEach(sel => refreshPassengerSelect(sel));
      }

      if (Array.isArray(opt.suppliers)) {
        supplierOptions = opt.suppliers;
        window.SUPPLIER_OPTIONS = supplierOptions;
        document.querySelectorAll('.trip_supplier').forEach(sel => refreshSupplierSelect(sel, preserveSelection ? undefined : ''));
        document.querySelectorAll('.aux_supplier').forEach(sel => refreshAuxSupplierSelect(sel, preserveSelection ? undefined : ''));
      }

      const resLists = await fetch('/inc/lists.php', { cache: 'no-store' });
      if (resLists.ok) {
        const lists = await resLists.json();
        window.K_LISTS = lists || window.K_LISTS;

        document.querySelectorAll('.segmentsTable tbody tr').forEach(tr=>{
          const curAir = tr.querySelector('[name="s_airline[]"]').value;
          tr.querySelector('[name="s_airline[]"]').outerHTML = buildAirlineSelect(curAir);

          const curCls = tr.querySelector('[name="s_class[]"]').value;
          tr.querySelector('[name="s_class[]"]').outerHTML = buildClassSelect(curCls);

          const curBag = tr.querySelector('[name="s_bag[]"]').value;
          tr.querySelector('[name="s_bag[]"]').outerHTML = buildBagSelect(curBag);

          setAirlineFromFlight(tr);
        });
      }
    } catch (e) {
      console.error('Falha ao carregar listas/opções', e);
    }
  }

  loadListsAndOptions();
  document.getElementById('refreshLists').addEventListener('click', () => loadListsAndOptions(false));
  document.addEventListener('visibilitychange', () => { if (!document.hidden) loadListsAndOptions(true); });

  window.recalcTotals();
  form.addEventListener('submit', window.recalcTotals);

	  btnDraft.addEventListener('click', function(){
	    document.getElementById('is_draft').value = '1';
	    form.noValidate = true;
	    form.requestSubmit(btnSave);
	  });
});

</script>
<?php
$body = ob_get_clean();
require __DIR__ . '/../inc/layout.php';