<?php
declare(strict_types=1);

require __DIR__ . '/../inc/auth.php';
require_login();
require __DIR__ . '/../inc/db.php';

header('Location: /sales/create.php');
exit;

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('num')) {
    function num($s): float {
        $s = trim((string)$s);
        if ($s === '') return 0.0;
        $s = str_replace([' ', "\u{00A0}"], '', $s);
        if (preg_match('/,\d{1,2}$/', $s)) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        }
        return (float)$s;
    }
}

$err = '';

$serviceTypes = [
    'hotel'     => 'Hotel',
    'car'       => 'Aluguel de Carro',
    'insurance' => 'Seguro Saúde',
    'reception' => 'Recepção',
    'guide'     => 'Guia Turístico',
    'transfer'  => 'Transfer',
    'other'     => 'Outro Serviço',
];

$old = [
    'service_type'   => isset($_GET['type'], $serviceTypes[$_GET['type']]) ? (string)$_GET['type'] : 'hotel',
    'client_id'      => '',
    'supplier_id'    => '',
    'reference'      => '',
    'status'         => 'pending',
    'currency'       => 'BRL',
    'total_amount'   => '0,00',
    'cost_amount'    => '0,00',
    'notes'          => '',

    'hotel_name'     => '',
    'hotel_address'  => '',
    'stars'          => '',
    'checkin'        => '',
    'checkout'       => '',
    'nights'         => '',
    'rooms'          => '',
    'room_type'      => '',
    'meal'           => '',
    'cancel_policy'  => '',
    'image'          => '',

    'car_company_name'   => '',
    'car_type'           => '',
    'pickup_date'        => '',
    'return_date'        => '',
    'pickup_location'    => '',
    'return_location'    => '',
    'driver_name'        => '',

    'insurance_provider_name'  => '',
    'insurance_plan_name'      => '',
    'insurance_start_date'     => '',
    'insurance_end_date'       => '',
    'insurance_coverage_amount'=> '',
    'insurance_destination'    => '',

    'generic_title'        => '',
    'generic_start_date'   => '',
    'generic_end_date'     => '',
    'generic_location'     => '',
    'generic_participants' => '',
    'generic_details'      => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = array_merge($old, [
        'service_type'   => (string)($_POST['service_type'] ?? 'hotel'),
        'client_id'      => (string)($_POST['client_id'] ?? ''),
        'supplier_id'    => (string)($_POST['supplier_id'] ?? ''),
        'reference'      => trim((string)($_POST['reference'] ?? '')),
        'status'         => trim((string)($_POST['status'] ?? 'pending')),
        'currency'       => trim((string)($_POST['currency'] ?? 'BRL')),
        'total_amount'   => trim((string)($_POST['total_amount'] ?? '0,00')),
        'cost_amount'    => trim((string)($_POST['cost_amount'] ?? '0,00')),
        'notes'          => trim((string)($_POST['notes'] ?? '')),

        'hotel_name'     => trim((string)($_POST['hotel_name'] ?? '')),
        'hotel_address'  => trim((string)($_POST['hotel_address'] ?? '')),
        'stars'          => trim((string)($_POST['stars'] ?? '')),
        'checkin'        => trim((string)($_POST['checkin'] ?? '')),
        'checkout'       => trim((string)($_POST['checkout'] ?? '')),
        'nights'         => trim((string)($_POST['nights'] ?? '')),
        'rooms'          => trim((string)($_POST['rooms'] ?? '')),
        'room_type'      => trim((string)($_POST['room_type'] ?? '')),
        'meal'           => trim((string)($_POST['meal'] ?? '')),
        'cancel_policy'  => trim((string)($_POST['cancel_policy'] ?? '')),
        'image'          => trim((string)($_POST['image'] ?? '')),

        'car_company_name'   => trim((string)($_POST['car_company_name'] ?? '')),
        'car_type'           => trim((string)($_POST['car_type'] ?? '')),
        'pickup_date'        => trim((string)($_POST['pickup_date'] ?? '')),
        'return_date'        => trim((string)($_POST['return_date'] ?? '')),
        'pickup_location'    => trim((string)($_POST['pickup_location'] ?? '')),
        'return_location'    => trim((string)($_POST['return_location'] ?? '')),
        'driver_name'        => trim((string)($_POST['driver_name'] ?? '')),

        'insurance_provider_name'   => trim((string)($_POST['insurance_provider_name'] ?? '')),
        'insurance_plan_name'       => trim((string)($_POST['insurance_plan_name'] ?? '')),
        'insurance_start_date'      => trim((string)($_POST['insurance_start_date'] ?? '')),
        'insurance_end_date'        => trim((string)($_POST['insurance_end_date'] ?? '')),
        'insurance_coverage_amount' => trim((string)($_POST['insurance_coverage_amount'] ?? '')),
        'insurance_destination'     => trim((string)($_POST['insurance_destination'] ?? '')),

        'generic_title'        => trim((string)($_POST['generic_title'] ?? '')),
        'generic_start_date'   => trim((string)($_POST['generic_start_date'] ?? '')),
        'generic_end_date'     => trim((string)($_POST['generic_end_date'] ?? '')),
        'generic_location'     => trim((string)($_POST['generic_location'] ?? '')),
        'generic_participants' => trim((string)($_POST['generic_participants'] ?? '')),
        'generic_details'      => trim((string)($_POST['generic_details'] ?? '')),
    ]);

    if (!csrf_check($_POST['csrf'] ?? null)) {
        $err = 'Sessão expirada. Recarregue a página e tente novamente.';
    } else {
        $service_type = (string)($_POST['service_type'] ?? 'hotel');
        if (!isset($serviceTypes[$service_type])) {
            $service_type = 'hotel';
        }

        $client_id    = (int)($_POST['client_id'] ?? 0);
        $supplier_raw = trim((string)($_POST['supplier_id'] ?? ''));
        $supplier_id  = $supplier_raw !== '' ? (int)$supplier_raw : null;

        $reference    = trim((string)($_POST['reference'] ?? ''));
        $status_in    = strtolower(trim((string)($_POST['status'] ?? 'pending')));
        $currency     = strtoupper(trim((string)($_POST['currency'] ?? 'BRL')));
        $total_amount = num($_POST['total_amount'] ?? '0');
        $cost_amount  = num($_POST['cost_amount'] ?? '0');
        $notes        = trim((string)($_POST['notes'] ?? ''));

        $hotel_name    = trim((string)($_POST['hotel_name'] ?? ''));
        $hotel_address = trim((string)($_POST['hotel_address'] ?? ''));
        $stars         = (int)($_POST['stars'] ?? 0);
        $checkin       = trim((string)($_POST['checkin'] ?? '')) ?: null;
        $checkout      = trim((string)($_POST['checkout'] ?? '')) ?: null;
        $nights        = (int)($_POST['nights'] ?? 0);
        $rooms_count   = (int)($_POST['rooms'] ?? 0);
        $room_type     = trim((string)($_POST['room_type'] ?? ''));
        $meal          = trim((string)($_POST['meal'] ?? ''));
        $cancel_policy = trim((string)($_POST['cancel_policy'] ?? ''));
        $image         = trim((string)($_POST['image'] ?? ''));

        $car_company_name = trim((string)($_POST['car_company_name'] ?? ''));
        $car_type = trim((string)($_POST['car_type'] ?? ''));
        $pickup_date = trim((string)($_POST['pickup_date'] ?? '')) ?: null;
        $return_date = trim((string)($_POST['return_date'] ?? '')) ?: null;
        $pickup_location = trim((string)($_POST['pickup_location'] ?? ''));
        $return_location = trim((string)($_POST['return_location'] ?? ''));
        $driver_name = trim((string)($_POST['driver_name'] ?? ''));

        $insurance_provider_name = trim((string)($_POST['insurance_provider_name'] ?? ''));
        $insurance_plan_name = trim((string)($_POST['insurance_plan_name'] ?? ''));
        $insurance_start_date = trim((string)($_POST['insurance_start_date'] ?? '')) ?: null;
        $insurance_end_date = trim((string)($_POST['insurance_end_date'] ?? '')) ?: null;
        $insurance_coverage_amount = trim((string)($_POST['insurance_coverage_amount'] ?? ''));
        $insurance_destination = trim((string)($_POST['insurance_destination'] ?? ''));

        $generic_title = trim((string)($_POST['generic_title'] ?? ''));
        $generic_start_date = trim((string)($_POST['generic_start_date'] ?? '')) ?: null;
        $generic_end_date = trim((string)($_POST['generic_end_date'] ?? '')) ?: null;
        $generic_location = trim((string)($_POST['generic_location'] ?? ''));
        $generic_participants = trim((string)($_POST['generic_participants'] ?? ''));
        $generic_details = trim((string)($_POST['generic_details'] ?? ''));

        $allowed_status = ['pending', 'confirmed', 'cancelled', 'refunded'];
        $status = in_array($status_in, $allowed_status, true) ? $status_in : 'pending';

        $guest_names = $_POST['guest_name'] ?? [];
        $guest_types = $_POST['guest_type'] ?? [];

        $room_types      = $_POST['room_list_type'] ?? [];
        $room_guests     = $_POST['room_list_guests'] ?? [];
        $room_meals      = $_POST['room_list_meal'] ?? [];
        $room_beds       = $_POST['room_list_beds'] ?? [];
        $room_quantities = $_POST['room_list_qty'] ?? [];

        if ($client_id <= 0) {
            $err = 'Selecione um cliente.';
        } else {
            $vc = $pdo->prepare("SELECT id FROM clients WHERE id=? AND agency_id=? LIMIT 1");
            $vc->execute([$client_id, agency_id()]);
            if (!$vc->fetch()) $err = 'Cliente inválido.';
        }

        if (!$err && $supplier_id !== null) {
            $vs = $pdo->prepare("SELECT id FROM suppliers WHERE id=? AND agency_id=? LIMIT 1");
            $vs->execute([$supplier_id, agency_id()]);
            if (!$vs->fetch()) $err = 'Fornecedor inválido.';
        }

        if (!$err && $service_type === 'hotel' && $hotel_name === '') {
            $err = 'Informe o nome do hotel.';
        } elseif (!$err && $service_type === 'hotel' && (!$checkin || !$checkout)) {
            $err = 'Informe check-in e check-out.';
        } elseif (!$err && $service_type === 'car' && $car_company_name === '') {
            $err = 'Informe a locadora do carro.';
        } elseif (!$err && $service_type === 'insurance' && $insurance_provider_name === '') {
            $err = 'Informe a seguradora.';
        } elseif (!$err && in_array($service_type, ['reception', 'guide', 'transfer', 'other'], true) && $generic_title === '') {
            $err = 'Informe o nome do serviço.';
        }

        $cleanGuests = [];
        if (!$err) {
            $nG = max(count($guest_names), count($guest_types));
            for ($i = 0; $i < $nG; $i++) {
                $gName = trim((string)($guest_names[$i] ?? ''));
                $gType = trim((string)($guest_types[$i] ?? ''));
                if ($gName === '') continue;
                $cleanGuests[] = [
                    'name' => $gName,
                    'type' => $gType,
                ];
            }

            if ($service_type === 'hotel' && !$cleanGuests) {
                $err = 'Adicione pelo menos um hóspede.';
            }
        }

        $cleanRooms = [];
        if (!$err) {
            $nR = max(
                count($room_types),
                count($room_guests),
                count($room_meals),
                count($room_beds),
                count($room_quantities)
            );

            for ($i = 0; $i < $nR; $i++) {
                $rType = trim((string)($room_types[$i] ?? ''));
                $rGuests = trim((string)($room_guests[$i] ?? ''));
                $rMeal = trim((string)($room_meals[$i] ?? ''));
                $rBeds = trim((string)($room_beds[$i] ?? ''));
                $rQty = (int)($room_quantities[$i] ?? 1);

                if ($rType === '' && $rGuests === '' && $rMeal === '' && $rBeds === '' && $rQty <= 0) {
                    continue;
                }

                if ($rType === '') {
                    continue;
                }

                $cleanRooms[] = [
                    'room_type' => $rType,
                    'guests'    => $rGuests,
                    'meal'      => $rMeal,
                    'beds'      => $rBeds,
                    'quantity'  => max(1, $rQty),
                ];
            }

            if (!$cleanRooms && $room_type !== '') {
                $cleanRooms[] = [
                    'room_type' => $room_type,
                    'guests'    => '',
                    'meal'      => $meal,
                    'beds'      => '',
                    'quantity'  => max(1, $rooms_count ?: 1),
                ];
            }
        }

        if (!$err) {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    INSERT INTO service_sales
                    (agency_id, client_id, supplier_id, created_by, service_type, reference, total_amount, cost_amount, currency, status, notes, created_at, updated_at)
                    VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");

                $agency_id  = agency_id();
                $created_by = $_SESSION['uid'] ?? null;

                $stmt->execute([
                    $agency_id,
                    $client_id,
                    $supplier_id,
                    $created_by,
                    $service_type,
                    $reference,
                    $total_amount,
                    $cost_amount,
                    $currency,
                    $status,
                    $notes
                ]);

                $service_id = (int)$pdo->lastInsertId();

                if ($service_type === 'hotel') {
                    $stmt = $pdo->prepare("
                        INSERT INTO service_hotels
                        (service_id, hotel_name, hotel_address, stars, checkin, checkout, nights, rooms, room_type, meal, cancel_policy, image, agency_id)
                        VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        $service_id,
                        $hotel_name,
                        $hotel_address,
                        $stars,
                        $checkin,
                        $checkout,
                        $nights,
                        $rooms_count,
                        $room_type,
                        $meal,
                        $cancel_policy,
                        $image,
                        agency_id()
                    ]);
                } elseif ($service_type === 'car') {
                    $stmt = $pdo->prepare("
                        INSERT INTO service_cars
                        (service_id, company_name, car_type, pickup_date, return_date, pickup_location, return_location, driver_name, agency_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $service_id,
                        $car_company_name,
                        $car_type,
                        $pickup_date,
                        $return_date,
                        $pickup_location,
                        $return_location,
                        $driver_name,
                        agency_id()
                    ]);
                } elseif ($service_type === 'insurance') {
                    $stmt = $pdo->prepare("
                        INSERT INTO service_insurance
                        (service_id, provider_name, plan_name, start_date, end_date, coverage_amount, destination, agency_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $service_id,
                        $insurance_provider_name,
                        $insurance_plan_name,
                        $insurance_start_date,
                        $insurance_end_date,
                        $insurance_coverage_amount,
                        $insurance_destination,
                        agency_id()
                    ]);
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO service_details
                        (service_id, title, start_date, end_date, location, participants, details, agency_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $service_id,
                        $generic_title,
                        $generic_start_date,
                        $generic_end_date,
                        $generic_location,
                        $generic_participants,
                        $generic_details,
                        agency_id()
                    ]);
                }

                if ($cleanGuests) {
                    $stmtGuest = $pdo->prepare("
                        INSERT INTO service_guests (service_id, full_name, type, agency_id)
                        VALUES (?, ?, ?, ?)
                    ");
                    foreach ($cleanGuests as $g) {
                        $stmtGuest->execute([
                            $service_id,
                            $g['name'],
                            $g['type'],
                            agency_id()
                        ]);
                    }
                }

                if ($service_type === 'hotel' && $cleanRooms) {
                    $stmtRoom = $pdo->prepare("
                        INSERT INTO service_hotel_rooms (service_id, room_type, guests, meal, beds, quantity, agency_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    foreach ($cleanRooms as $r) {
                        $stmtRoom->execute([
                            $service_id,
                            $r['room_type'],
                            $r['guests'],
                            $r['meal'],
                            $r['beds'],
                            $r['quantity'],
                            agency_id()
                        ]);
                    }
                }

                $pdo->commit();
                header('Location: /services/show.php?id=' . $service_id);
                exit;

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('services/create.php: ' . $e->getMessage());
                $err = 'Erro ao salvar.';
            }
        }
    }
}

