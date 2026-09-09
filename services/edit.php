<?php
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_login();

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die('Invalid service ID');
}

/*
|---------------------------------------------------
| Load main service
|---------------------------------------------------
*/
$stmt = $pdo->prepare("SELECT * FROM service_sales WHERE id = ? AND agency_id = ? LIMIT 1");
$stmt->execute([$id, agency_id()]);
$service = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$service) {
    die('Service not found');
}

if ($service['service_type'] !== 'hotel') {
    die('Only hotel editing is supported right now.');
}

/*
|---------------------------------------------------
| Load hotel details
|---------------------------------------------------
*/
$stmt = $pdo->prepare("SELECT * FROM service_hotels WHERE service_id = ? LIMIT 1");
$stmt->execute([$id]);
$hotel = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT * FROM service_hotel_rooms WHERE service_id = ? ORDER BY id ASC");
$stmt->execute([$id]);
$rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT * FROM service_guests WHERE service_id = ? ORDER BY id ASC");
$stmt->execute([$id]);
$guests = $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|---------------------------------------------------
| Load clients and suppliers for selects
|---------------------------------------------------
*/
$clientsStmt = $pdo->prepare("SELECT id, name FROM clients WHERE agency_id=? ORDER BY name ASC");
$clientsStmt->execute([agency_id()]);
$clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);

$suppliersStmt = $pdo->prepare("SELECT id, name FROM suppliers WHERE agency_id=? ORDER BY name ASC");
$suppliersStmt->execute([agency_id()]);
$suppliers = $suppliersStmt->fetchAll(PDO::FETCH_ASSOC);

