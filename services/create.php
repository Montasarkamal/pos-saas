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
require __DIR__ . '/../inc/header.php';
?>

<style>
.table-wrap { overflow:auto; -webkit-overflow-scrolling:touch; }
.table-wrap .table { min-width: 820px; }
.is-invalid { background: #fff1f2 !important; border-color: #dc3545 !important; }
</style>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.css">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>

<form method="post" id="serviceForm" autocomplete="off">
  <?php if ($err): ?>
    <div class="alert alert-danger"><?= e($err) ?></div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header"><h3 class="card-title">Dados da Venda</h3></div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Tipo de venda *</label>
          <select class="form-select" id="serviceType" name="service_type" required>
            <?php foreach ($serviceTypes as $value => $label): ?>
              <option value="<?= e($value) ?>" <?= $old['service_type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">Cliente *</label>
          <select class="form-select" id="clientSelect" name="client_id" required>
            <option value="">— selecione —</option>
            <?php foreach ($clients as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= ((string)$c['id'] === (string)$old['client_id']) ? 'selected' : '' ?>>
                <?= e($c['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">Fornecedor</label>
          <select class="form-select" id="supplierSelect" name="supplier_id">
            <option value="">— nenhum —</option>
            <?php foreach ($suppliers as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= ((string)$s['id'] === (string)$old['supplier_id']) ? 'selected' : '' ?>>
                <?= e($s['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">Referência</label>
          <input type="text" class="form-control" name="reference" value="<?= e($old['reference']) ?>" placeholder="Código interno / localizador">
        </div>

        <div class="col-md-3">
          <label class="form-label">Status</label>
          <select class="form-select" name="status">
            <option value="pending"   <?= $old['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="confirmed" <?= $old['status'] === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
            <option value="cancelled" <?= $old['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
            <option value="refunded"  <?= $old['status'] === 'refunded' ? 'selected' : '' ?>>Refunded</option>
          </select>
        </div>

        <div class="col-md-3">
          <label class="form-label">Moeda</label>
          <select class="form-select" name="currency">
            <option value="BRL" <?= $old['currency'] === 'BRL' ? 'selected' : '' ?>>BRL</option>
            <option value="USD" <?= $old['currency'] === 'USD' ? 'selected' : '' ?>>USD</option>
            <option value="EUR" <?= $old['currency'] === 'EUR' ? 'selected' : '' ?>>EUR</option>
          </select>
        </div>

        <div class="col-md-3">
          <label class="form-label">Valor Total</label>
          <input class="form-control" name="total_amount" id="total_amount" value="<?= e($old['total_amount']) ?>" placeholder="0,00">
        </div>

        <div class="col-md-3">
          <label class="form-label">Custo</label>
          <input class="form-control" name="cost_amount" id="cost_amount" value="<?= e($old['cost_amount']) ?>" placeholder="0,00">
        </div>

        <div class="col-md-12">
          <label class="form-label">Observações</label>
          <textarea class="form-control" name="notes" rows="3"><?= e($old['notes']) ?></textarea>
        </div>
      </div>
    </div>
  </div>

  <div class="card service-panel" data-service-panel="hotel">
    <div class="card-header"><h3 class="card-title">Dados do Hotel</h3></div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Hotel Name *</label>
          <input type="text" class="form-control" name="hotel_name" value="<?= e($old['hotel_name']) ?>" required>
        </div>

        <div class="col-md-2">
          <label class="form-label">Stars</label>
          <input type="number" class="form-control" name="stars" min="0" max="7" value="<?= e($old['stars']) ?>">
        </div>

        <div class="col-md-2">
          <label class="form-label">Check-in *</label>
          <input type="date" class="form-control" name="checkin" value="<?= e($old['checkin']) ?>" required>
        </div>

        <div class="col-md-2">
          <label class="form-label">Check-out *</label>
          <input type="date" class="form-control" name="checkout" value="<?= e($old['checkout']) ?>" required>
        </div>

        <div class="col-md-3">
          <label class="form-label">Nights</label>
          <input type="number" class="form-control" name="nights" id="nights" value="<?= e($old['nights']) ?>" min="0">
        </div>

        <div class="col-md-3">
          <label class="form-label">Rooms</label>
          <input type="number" class="form-control" name="rooms" value="<?= e($old['rooms']) ?>" min="0">
        </div>

        <div class="col-md-3">
          <label class="form-label">Main Room Type</label>
          <input type="text" class="form-control" name="room_type" value="<?= e($old['room_type']) ?>" placeholder="Luxury Room - 1 King Bed">
        </div>

        <div class="col-md-3">
          <label class="form-label">Meal Plan</label>
          <input type="text" class="form-control" name="meal" value="<?= e($old['meal']) ?>" placeholder="Breakfast Included">
        </div>

        <div class="col-md-12">
          <label class="form-label">Hotel Address</label>
          <textarea class="form-control" name="hotel_address" rows="3"><?= e($old['hotel_address']) ?></textarea>
        </div>

        <div class="col-md-12">
          <label class="form-label">Image URL</label>
          <input type="text" class="form-control" name="image" value="<?= e($old['image']) ?>" placeholder="https://...">
        </div>

        <div class="col-md-12">
          <label class="form-label">Cancellation Policy</label>
          <textarea class="form-control" name="cancel_policy" rows="4"><?= e($old['cancel_policy']) ?></textarea>
        </div>
      </div>
    </div>
  </div>

  <div class="card service-panel" data-service-panel="car">
    <div class="card-header"><h3 class="card-title">Dados do Aluguel de Carro</h3></div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Locadora *</label>
          <input type="text" class="form-control" name="car_company_name" value="<?= e($old['car_company_name']) ?>" data-required-for="car">
        </div>
        <div class="col-md-6">
          <label class="form-label">Tipo / Categoria do carro</label>
          <input type="text" class="form-control" name="car_type" value="<?= e($old['car_type']) ?>" placeholder="SUV, Econômico, Executivo...">
        </div>
        <div class="col-md-3">
          <label class="form-label">Retirada</label>
          <input type="date" class="form-control" name="pickup_date" value="<?= e($old['pickup_date']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Devolução</label>
          <input type="date" class="form-control" name="return_date" value="<?= e($old['return_date']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Local de retirada</label>
          <input type="text" class="form-control" name="pickup_location" value="<?= e($old['pickup_location']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Local de devolução</label>
          <input type="text" class="form-control" name="return_location" value="<?= e($old['return_location']) ?>">
        </div>
        <div class="col-md-12">
          <label class="form-label">Condutor / Observações</label>
          <textarea class="form-control" name="driver_name" rows="3"><?= e($old['driver_name']) ?></textarea>
        </div>
      </div>
    </div>
  </div>

  <div class="card service-panel" data-service-panel="insurance">
    <div class="card-header"><h3 class="card-title">Dados do Seguro Saúde</h3></div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Seguradora *</label>
          <input type="text" class="form-control" name="insurance_provider_name" value="<?= e($old['insurance_provider_name']) ?>" data-required-for="insurance">
        </div>
        <div class="col-md-6">
          <label class="form-label">Plano</label>
          <input type="text" class="form-control" name="insurance_plan_name" value="<?= e($old['insurance_plan_name']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Início da cobertura</label>
          <input type="date" class="form-control" name="insurance_start_date" value="<?= e($old['insurance_start_date']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Fim da cobertura</label>
          <input type="date" class="form-control" name="insurance_end_date" value="<?= e($old['insurance_end_date']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Cobertura</label>
          <input type="text" class="form-control" name="insurance_coverage_amount" value="<?= e($old['insurance_coverage_amount']) ?>" placeholder="USD 60.000">
        </div>
        <div class="col-md-3">
          <label class="form-label">Destino</label>
          <input type="text" class="form-control" name="insurance_destination" value="<?= e($old['insurance_destination']) ?>">
        </div>
      </div>
    </div>
  </div>

  <div class="card service-panel" data-service-panel="reception guide transfer other">
    <div class="card-header"><h3 class="card-title">Dados do Serviço</h3></div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Nome do serviço *</label>
          <input type="text" class="form-control" name="generic_title" value="<?= e($old['generic_title']) ?>" placeholder="Recepção, guia turístico, transfer..." data-required-for="reception guide transfer other">
        </div>
        <div class="col-md-3">
          <label class="form-label">Data inicial</label>
          <input type="date" class="form-control" name="generic_start_date" value="<?= e($old['generic_start_date']) ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Data final</label>
          <input type="date" class="form-control" name="generic_end_date" value="<?= e($old['generic_end_date']) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Local</label>
          <input type="text" class="form-control" name="generic_location" value="<?= e($old['generic_location']) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Participantes</label>
          <input type="text" class="form-control" name="generic_participants" value="<?= e($old['generic_participants']) ?>">
        </div>
        <div class="col-md-12">
          <label class="form-label">Detalhes</label>
          <textarea class="form-control" name="generic_details" rows="4"><?= e($old['generic_details']) ?></textarea>
        </div>
      </div>
    </div>
  </div>

  <div class="card people-panel">
    <div class="card-header d-flex align-items-center">
      <h3 class="card-title">Pessoas / Passageiros</h3>
      <div class="ms-auto btn-list">
        <button type="button" id="addGuest" class="btn btn-outline-primary">
          <i class="ti ti-plus"></i> Adicionar
        </button>
      </div>
    </div>
    <div class="card-body table-wrap">
      <table class="table" id="guestsTable">
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

  <div class="card service-panel" data-service-panel="hotel">
    <div class="card-header d-flex align-items-center">
      <h3 class="card-title">Quartos</h3>
      <div class="ms-auto btn-list">
        <button type="button" id="addRoom" class="btn btn-outline-primary">
          <i class="ti ti-plus"></i> Adicionar
        </button>
      </div>
    </div>
    <div class="card-body table-wrap">
      <table class="table" id="roomsTable">
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

  <div class="d-flex justify-content-end mb-4 gap-2">
    <a href="/services/index.php" class="btn">Cancelar</a>
    <button class="btn btn-primary" type="submit" id="btnSave">
      <i class="ti ti-device-floppy me-1"></i> Salvar
    </button>
  </div>
</form>

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
      <td><input name="guest_name[]" class="form-control" placeholder="FULL NAME" value="${(data.name || '').replace(/"/g,'&quot;')}"></td>
      <td>
        <select name="guest_type[]" class="form-select">
          <option value="Adult" ${data.type === 'Adult' ? 'selected' : ''}>Adult</option>
          <option value="Child" ${data.type === 'Child' ? 'selected' : ''}>Child</option>
          <option value="Infant" ${data.type === 'Infant' ? 'selected' : ''}>Infant</option>
        </select>
      </td>
      <td class="text-end">
        <div class="btn-list justify-content-end">
          <button type="button" class="btn btn-outline-danger delRow"><i class="ti ti-trash"></i> Remover</button>
        </div>
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
      <td><input name="room_list_type[]" class="form-control" placeholder="Room Type" value="${(data.room_type || '').replace(/"/g,'&quot;')}"></td>
      <td><input name="room_list_guests[]" class="form-control" placeholder="2 Adults" value="${(data.guests || '').replace(/"/g,'&quot;')}"></td>
      <td><input name="room_list_meal[]" class="form-control" placeholder="Breakfast Included" value="${(data.meal || '').replace(/"/g,'&quot;')}"></td>
      <td><input name="room_list_beds[]" class="form-control" placeholder="1 King Bed" value="${(data.beds || '').replace(/"/g,'&quot;')}"></td>
      <td><input name="room_list_qty[]" class="form-control" type="number" min="1" value="${(data.qty || '1').toString().replace(/"/g,'&quot;')}"></td>
      <td class="text-end">
        <div class="btn-list justify-content-end">
          <button type="button" class="btn btn-outline-danger delRow"><i class="ti ti-trash"></i> Remover</button>
        </div>
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

<?php require __DIR__ . '/../inc/footer.php'; ?>
