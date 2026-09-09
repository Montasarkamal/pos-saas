<?php
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_login();

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('money_fmt')) {
    function money_fmt($amount, $currency = 'BRL'): string {
        $amount = (float)$amount;
        return e($currency) . ' ' . number_format($amount, 2, ',', '.');
    }
}

if (!function_exists('status_badge_class')) {
    function status_badge_class(string $status): string {
        $status = strtolower(trim($status));

        return match ($status) {
            'confirmed' => 'badge-success',
            'pending'   => 'badge-warning',
            'cancelled' => 'badge-danger',
            'refunded'  => 'badge-secondary',
            default     => 'badge-primary',
        };
    }
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die('Invalid service ID');
}

$serviceTypes = [
    'hotel'     => 'Hotel',
    'car'       => 'Aluguel de Carro',
    'insurance' => 'Seguro Saúde',
    'reception' => 'Recepção',
    'guide'     => 'Guia Turístico',
    'transfer'  => 'Transfer',
    'other'     => 'Outro Serviço',
];

/*
|---------------------------------------------------
| Main service
|---------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT 
        s.*,
        c.name AS client_name,
        sup.name AS supplier_name,
        u.name AS created_by_name
    FROM service_sales s
    LEFT JOIN clients c   ON c.id = s.client_id AND c.agency_id = s.agency_id
    LEFT JOIN suppliers sup ON sup.id = s.supplier_id AND sup.agency_id = s.agency_id
    LEFT JOIN users u     ON u.id = s.created_by
    WHERE s.id = ? AND s.agency_id = ?
    LIMIT 1
");
$stmt->execute([$id, agency_id()]);
$service = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$service) {
    die('Service not found');
}

$hotel = null;
$hotelRooms = [];
$guests = [];
$car = null;
$insurance = null;
$detail = null;

/*
|---------------------------------------------------
| Load type details
|---------------------------------------------------
*/
if ($service['service_type'] === 'hotel') {
    $stmt = $pdo->prepare("SELECT * FROM service_hotels WHERE service_id = ? LIMIT 1");
    $stmt->execute([$id]);
    $hotel = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM service_hotel_rooms WHERE service_id = ? ORDER BY id ASC");
    $stmt->execute([$id]);
    $hotelRooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM service_guests WHERE service_id = ? ORDER BY id ASC");
    $stmt->execute([$id]);
    $guests = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($service['service_type'] === 'car') {
    $stmt = $pdo->prepare("SELECT * FROM service_cars WHERE service_id = ? LIMIT 1");
    $stmt->execute([$id]);
    $car = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM service_guests WHERE service_id = ? ORDER BY id ASC");
    $stmt->execute([$id]);
    $guests = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($service['service_type'] === 'insurance') {
    $stmt = $pdo->prepare("SELECT * FROM service_insurance WHERE service_id = ? LIMIT 1");
    $stmt->execute([$id]);
    $insurance = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("SELECT * FROM service_guests WHERE service_id = ? ORDER BY id ASC");
    $stmt->execute([$id]);
    $guests = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if (in_array($service['service_type'], ['reception', 'guide', 'transfer', 'other'], true)) {
    $stmt = $pdo->prepare("SELECT * FROM service_details WHERE service_id = ? LIMIT 1");
    $stmt->execute([$id]);
    $detail = $stmt->fetch(PDO::FETCH_ASSOC);
}

$profit = (float)$service['total_amount'] - (float)$service['cost_amount'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Details</title>

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
            font-size: 26px;
            font-weight: 700;
            margin: 0;
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
        }

        .btn-primary {
            background: #0d6efd;
            border-color: #0d6efd;
            color: #fff;
        }

        .grid {
            display: grid;
            grid-template-columns: 1.15fr 0.85fr;
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

        .meta-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .meta-item {
            background: #f7faff;
            border: 1px solid #dce7f3;
            border-radius: 12px;
            padding: 12px;
        }

        .label {
            font-size: 12px;
            color: #6a7f96;
            margin-bottom: 6px;
        }

        .value {
            font-size: 15px;
            font-weight: 700;
            line-height: 1.45;
            word-break: break-word;
        }

        .badge {
            display: inline-block;
            padding: 7px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            color: #fff;
        }

        .badge-success { background: #198754; }
        .badge-warning { background: #f0ad4e; }
        .badge-danger { background: #dc3545; }
        .badge-secondary { background: #6c757d; }
        .badge-primary { background: #0d6efd; }

        .section-block + .section-block {
            margin-top: 14px;
        }

        .list {
            display: grid;
            gap: 10px;
        }

        .list-card {
            background: #f7faff;
            border: 1px solid #dce7f3;
            border-radius: 12px;
            padding: 14px;
        }

        .list-title {
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .mini-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }

        .notes {
            white-space: pre-line;
            line-height: 1.7;
            color: #16395f;
        }

        .price-summary {
            display: grid;
            gap: 10px;
        }

        .price-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: center;
            background: #f7faff;
            border: 1px solid #dce7f3;
            border-radius: 12px;
            padding: 12px 14px;
            font-weight: 700;
        }

        .image-box img {
            width: 100%;
            max-height: 280px;
            object-fit: cover;
            display: block;
            border-radius: 12px;
            border: 1px solid #dce7f3;
        }

        @media (max-width: 900px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .meta-grid,
            .mini-grid {
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
        <h1 class="title">Service Details #<?= (int)$service['id'] ?></h1>

        <div class="actions">
            <a class="btn" href="/services/index.php">Back</a>
            <?php if ($service['service_type'] === 'hotel'): ?>
                <a class="btn" href="/services/print.php?id=<?= (int)$service['id'] ?>" target="_blank">Print</a>
            <?php endif; ?>
            <?php if ($service['service_type'] === 'hotel'): ?>
                <a class="btn btn-primary" href="/services/edit.php?id=<?= (int)$service['id'] ?>">Edit</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid">

        <div>
            <div class="card">
                <div class="card-header">General Information</div>
                <div class="card-body">
                    <div class="meta-grid">
                        <div class="meta-item">
                            <div class="label">Service Type</div>
                            <div class="value"><?= e($serviceTypes[$service['service_type']] ?? ucfirst($service['service_type'])) ?></div>
                        </div>

                        <div class="meta-item">
                            <div class="label">Status</div>
                            <div class="value">
                                <span class="badge <?= e(status_badge_class($service['status'])) ?>">
                                    <?= e(ucfirst($service['status'])) ?>
                                </span>
                            </div>
                        </div>

                        <div class="meta-item">
                            <div class="label">Reference</div>
                            <div class="value"><?= e($service['reference'] ?: '-') ?></div>
                        </div>

                        <div class="meta-item">
                            <div class="label">Currency</div>
                            <div class="value"><?= e($service['currency'] ?: 'BRL') ?></div>
                        </div>

                        <div class="meta-item">
                            <div class="label">Client</div>
                            <div class="value"><?= e($service['client_name'] ?: ('#' . $service['client_id'])) ?></div>
                        </div>

                        <div class="meta-item">
                            <div class="label">Supplier</div>
                            <div class="value"><?= e($service['supplier_name'] ?: '-') ?></div>
                        </div>

                        <div class="meta-item">
                            <div class="label">Created By</div>
                            <div class="value"><?= e($service['created_by_name'] ?: '-') ?></div>
                        </div>

                        <div class="meta-item">
                            <div class="label">Created At</div>
                            <div class="value"><?= e($service['created_at']) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($service['service_type'] === 'hotel' && $hotel): ?>
                <div class="card">
                    <div class="card-header">Hotel Information</div>
                    <div class="card-body">

                        <?php if (!empty($hotel['image'])): ?>
                            <div class="image-box section-block">
                                <img src="<?= e($hotel['image']) ?>" alt="Hotel Image">
                            </div>
                        <?php endif; ?>

                        <div class="meta-grid">
                            <div class="meta-item">
                                <div class="label">Hotel Name</div>
                                <div class="value"><?= e($hotel['hotel_name']) ?></div>
                            </div>

                            <div class="meta-item">
                                <div class="label">Stars</div>
                                <div class="value"><?= str_repeat('★', max(0, (int)$hotel['stars'])) ?></div>
                            </div>

                            <div class="meta-item" style="grid-column: 1 / -1;">
                                <div class="label">Hotel Address</div>
                                <div class="value"><?= e($hotel['hotel_address']) ?></div>
                            </div>

                            <div class="meta-item">
                                <div class="label">Check-in</div>
                                <div class="value"><?= e($hotel['checkin']) ?></div>
                            </div>

                            <div class="meta-item">
                                <div class="label">Check-out</div>
                                <div class="value"><?= e($hotel['checkout']) ?></div>
                            </div>

                            <div class="meta-item">
                                <div class="label">Nights</div>
                                <div class="value"><?= e($hotel['nights']) ?></div>
                            </div>

                            <div class="meta-item">
                                <div class="label">Rooms</div>
                                <div class="value"><?= e($hotel['rooms']) ?></div>
                            </div>

                            <div class="meta-item">
                                <div class="label">Room Type</div>
                                <div class="value"><?= e($hotel['room_type']) ?></div>
                            </div>

                            <div class="meta-item">
                                <div class="label">Meal Plan</div>
                                <div class="value"><?= e($hotel['meal']) ?></div>
                            </div>
                        </div>

                        <?php if (!empty($guests)): ?>
                            <div class="section-block">
                                <div class="card-header" style="padding-left:0;padding-right:0;border:0;">Guests</div>
                                <div class="list">
                                    <?php foreach ($guests as $i => $guest): ?>
                                        <div class="list-card">
                                            <div class="list-title">Guest <?= $i + 1 ?></div>
                                            <div class="mini-grid">
                                                <div>
                                                    <div class="label">Name</div>
                                                    <div class="value"><?= e($guest['full_name']) ?></div>
                                                </div>
                                                <div>
                                                    <div class="label">Type</div>
                                                    <div class="value"><?= e($guest['type']) ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($hotelRooms)): ?>
                            <div class="section-block">
                                <div class="card-header" style="padding-left:0;padding-right:0;border:0;">Rooms</div>
                                <div class="list">
                                    <?php foreach ($hotelRooms as $i => $room): ?>
                                        <div class="list-card">
                                            <div class="list-title">Room <?= $i + 1 ?></div>

                                            <div class="mini-grid">
                                                <div>
                                                    <div class="label">Room Type</div>
                                                    <div class="value"><?= e($room['room_type']) ?></div>
                                                </div>

                                                <div>
                                                    <div class="label">Guests</div>
                                                    <div class="value"><?= e($room['guests']) ?></div>
                                                </div>

                                                <div>
                                                    <div class="label">Meal</div>
                                                    <div class="value"><?= e($room['meal']) ?></div>
                                                </div>

                                                <div>
                                                    <div class="label">Beds</div>
                                                    <div class="value"><?= e($room['beds']) ?></div>
                                                </div>

                                                <div>
                                                    <div class="label">Quantity</div>
                                                    <div class="value"><?= e($room['quantity']) ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($hotel['cancel_policy'])): ?>
                            <div class="section-block">
                                <div class="card-header" style="padding-left:0;padding-right:0;border:0;">Cancellation Policy</div>
                                <div class="notes"><?= e($hotel['cancel_policy']) ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($service['service_type'] === 'car' && $car): ?>
                <div class="card">
                    <div class="card-header">Car Rental Details</div>
                    <div class="card-body">
                        <div class="meta-grid">
                            <div class="meta-item">
                                <div class="label">Company</div>
                                <div class="value"><?= e($car['company_name'] ?? '') ?></div>
                            </div>

                            <div class="meta-item">
                                <div class="label">Car Type</div>
                                <div class="value"><?= e($car['car_type'] ?? '') ?></div>
                            </div>

                            <div class="meta-item">
                                <div class="label">Pickup Date</div>
                                <div class="value"><?= e($car['pickup_date'] ?? '') ?></div>
                            </div>

                            <div class="meta-item">
                                <div class="label">Return Date</div>
                                <div class="value"><?= e($car['return_date'] ?? '') ?></div>
                            </div>

                            <div class="meta-item">
                                <div class="label">Pickup Location</div>
                                <div class="value"><?= e($car['pickup_location'] ?? '') ?></div>
                            </div>

                            <div class="meta-item">
                                <div class="label">Return Location</div>
                                <div class="value"><?= e($car['return_location'] ?? '') ?></div>
                            </div>
                        </div>

                        <?php if (!empty($guests)): ?>
                            <div class="section-block">
                                <div class="card-header" style="padding-left:0;padding-right:0;border:0;">Guests / Drivers</div>
                                <div class="list">
                                    <?php foreach ($guests as $i => $guest): ?>
                                        <div class="list-card">
                                            <div class="list-title">Person <?= $i + 1 ?></div>
                                            <div class="mini-grid">
                                                <div>
                                                    <div class="label">Name</div>
                                                    <div class="value"><?= e($guest['full_name']) ?></div>
                                                </div>
                                                <div>
                                                    <div class="label">Type</div>
                                                    <div class="value"><?= e($guest['type']) ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($service['service_type'] === 'insurance' && $insurance): ?>
                <div class="card">
                    <div class="card-header">Insurance Details</div>
                    <div class="card-body">
                        <div class="meta-grid">
                            <div class="meta-item">
                                <div class="label">Provider</div>
                                <div class="value"><?= e($insurance['provider_name'] ?? '') ?></div>
                            </div>

                            <div class="meta-item">
                                <div class="label">Plan Name</div>
                                <div class="value"><?= e($insurance['plan_name'] ?? '') ?></div>
                            </div>

                            <div class="meta-item">
                                <div class="label">Coverage Start</div>
                                <div class="value"><?= e($insurance['start_date'] ?? '') ?></div>
                            </div>

                            <div class="meta-item">
                                <div class="label">Coverage End</div>
                                <div class="value"><?= e($insurance['end_date'] ?? '') ?></div>
                            </div>

                            <div class="meta-item">
                                <div class="label">Coverage Amount</div>
                                <div class="value"><?= e($insurance['coverage_amount'] ?? '') ?></div>
                            </div>

                            <div class="meta-item">
                                <div class="label">Destination</div>
                                <div class="value"><?= e($insurance['destination'] ?? '') ?></div>
                            </div>
                        </div>

                        <?php if (!empty($guests)): ?>
                            <div class="section-block">
                                <div class="card-header" style="padding-left:0;padding-right:0;border:0;">Covered Guests</div>
                                <div class="list">
                                    <?php foreach ($guests as $i => $guest): ?>
                                        <div class="list-card">
                                            <div class="list-title">Guest <?= $i + 1 ?></div>
                                            <div class="mini-grid">
                                                <div>
                                                    <div class="label">Name</div>
                                                    <div class="value"><?= e($guest['full_name']) ?></div>
                                                </div>
                                                <div>
                                                    <div class="label">Type</div>
                                                    <div class="value"><?= e($guest['type']) ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (in_array($service['service_type'], ['reception', 'guide', 'transfer', 'other'], true) && $detail): ?>
                <div class="card">
                    <div class="card-header">Service Details</div>
                    <div class="card-body">
                        <div class="meta-grid">
                            <div class="meta-item">
                                <div class="label">Service</div>
                                <div class="value"><?= e($detail['title'] ?? '') ?></div>
                            </div>

                            <div class="meta-item">
                                <div class="label">Location</div>
                                <div class="value"><?= e($detail['location'] ?? '') ?></div>
                            </div>

                            <div class="meta-item">
                                <div class="label">Start Date</div>
                                <div class="value"><?= e($detail['start_date'] ?? '') ?></div>
                            </div>

                            <div class="meta-item">
                                <div class="label">End Date</div>
                                <div class="value"><?= e($detail['end_date'] ?? '') ?></div>
                            </div>

                            <div class="meta-item" style="grid-column: 1 / -1;">
                                <div class="label">Participants</div>
                                <div class="value"><?= e($detail['participants'] ?? '') ?></div>
                            </div>
                        </div>

                        <?php if (!empty($detail['details'])): ?>
                            <div class="section-block">
                                <div class="card-header" style="padding-left:0;padding-right:0;border:0;">Details</div>
                                <div class="notes"><?= e($detail['details']) ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($service['notes'])): ?>
                <div class="card">
                    <div class="card-header">Notes</div>
                    <div class="card-body">
                        <div class="notes"><?= e($service['notes']) ?></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div>
            <div class="card">
                <div class="card-header">Financial Summary</div>
                <div class="card-body">
                    <div class="price-summary">
                        <div class="price-row">
                            <span>Total Amount</span>
                            <span><?= money_fmt($service['total_amount'], $service['currency']) ?></span>
                        </div>

                        <div class="price-row">
                            <span>Cost Amount</span>
                            <span><?= money_fmt($service['cost_amount'], $service['currency']) ?></span>
                        </div>

                        <div class="price-row">
                            <span>Profit</span>
                            <span><?= money_fmt($profit, $service['currency']) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">Quick Info</div>
                <div class="card-body">
                    <div class="meta-grid">
                        <div class="meta-item">
                            <div class="label">Service ID</div>
                            <div class="value">#<?= (int)$service['id'] ?></div>
                        </div>

                        <div class="meta-item">
                            <div class="label">Type</div>
                            <div class="value"><?= e($serviceTypes[$service['service_type']] ?? strtoupper($service['service_type'])) ?></div>
                        </div>

                        <div class="meta-item">
                            <div class="label">Updated At</div>
                            <div class="value"><?= e($service['updated_at']) ?></div>
                        </div>

                        <div class="meta-item">
                            <div class="label">Agency ID</div>
                            <div class="value"><?= e($service['agency_id'] ?: '-') ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($service['service_type'] === 'hotel' && $hotel): ?>
                <div class="card">
                    <div class="card-header">Hotel Summary</div>
                    <div class="card-body">
                        <div class="meta-grid">
                            <div class="meta-item">
                                <div class="label">Hotel</div>
                                <div class="value"><?= e($hotel['hotel_name']) ?></div>
                            </div>

                            <div class="meta-item">
                                <div class="label">Stars</div>
                                <div class="value"><?= str_repeat('★', max(0, (int)$hotel['stars'])) ?></div>
                            </div>

                            <div class="meta-item">
                                <div class="label">Rooms Count</div>
                                <div class="value"><?= count($hotelRooms) ?: e($hotel['rooms']) ?></div>
                            </div>

                            <div class="meta-item">
                                <div class="label">Guests Count</div>
                                <div class="value"><?= count($guests) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>
</body>
</html>