/*
|---------------------------------------------------
| Save update
|---------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('CSRF inválido');
    }

    $client_id    = (int)($_POST['client_id'] ?? 0);
    $supplier_id  = trim((string)($_POST['supplier_id'] ?? '')) !== '' ? (int)$_POST['supplier_id'] : null;
    $reference    = trim((string)($_POST['reference'] ?? ''));
    $total_amount = (float)($_POST['total_amount'] ?? 0);
    $cost_amount  = (float)($_POST['cost_amount'] ?? 0);
    $currency     = trim((string)($_POST['currency'] ?? 'BRL'));
    $status       = trim((string)($_POST['status'] ?? 'pending'));
    $notes        = trim((string)($_POST['notes'] ?? ''));

    $hotel_name    = trim((string)($_POST['hotel_name'] ?? ''));
    $hotel_address = trim((string)($_POST['hotel_address'] ?? ''));
    $stars         = (int)($_POST['stars'] ?? 0);
    $checkin       = trim((string)($_POST['checkin'] ?? ''));
    $checkout      = trim((string)($_POST['checkout'] ?? ''));
    $nights        = (int)($_POST['nights'] ?? 0);
    $rooms_count   = (int)($_POST['rooms'] ?? 0);
    $room_type     = trim((string)($_POST['room_type'] ?? ''));
    $meal          = trim((string)($_POST['meal'] ?? ''));
    $cancel_policy = trim((string)($_POST['cancel_policy'] ?? ''));
    $image         = trim((string)($_POST['image'] ?? ''));

    $guest_names = $_POST['guest_name'] ?? [];
    $guest_types = $_POST['guest_type'] ?? [];

    $room_types     = $_POST['room_list_type'] ?? [];
    $room_guests    = $_POST['room_list_guests'] ?? [];
    $room_meals     = $_POST['room_list_meal'] ?? [];
    $room_beds      = $_POST['room_list_beds'] ?? [];
    $room_quantities= $_POST['room_list_qty'] ?? [];

    if ($client_id <= 0) {
        die('Client is required');
    }

    $vc = $pdo->prepare("SELECT id FROM clients WHERE id=? AND agency_id=? LIMIT 1");
    $vc->execute([$client_id, agency_id()]);
    if (!$vc->fetch()) {
        die('Invalid client');
    }

    if ($supplier_id !== null) {
        $vs = $pdo->prepare("SELECT id FROM suppliers WHERE id=? AND agency_id=? LIMIT 1");
        $vs->execute([$supplier_id, agency_id()]);
        if (!$vs->fetch()) {
            die('Invalid supplier');
        }
    }

    try {
        $pdo->beginTransaction();

        /*
        |---------------------------------------------------
        | Update main service_sales
        |---------------------------------------------------
        */
        $stmt = $pdo->prepare("
            UPDATE service_sales
            SET
                client_id = ?,
                supplier_id = ?,
                reference = ?,
                total_amount = ?,
                cost_amount = ?,
                currency = ?,
                status = ?,
                notes = ?,
                updated_at = NOW()
            WHERE id = ? AND agency_id = ?
        ");
        $stmt->execute([
            $client_id,
            $supplier_id,
            $reference,
            $total_amount,
            $cost_amount,
            $currency,
            $status,
            $notes,
            $id,
            agency_id()
        ]);

        /*
        |---------------------------------------------------
        | Update service_hotels
        |---------------------------------------------------
        */
        if ($hotel) {
            $stmt = $pdo->prepare("
                UPDATE service_hotels
                SET
                    hotel_name = ?,
                    hotel_address = ?,
                    stars = ?,
                    checkin = ?,
                    checkout = ?,
                    nights = ?,
                    rooms = ?,
                    room_type = ?,
                    meal = ?,
                    cancel_policy = ?,
                    image = ?
                WHERE service_id = ? AND agency_id = ?
            ");
            $stmt->execute([
                $hotel_name,
                $hotel_address,
                $stars,
                $checkin ?: null,
                $checkout ?: null,
                $nights,
                $rooms_count,
                $room_type,
                $meal,
                $cancel_policy,
                $image,
                $id,
                agency_id()
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO service_hotels
                (service_id, hotel_name, hotel_address, stars, checkin, checkout, nights, rooms, room_type, meal, cancel_policy, image, agency_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $id,
                $hotel_name,
                $hotel_address,
                $stars,
                $checkin ?: null,
                $checkout ?: null,
                $nights,
                $rooms_count,
                $room_type,
                $meal,
                $cancel_policy,
                $image,
                agency_id()
            ]);
        }

        /*
        |---------------------------------------------------
        | Replace guests
        |---------------------------------------------------
        */
        $stmt = $pdo->prepare("DELETE FROM service_guests WHERE service_id = ? AND agency_id = ?");
        $stmt->execute([$id, agency_id()]);

        $stmtGuest = $pdo->prepare("
            INSERT INTO service_guests (service_id, full_name, type, agency_id)
            VALUES (?, ?, ?, ?)
        ");

        foreach ($guest_names as $k => $name) {
            $name = trim((string)$name);
            $type = trim((string)($guest_types[$k] ?? ''));

            if ($name === '') {
                continue;
            }

            $stmtGuest->execute([$id, $name, $type, agency_id()]);
        }

        /*
        |---------------------------------------------------
        | Replace hotel rooms
        |---------------------------------------------------
        */
        $stmt = $pdo->prepare("DELETE FROM service_hotel_rooms WHERE service_id = ? AND agency_id = ?");
        $stmt->execute([$id, agency_id()]);

        $stmtRoom = $pdo->prepare("
            INSERT INTO service_hotel_rooms (service_id, room_type, guests, meal, beds, quantity, agency_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($room_types as $k => $type) {
            $type     = trim((string)$type);
            $rGuests  = trim((string)($room_guests[$k] ?? ''));
            $rMeal    = trim((string)($room_meals[$k] ?? ''));
            $rBeds    = trim((string)($room_beds[$k] ?? ''));
            $rQty     = (int)($room_quantities[$k] ?? 1);

            if ($type === '') {
                continue;
            }

            $stmtRoom->execute([$id, $type, $rGuests, $rMeal, $rBeds, $rQty, agency_id()]);
        }

        $pdo->commit();

        header('Location: /services/show.php?id=' . $id);
        exit;

    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('services/edit.php: ' . $e->getMessage());
        die('Update failed.');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Service</title>
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #f5f7fb;
            font-family: Arial, Helvetica, sans-serif;
            color: #16395f;
        }

        .page {
            max-width: 1100px;
            margin: 0 auto;
            padding: 20px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }

        .title {
            margin: 0;
            font-size: 26px;
            font-weight: 700;
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-block;
            padding: 10px 14px;
            border-radius: 10px;
            text-decoration: none;
            border: 1px solid #d9e3ef;
            background: #fff;
            color: #16395f;
            font-weight: 700;
            cursor: pointer;
        }

        .btn-primary {
            background: #0d6efd;
            border-color: #0d6efd;
            color: #fff;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .card {
            background: #fff;
            border: 1px solid #d9e3ef;
            border-radius: 16px;
            overflow: hidden;
            margin-bottom: 18px;
        }

        .card-header {
            padding: 16px 18px;
            border-bottom: 1px solid #e8eef5;
            font-size: 18px;
            font-weight: 700;
        }

        .card-body {
            padding: 18px;
        }

        .field-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .field {
            margin-bottom: 12px;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 6px;
            color: #16395f;
        }

        input,
        select,
        textarea {
            width: 100%;
            border: 1px solid #d9e3ef;
            border-radius: 10px;
            padding: 11px 12px;
            font: inherit;
            color: #16395f;
            background: #fff;
        }

        textarea {
            min-height: 110px;
            resize: vertical;
        }

        .repeat-list {
            display: grid;
            gap: 12px;
        }

        .repeat-card {
            background: #f7faff;
            border: 1px solid #dce7f3;
            border-radius: 12px;
            padding: 14px;
        }

        .repeat-title {
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 10px;
        }

        .repeat-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }

        .btn-small {
            padding: 8px 10px;
            border-radius: 8px;
            font-size: 13px;
        }

        @media (max-width: 900px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .field-grid {
                grid-template-columns: 1fr;
            }

            .page {
                padding: 14px;
            }

            .title {
                font-size: 22px;
            }
        }
    </style>
</head>
<body>
<div class="page">

    <div class="topbar">
        <h1 class="title">Edit Service #<?= (int)$service['id'] ?></h1>

        <div class="actions">
            <a class="btn" href="/services/show.php?id=<?= (int)$service['id'] ?>">Back</a>
            <button type="submit" form="serviceForm" class="btn btn-primary">Save Changes</button>
        </div>
    </div>

    <form method="post" id="serviceForm">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

        <div class="grid">

            <div>
                <div class="card">
                    <div class="card-header">General Information</div>
                    <div class="card-body">
                        <div class="field-grid">
                            <div class="field">
                                <label>Client</label>
                                <select name="client_id" required>
                                    <option value="">Select client</option>
                                    <?php foreach ($clients as $client): ?>
                                        <option value="<?= (int)$client['id'] ?>" <?= (int)$service['client_id'] === (int)$client['id'] ? 'selected' : '' ?>>
                                            <?= e($client['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="field">
                                <label>Supplier</label>
                                <select name="supplier_id">
                                    <option value="">Select supplier</option>
                                    <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?= (int)$supplier['id'] ?>" <?= (int)$service['supplier_id'] === (int)$supplier['id'] ? 'selected' : '' ?>>
                                            <?= e($supplier['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="field">
                                <label>Reference</label>
                                <input type="text" name="reference" value="<?= e($service['reference']) ?>">
                            </div>

                            <div class="field">
                                <label>Status</label>
                                <select name="status">
                                    <?php
                                    $statuses = ['pending', 'confirmed', 'cancelled', 'refunded'];
                                    foreach ($statuses as $status):
                                    ?>
                                        <option value="<?= e($status) ?>" <?= $service['status'] === $status ? 'selected' : '' ?>>
                                            <?= e(ucfirst($status)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="field">
                                <label>Total Amount</label>
                                <input type="number" step="0.01" name="total_amount" value="<?= e($service['total_amount']) ?>">
                            </div>

                            <div class="field">
                                <label>Cost Amount</label>
                                <input type="number" step="0.01" name="cost_amount" value="<?= e($service['cost_amount']) ?>">
                            </div>

                            <div class="field">
                                <label>Currency</label>
                                <input type="text" name="currency" value="<?= e($service['currency'] ?: 'BRL') ?>">
                            </div>

                            <div class="field full">
                                <label>Notes</label>
                                <textarea name="notes"><?= e($service['notes']) ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">Hotel Information</div>
                    <div class="card-body">
                        <div class="field-grid">
                            <div class="field">
                                <label>Hotel Name</label>
                                <input type="text" name="hotel_name" value="<?= e($hotel['hotel_name'] ?? '') ?>">
                            </div>

                            <div class="field">
                                <label>Stars</label>
                                <input type="number" name="stars" value="<?= e($hotel['stars'] ?? '') ?>">
                            </div>

                            <div class="field full">
                                <label>Hotel Address</label>
                                <textarea name="hotel_address"><?= e($hotel['hotel_address'] ?? '') ?></textarea>
                            </div>

                            <div class="field">
                                <label>Check-in</label>
                                <input type="date" name="checkin" value="<?= e($hotel['checkin'] ?? '') ?>">
                            </div>

                            <div class="field">
                                <label>Check-out</label>
                                <input type="date" name="checkout" value="<?= e($hotel['checkout'] ?? '') ?>">
                            </div>

                            <div class="field">
                                <label>Nights</label>
                                <input type="number" name="nights" value="<?= e($hotel['nights'] ?? '') ?>">
                            </div>

                            <div class="field">
                                <label>Rooms</label>
                                <input type="number" name="rooms" value="<?= e($hotel['rooms'] ?? '') ?>">
                            </div>

                            <div class="field">
                                <label>Main Room Type</label>
                                <input type="text" name="room_type" value="<?= e($hotel['room_type'] ?? '') ?>">
                            </div>

                            <div class="field">
                                <label>Meal Plan</label>
                                <input type="text" name="meal" value="<?= e($hotel['meal'] ?? '') ?>">
                            </div>

                            <div class="field full">
                                <label>Image URL</label>
                                <input type="text" name="image" value="<?= e($hotel['image'] ?? '') ?>">
                            </div>

                            <div class="field full">
                                <label>Cancellation Policy</label>
                                <textarea name="cancel_policy"><?= e($hotel['cancel_policy'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div>
                <div class="card">
                    <div class="card-header">Guests</div>
                    <div class="card-body">
                        <div id="guestsList" class="repeat-list">
                            <?php if ($guests): ?>
                                <?php foreach ($guests as $i => $guest): ?>
                                    <div class="repeat-card guest-item">
                                        <div class="repeat-title">Guest <?= $i + 1 ?></div>
                                        <div class="field-grid">
                                            <div class="field">
                                                <label>Name</label>
                                                <input type="text" name="guest_name[]" value="<?= e($guest['full_name']) ?>">
                                            </div>
                                            <div class="field">
                                                <label>Type</label>
                                                <input type="text" name="guest_type[]" value="<?= e($guest['type']) ?>" placeholder="Adult / Child / Infant">
                                            </div>
                                        </div>
                                        <div class="repeat-actions">
                                            <button type="button" class="btn btn-small remove-guest">Remove</button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="repeat-card guest-item">
                                    <div class="repeat-title">Guest 1</div>
                                    <div class="field-grid">
                                        <div class="field">
                                            <label>Name</label>
                                            <input type="text" name="guest_name[]">
                                        </div>
                                        <div class="field">
                                            <label>Type</label>
                                            <input type="text" name="guest_type[]" placeholder="Adult / Child / Infant">
                                        </div>
                                    </div>
                                    <div class="repeat-actions">
                                        <button type="button" class="btn btn-small remove-guest">Remove</button>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div style="margin-top:12px;">
                            <button type="button" class="btn" id="addGuestBtn">Add Guest</button>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">Rooms</div>
                    <div class="card-body">
                        <div id="roomsList" class="repeat-list">
                            <?php if ($rooms): ?>
                                <?php foreach ($rooms as $i => $room): ?>
                                    <div class="repeat-card room-item">
                                        <div class="repeat-title">Room <?= $i + 1 ?></div>
                                        <div class="field-grid">
                                            <div class="field">
                                                <label>Room Type</label>
                                                <input type="text" name="room_list_type[]" value="<?= e($room['room_type']) ?>">
                                            </div>

                                            <div class="field">
                                                <label>Guests</label>
                                                <input type="text" name="room_list_guests[]" value="<?= e($room['guests']) ?>" placeholder="2 Adults">
                                            </div>

                                            <div class="field">
                                                <label>Meal</label>
                                                <input type="text" name="room_list_meal[]" value="<?= e($room['meal']) ?>">
                                            </div>

                                            <div class="field">
                                                <label>Beds</label>
                                                <input type="text" name="room_list_beds[]" value="<?= e($room['beds']) ?>">
                                            </div>

                                            <div class="field">
                                                <label>Quantity</label>
                                                <input type="number" name="room_list_qty[]" value="<?= e($room['quantity']) ?>">
                                            </div>
                                        </div>

                                        <div class="repeat-actions">
                                            <button type="button" class="btn btn-small remove-room">Remove</button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="repeat-card room-item">
                                    <div class="repeat-title">Room 1</div>
                                    <div class="field-grid">
                                        <div class="field">
                                            <label>Room Type</label>
                                            <input type="text" name="room_list_type[]">
                                        </div>

                                        <div class="field">
                                            <label>Guests</label>
                                            <input type="text" name="room_list_guests[]" placeholder="2 Adults">
                                        </div>

                                        <div class="field">
                                            <label>Meal</label>
                                            <input type="text" name="room_list_meal[]">
                                        </div>

                                        <div class="field">
                                            <label>Beds</label>
                                            <input type="text" name="room_list_beds[]">
                                        </div>

                                        <div class="field">
                                            <label>Quantity</label>
                                            <input type="number" name="room_list_qty[]" value="1">
                                        </div>
                                    </div>

                                    <div class="repeat-actions">
                                        <button type="button" class="btn btn-small remove-room">Remove</button>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div style="margin-top:12px;">
                            <button type="button" class="btn" id="addRoomBtn">Add Room</button>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </form>
</div>

<script>
(function () {
    const guestsList = document.getElementById('guestsList');
    const roomsList  = document.getElementById('roomsList');
    const addGuestBtn = document.getElementById('addGuestBtn');
    const addRoomBtn  = document.getElementById('addRoomBtn');

    function refreshGuestTitles() {
        guestsList.querySelectorAll('.guest-item').forEach((item, index) => {
            const title = item.querySelector('.repeat-title');
            if (title) title.textContent = 'Guest ' + (index + 1);
        });
    }

    function refreshRoomTitles() {
        roomsList.querySelectorAll('.room-item').forEach((item, index) => {
            const title = item.querySelector('.repeat-title');
            if (title) title.textContent = 'Room ' + (index + 1);
        });
    }

    addGuestBtn.addEventListener('click', function () {
        const div = document.createElement('div');
        div.className = 'repeat-card guest-item';
        div.innerHTML = `
            <div class="repeat-title"></div>
            <div class="field-grid">
                <div class="field">
                    <label>Name</label>
                    <input type="text" name="guest_name[]">
                </div>
                <div class="field">
                    <label>Type</label>
                    <input type="text" name="guest_type[]" placeholder="Adult / Child / Infant">
                </div>
            </div>
            <div class="repeat-actions">
                <button type="button" class="btn btn-small remove-guest">Remove</button>
            </div>
        `;
        guestsList.appendChild(div);
        refreshGuestTitles();
    });

    addRoomBtn.addEventListener('click', function () {
        const div = document.createElement('div');
        div.className = 'repeat-card room-item';
        div.innerHTML = `
            <div class="repeat-title"></div>
            <div class="field-grid">
                <div class="field">
                    <label>Room Type</label>
                    <input type="text" name="room_list_type[]">
                </div>
                <div class="field">
                    <label>Guests</label>
                    <input type="text" name="room_list_guests[]" placeholder="2 Adults">
                </div>
                <div class="field">
                    <label>Meal</label>
                    <input type="text" name="room_list_meal[]">
                </div>
                <div class="field">
                    <label>Beds</label>
                    <input type="text" name="room_list_beds[]">
                </div>
                <div class="field">
                    <label>Quantity</label>
                    <input type="number" name="room_list_qty[]" value="1">
                </div>
            </div>
            <div class="repeat-actions">
                <button type="button" class="btn btn-small remove-room">Remove</button>
            </div>
        `;
        roomsList.appendChild(div);
        refreshRoomTitles();
    });

    document.addEventListener('click', function (e) {
        if (e.target.classList.contains('remove-guest')) {
            const items = guestsList.querySelectorAll('.guest-item');
            if (items.length > 1) {
                e.target.closest('.guest-item').remove();
                refreshGuestTitles();
            }
        }

        if (e.target.classList.contains('remove-room')) {
            const items = roomsList.querySelectorAll('.room-item');
            if (items.length > 1) {
                e.target.closest('.room-item').remove();
                refreshRoomTitles();
            }
        }
    });

    refreshGuestTitles();
    refreshRoomTitles();
})();
</script>
</body>
</html>