$clientsStmt = $pdo->prepare("SELECT id, name FROM clients WHERE agency_id=? ORDER BY name ASC");
$clientsStmt->execute([agency_id()]);
$clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);

$suppliersStmt = $pdo->prepare("SELECT id, name FROM suppliers WHERE agency_id=? ORDER BY name ASC");
$suppliersStmt->execute([agency_id()]);
$suppliers = $suppliersStmt->fetchAll(PDO::FETCH_ASSOC);

$token = csrf_token();
$pageTitle = 'Nova Venda de Serviço';
ob_start();
$tblInp = 'w-full rounded-lg border border-ink-200 bg-white px-2.5 py-1.5 text-sm text-ink-900 outline-none transition focus:border-brand-400 focus:ring-2 focus:ring-brand-500/10';
$tblSel = 'w-full rounded-lg border border-ink-200 bg-white px-2 py-1.5 text-sm text-ink-900 outline-none transition focus:border-brand-400 focus:ring-2 focus:ring-brand-500/10';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.css">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>

<div class="mx-auto max-w-5xl">

  <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
      <p class="text-xs font-bold uppercase tracking-wider text-brand-600">Serviços</p>
      <h2 class="text-xl font-bold text-ink-950">Nova Venda de Serviço</h2>
      <p class="mt-1 text-sm text-ink-500">Hotel, carro, seguro ou serviço avulso — com passageiros e quartos.</p>
    </div>
    <a href="/services/index.php" class="btn-ghost">
      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"></path><path d="M12 19l-7-7 7-7"></path></svg>
      Cancelar
    </a>
  </div>

  <?php if ($err): ?>
    <div class="mb-5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800" role="alert">
      <?= e($err) ?>
    </div>
  <?php endif; ?>

  <form method="post" id="serviceForm" autocomplete="off" class="space-y-5">

    <div class="card overflow-hidden">
      <div class="border-b border-ink-100 px-5 py-4">
        <h3 class="text-sm font-bold text-ink-950">Dados da Venda</h3>
      </div>
      <div class="p-5">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <div>
            <label class="label-field" for="serviceType">Tipo de venda *</label>
            <select class="select-field" id="serviceType" name="service_type" required>
              <?php foreach ($serviceTypes as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $old['service_type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="label-field" for="clientSelect">Cliente *</label>
            <select class="select-field" id="clientSelect" name="client_id" required>
              <option value="">— selecione —</option>
              <?php foreach ($clients as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= ((string)$c['id'] === (string)$old['client_id']) ? 'selected' : '' ?>>
                  <?= e($c['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="label-field" for="supplierSelect">Fornecedor</label>
            <select class="select-field" id="supplierSelect" name="supplier_id">
              <option value="">— nenhum —</option>
              <?php foreach ($suppliers as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= ((string)$s['id'] === (string)$old['supplier_id']) ? 'selected' : '' ?>>
                  <?= e($s['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label class="label-field" for="reference">Referência</label>
            <input type="text" class="input-field" id="reference" name="reference" value="<?= e($old['reference']) ?>" placeholder="Código interno / localizador">
          </div>
          <div>
            <label class="label-field" for="status">Status</label>
            <select class="select-field" id="status" name="status">
              <option value="pending"   <?= $old['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
              <option value="confirmed" <?= $old['status'] === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
              <option value="cancelled" <?= $old['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
              <option value="refunded"  <?= $old['status'] === 'refunded' ? 'selected' : '' ?>>Refunded</option>
            </select>
          </div>
          <div>
            <label class="label-field" for="currency">Moeda</label>
            <select class="select-field" id="currency" name="currency">
              <option value="BRL" <?= $old['currency'] === 'BRL' ? 'selected' : '' ?>>BRL</option>
              <option value="USD" <?= $old['currency'] === 'USD' ? 'selected' : '' ?>>USD</option>
              <option value="EUR" <?= $old['currency'] === 'EUR' ? 'selected' : '' ?>>EUR</option>
            </select>
          </div>
          <div>
            <label class="label-field" for="total_amount">Valor Total</label>
            <input class="input-field" id="total_amount" name="total_amount" value="<?= e($old['total_amount']) ?>" placeholder="0,00">
          </div>
          <div>
            <label class="label-field" for="cost_amount">Custo</label>
            <input class="input-field" id="cost_amount" name="cost_amount" value="<?= e($old['cost_amount']) ?>" placeholder="0,00">
          </div>
          <div class="sm:col-span-2 lg:col-span-3">
            <label class="label-field" for="notes">Observações</label>
            <textarea class="input-field" id="notes" name="notes" rows="3"><?= e($old['notes']) ?></textarea>
          </div>
        </div>
      </div>
    </div>

    <div class="card service-panel overflow-hidden" data-service-panel="hotel">
      <div class="border-b border-ink-100 px-5 py-4">
        <h3 class="text-sm font-bold text-ink-950">Dados do Hotel</h3>
      </div>
      <div class="p-5">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div class="lg:col-span-2">
            <label class="label-field" for="hotel_name">Hotel Name *</label>
            <input type="text" class="input-field" id="hotel_name" name="hotel_name" value="<?= e($old['hotel_name']) ?>" required>
          </div>
          <div>
            <label class="label-field" for="stars">Stars</label>
            <input type="number" class="input-field" id="stars" name="stars" min="0" max="7" value="<?= e($old['stars']) ?>">
          </div>
          <div>
            <label class="label-field" for="checkin">Check-in *</label>
            <input type="date" class="input-field" id="checkin" name="checkin" value="<?= e($old['checkin']) ?>" required>
          </div>
          <div>
            <label class="label-field" for="checkout">Check-out *</label>
            <input type="date" class="input-field" id="checkout" name="checkout" value="<?= e($old['checkout']) ?>" required>
          </div>
          <div>
            <label class="label-field" for="nights">Nights</label>
            <input type="number" class="input-field" id="nights" name="nights" value="<?= e($old['nights']) ?>" min="0">
          </div>
          <div>
            <label class="label-field" for="rooms">Rooms</label>
            <input type="number" class="input-field" id="rooms" name="rooms" value="<?= e($old['rooms']) ?>" min="0">
          </div>
          <div>
            <label class="label-field" for="room_type">Main Room Type</label>
            <input type="text" class="input-field" id="room_type" name="room_type" value="<?= e($old['room_type']) ?>" placeholder="Luxury Room - 1 King Bed">
          </div>
          <div>
            <label class="label-field" for="meal">Meal Plan</label>
            <input type="text" class="input-field" id="meal" name="meal" value="<?= e($old['meal']) ?>" placeholder="Breakfast Included">
          </div>
          <div class="sm:col-span-2 lg:col-span-2">
            <label class="label-field" for="hotel_address">Hotel Address</label>
            <textarea class="input-field" id="hotel_address" name="hotel_address" rows="3"><?= e($old['hotel_address']) ?></textarea>
          </div>
          <div class="sm:col-span-2 lg:col-span-2">
            <label class="label-field" for="image">Image URL</label>
            <input type="text" class="input-field" id="image" name="image" value="<?= e($old['image']) ?>" placeholder="https://...">
          </div>
          <div class="sm:col-span-2 lg:col-span-4">
            <label class="label-field" for="cancel_policy">Cancellation Policy</label>
            <textarea class="input-field" id="cancel_policy" name="cancel_policy" rows="4"><?= e($old['cancel_policy']) ?></textarea>
          </div>
        </div>
      </div>
    </div>

    <div class="card service-panel overflow-hidden" data-service-panel="car">
      <div class="border-b border-ink-100 px-5 py-4">
        <h3 class="text-sm font-bold text-ink-950">Dados do Aluguel de Carro</h3>
      </div>
      <div class="p-5">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div class="lg:col-span-2">
            <label class="label-field" for="car_company_name">Locadora *</label>
            <input type="text" class="input-field" id="car_company_name" name="car_company_name" value="<?= e($old['car_company_name']) ?>" data-required-for="car">
          </div>
          <div class="lg:col-span-2">
            <label class="label-field" for="car_type">Tipo / Categoria do carro</label>
            <input type="text" class="input-field" id="car_type" name="car_type" value="<?= e($old['car_type']) ?>" placeholder="SUV, Econômico, Executivo...">
          </div>
          <div>
            <label class="label-field" for="pickup_date">Retirada</label>
            <input type="date" class="input-field" id="pickup_date" name="pickup_date" value="<?= e($old['pickup_date']) ?>">
          </div>
          <div>
            <label class="label-field" for="return_date">Devolução</label>
            <input type="date" class="input-field" id="return_date" name="return_date" value="<?= e($old['return_date']) ?>">
          </div>
          <div>
            <label class="label-field" for="pickup_location">Local de retirada</label>
            <input type="text" class="input-field" id="pickup_location" name="pickup_location" value="<?= e($old['pickup_location']) ?>">
          </div>
          <div>
            <label class="label-field" for="return_location">Local de devolução</label>
            <input type="text" class="input-field" id="return_location" name="return_location" value="<?= e($old['return_location']) ?>">
          </div>
          <div class="sm:col-span-2 lg:col-span-4">
            <label class="label-field" for="driver_name">Condutor / Observações</label>
            <textarea class="input-field" id="driver_name" name="driver_name" rows="3"><?= e($old['driver_name']) ?></textarea>
          </div>
        </div>
      </div>
    </div>

    <div class="card service-panel overflow-hidden" data-service-panel="insurance">
      <div class="border-b border-ink-100 px-5 py-4">
        <h3 class="text-sm font-bold text-ink-950">Dados do Seguro Saúde</h3>
      </div>
      <div class="p-5">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div class="lg:col-span-2">
            <label class="label-field" for="insurance_provider_name">Seguradora *</label>
            <input type="text" class="input-field" id="insurance_provider_name" name="insurance_provider_name" value="<?= e($old['insurance_provider_name']) ?>" data-required-for="insurance">
          </div>
          <div class="lg:col-span-2">
            <label class="label-field" for="insurance_plan_name">Plano</label>
            <input type="text" class="input-field" id="insurance_plan_name" name="insurance_plan_name" value="<?= e($old['insurance_plan_name']) ?>">
          </div>
          <div>
            <label class="label-field" for="insurance_start_date">Início da cobertura</label>
            <input type="date" class="input-field" id="insurance_start_date" name="insurance_start_date" value="<?= e($old['insurance_start_date']) ?>">
          </div>
          <div>
            <label class="label-field" for="insurance_end_date">Fim da cobertura</label>
            <input type="date" class="input-field" id="insurance_end_date" name="insurance_end_date" value="<?= e($old['insurance_end_date']) ?>">
          </div>
          <div>
            <label class="label-field" for="insurance_coverage_amount">Cobertura</label>
            <input type="text" class="input-field" id="insurance_coverage_amount" name="insurance_coverage_amount" value="<?= e($old['insurance_coverage_amount']) ?>" placeholder="USD 60.000">
          </div>
          <div>
            <label class="label-field" for="insurance_destination">Destino</label>
            <input type="text" class="input-field" id="insurance_destination" name="insurance_destination" value="<?= e($old['insurance_destination']) ?>">
          </div>
        </div>
      </div>
    </div>

    <div class="card service-panel overflow-hidden" data-service-panel="reception guide transfer other">
      <div class="border-b border-ink-100 px-5 py-4">
        <h3 class="text-sm font-bold text-ink-950">Dados do Serviço</h3>
      </div>
      <div class="p-5">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div class="lg:col-span-2">
            <label class="label-field" for="generic_title">Nome do serviço *</label>
            <input type="text" class="input-field" id="generic_title" name="generic_title" value="<?= e($old['generic_title']) ?>" placeholder="Recepção, guia turístico, transfer..." data-required-for="reception guide transfer other">
          </div>
          <div>
            <label class="label-field" for="generic_start_date">Data inicial</label>
            <input type="date" class="input-field" id="generic_start_date" name="generic_start_date" value="<?= e($old['generic_start_date']) ?>">
          </div>
          <div>
            <label class="label-field" for="generic_end_date">Data final</label>
            <input type="date" class="input-field" id="generic_end_date" name="generic_end_date" value="<?= e($old['generic_end_date']) ?>">
          </div>
          <div>
            <label class="label-field" for="generic_location">Local</label>
            <input type="text" class="input-field" id="generic_location" name="generic_location" value="<?= e($old['generic_location']) ?>">
          </div>
          <div>
            <label class="label-field" for="generic_participants">Participantes</label>
            <input type="text" class="input-field" id="generic_participants" name="generic_participants" value="<?= e($old['generic_participants']) ?>">
          </div>
          <div class="sm:col-span-2 lg:col-span-4">
            <label class="label-field" for="generic_details">Detalhes</label>
            <textarea class="input-field" id="generic_details" name="generic_details" rows="4"><?= e($old['generic_details']) ?></textarea>
          </div>
        </div>
      </div>
    </div>

    <div class="card people-panel overflow-hidden">
      <div class="flex items-center justify-between gap-3 border-b border-ink-100 px-5 py-4">
        <h3 class="text-sm font-bold text-ink-950">Pessoas / Passageiros</h3>
        <button type="button" id="addGuest" class="btn-soft border-brand-200 text-brand-700 hover:bg-brand-50 hover:text-brand-800">
          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
          Adicionar
        </button>
      </div>
      <div class="overflow-x-auto p-5">
        <table class="table-modern" id="guestsTable">
          <thead>
            <tr>
              <th>Nome</th>
              <th style="width:200px">Tipo</th>
              <th style="width:110px"></th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>

    <div class="card service-panel overflow-hidden" data-service-panel="hotel">
      <div class="flex items-center justify-between gap-3 border-b border-ink-100 px-5 py-4">
        <h3 class="text-sm font-bold text-ink-950">Quartos</h3>
        <button type="button" id="addRoom" class="btn-soft border-brand-200 text-brand-700 hover:bg-brand-50 hover:text-brand-800">
          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
          Adicionar
        </button>
      </div>
      <div class="overflow-x-auto p-5">
        <table class="table-modern" id="roomsTable">
          <thead>
            <tr>
              <th>Room Type</th>
              <th>Guests</th>
              <th>Meal</th>
              <th>Beds</th>
              <th style="width:120px">Qty</th>
              <th style="width:110px"></th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>

    <input type="hidden" name="csrf" value="<?= e($token) ?>">

    <div class="flex items-center justify-end gap-3">
      <a href="/services/index.php" class="btn-ghost">Cancelar</a>
      <button class="btn-primary" type="submit" id="btnSave">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
        Salvar
      </button>
    </div>
  </form>

</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
  const serviceType = document.getElementById('serviceType');
  const clientSelect = document.getElementById('clientSelect');
  const supplierSelect = document.getElementById('supplierSelect');
  if (serviceType) new TomSelect(serviceType, { create:false, maxOptions:100, searchField:'text' });
  if (clientSelect) new TomSelect(clientSelect, { create:false, maxOptions:1000, searchField:'text', plugins:['dropdown_input'] });
  if (supplierSelect) new TomSelect(supplierSelect, { create:false, maxOptions:1000, searchField:'text', plugins:['dropdown_input'] });

  function parseMoney(str){
    str = (str || '').toString().trim();
    if (/,\\d{1,2}$/.test(str)) str = str.replace(/\\./g,'').replace(',','.');
    return parseFloat(str || '0') || 0;
  }

  function diffDays(a, b){
    if (!a || !b) return '';
    const d1 = new Date(a + 'T00:00:00');
    const d2 = new Date(b + 'T00:00:00');
    const diff = Math.round((d2 - d1) / (1000 * 60 * 60 * 24));
    return diff >= 0 ? diff : '';
  }

  const checkinEl = document.querySelector('input[name="checkin"]');
  const checkoutEl = document.querySelector('input[name="checkout"]');
  const nightsEl = document.querySelector('input[name="nights"]');

  function recalcNights(){
    if (!checkinEl || !checkoutEl || !nightsEl) return;
    const d = diffDays(checkinEl.value, checkoutEl.value);
    if (d !== '') nightsEl.value = d;
  }

  checkinEl && checkinEl.addEventListener('change', recalcNights);
  checkoutEl && checkoutEl.addEventListener('change', recalcNights);

  const guestsBody = document.querySelector('#guestsTable tbody');
  const roomsBody  = document.querySelector('#roomsTable tbody');

  function panelMatches(panel, type){
    return (panel.dataset.servicePanel || '').split(/\s+/).includes(type);
  }

  function syncServicePanels(){
    const type = serviceType?.value || 'hotel';

    document.querySelectorAll('.service-panel').forEach(panel => {
      const visible = panelMatches(panel, type);
      panel.style.display = visible ? '' : 'none';
      panel.querySelectorAll('input, select, textarea, button').forEach(el => {
        if (el.type === 'button') return;
        el.disabled = !visible;
      });
    });

    document.querySelectorAll('[data-required-for]').forEach(el => {
      const applies = (el.dataset.requiredFor || '').split(/\s+/).includes(type);
      el.required = applies;
    });

    const peopleVisible = ['hotel', 'car', 'insurance'].includes(type);
    document.querySelectorAll('.people-panel').forEach(panel => {
      panel.style.display = peopleVisible ? '' : 'none';
      panel.querySelectorAll('input, select, textarea').forEach(el => {
        el.disabled = !peopleVisible;
      });
    });
  }

  serviceType?.addEventListener('change', syncServicePanels);

  function addGuestRow(data){
    data = data || { name:'', type:'Adult' };
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><input name="guest_name[]" class="<?= $tblInp ?>" placeholder="FULL NAME" value="${(data.name || '').replace(/"/g,'&quot;')}"></td>
      <td>
        <select name="guest_type[]" class="<?= $tblSel ?>">
          <option value="Adult" ${data.type === 'Adult' ? 'selected' : ''}>Adult</option>
          <option value="Child" ${data.type === 'Child' ? 'selected' : ''}>Child</option>
          <option value="Infant" ${data.type === 'Infant' ? 'selected' : ''}>Infant</option>
        </select>
      </td>
      <td class="text-right">
        <button type="button" class="btn-xs btn-soft border-red-200 text-red-700 hover:bg-red-50 hover:text-red-800 delRow">
          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
          Remover
        </button>
      </td>
    `;
    guestsBody.appendChild(tr);
    tr.querySelector('.delRow').addEventListener('click', () => {
      tr.remove();
      ensureGuestRow();
    });
  }

  function ensureGuestRow(){
    if (!guestsBody.querySelector('tr')) addGuestRow();
  }

  function addRoomRow(data){
    data = data || { room_type:'', guests:'', meal:'', beds:'', qty:'1' };
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><input name="room_list_type[]" class="<?= $tblInp ?>" placeholder="Room Type" value="${(data.room_type || '').replace(/"/g,'&quot;')}"></td>
      <td><input name="room_list_guests[]" class="<?= $tblInp ?>" placeholder="2 Adults" value="${(data.guests || '').replace(/"/g,'&quot;')}"></td>
      <td><input name="room_list_meal[]" class="<?= $tblInp ?>" placeholder="Breakfast Included" value="${(data.meal || '').replace(/"/g,'&quot;')}"></td>
      <td><input name="room_list_beds[]" class="<?= $tblInp ?>" placeholder="1 King Bed" value="${(data.beds || '').replace(/"/g,'&quot;')}"></td>
      <td><input name="room_list_qty[]" class="<?= $tblInp ?>" type="number" min="1" value="${(data.qty || '1').toString().replace(/"/g,'&quot;')}"></td>
      <td class="text-right">
        <button type="button" class="btn-xs btn-soft border-red-200 text-red-700 hover:bg-red-50 hover:text-red-800 delRow">
          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
          Remover
        </button>
      </td>
    `;
    roomsBody.appendChild(tr);
    tr.querySelector('.delRow').addEventListener('click', () => {
      tr.remove();
      ensureRoomRow();
    });
  }

  function ensureRoomRow(){
    if (!roomsBody.querySelector('tr')) addRoomRow();
  }

  document.getElementById('addGuest')?.addEventListener('click', () => addGuestRow());
  document.getElementById('addRoom')?.addEventListener('click', () => addRoomRow());

  <?php
    $postedGuestNames = $_POST['guest_name'] ?? [];
    $postedGuestTypes = $_POST['guest_type'] ?? [];
    $postedGuestsJs = [];
    $maxG = max(count($postedGuestNames), count($postedGuestTypes));
    for ($i = 0; $i < $maxG; $i++) {
        $postedGuestsJs[] = [
            'name' => (string)($postedGuestNames[$i] ?? ''),
            'type' => (string)($postedGuestTypes[$i] ?? 'Adult'),
        ];
    }

    $postedRoomTypes = $_POST['room_list_type'] ?? [];
    $postedRoomGuests = $_POST['room_list_guests'] ?? [];
    $postedRoomMeals = $_POST['room_list_meal'] ?? [];
    $postedRoomBeds = $_POST['room_list_beds'] ?? [];
    $postedRoomQtys = $_POST['room_list_qty'] ?? [];
    $postedRoomsJs = [];
    $maxR = max(count($postedRoomTypes), count($postedRoomGuests), count($postedRoomMeals), count($postedRoomBeds), count($postedRoomQtys));
    for ($i = 0; $i < $maxR; $i++) {
        $postedRoomsJs[] = [
            'room_type' => (string)($postedRoomTypes[$i] ?? ''),
            'guests'    => (string)($postedRoomGuests[$i] ?? ''),
            'meal'      => (string)($postedRoomMeals[$i] ?? ''),
            'beds'      => (string)($postedRoomBeds[$i] ?? ''),
            'qty'       => (string)($postedRoomQtys[$i] ?? '1'),
        ];
    }
  ?>

  const oldGuests = <?= json_encode($postedGuestsJs, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
  const oldRooms  = <?= json_encode($postedRoomsJs, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;

  if (oldGuests.length) {
    oldGuests.forEach(g => addGuestRow(g));
  } else {
    addGuestRow();
  }

  if (oldRooms.length) {
    oldRooms.forEach(r => addRoomRow(r));
  } else {
    addRoomRow();
  }

  syncServicePanels();

  const form = document.getElementById('serviceForm');
  const btnSave = document.getElementById('btnSave');

  form.addEventListener('submit', function(){
    btnSave.disabled = true;
    btnSave.innerHTML = 'Salvando…';
  });
});
</script>
<?php
$body = ob_get_clean();
require __DIR__ . '/../inc/layout.php';
