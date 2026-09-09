<?php
declare(strict_types=1);

require __DIR__ . '/../inc/auth.php';
require_login();
require __DIR__ . '/../inc/db.php';

if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('money_fmt')) {
    function money_fmt($amount, string $currency = 'BRL'): string {
        return e($currency) . ' ' . number_format((float)$amount, 2, ',', '.');
    }
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die('Invalid service ID');
}

$stmt = $pdo->prepare("
    SELECT
        s.*,
        c.name AS client_name,
        c.email AS client_email,
        c.phone AS client_phone,
        sup.name AS supplier_name
    FROM service_sales s
    LEFT JOIN clients c ON c.id = s.client_id AND c.agency_id = s.agency_id
    LEFT JOIN suppliers sup ON sup.id = s.supplier_id AND sup.agency_id = s.agency_id
    WHERE s.id = ? AND s.agency_id = ?
    LIMIT 1
");
$stmt->execute([$id, agency_id()]);
$service = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$service) {
    die('Service not found');
}

if (($service['service_type'] ?? '') !== 'hotel') {
    die('This print page currently supports hotel services only.');
}

$stmt = $pdo->prepare("SELECT * FROM service_hotels WHERE service_id = ? LIMIT 1");
$stmt->execute([$id]);
$hotel = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT * FROM service_hotel_rooms WHERE service_id = ? ORDER BY id ASC");
$stmt->execute([$id]);
$rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT * FROM service_guests WHERE service_id = ? ORDER BY id ASC");
$stmt->execute([$id]);
$guests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$profit = (float)$service['total_amount'] - (float)$service['cost_amount'];

$logo = 'https://kamaltur.com/wp-content/uploads/2025/05/cropped-KAMALTUR-LOGO-2.png';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Service Print</title>
<style>
  @page {
    size: A4;
    margin: 12mm;
  }

  * {
    box-sizing: border-box;
  }

  body {
    margin: 0;
    background: #eef3f8;
    font-family: Arial, Helvetica, sans-serif;
    color: #183b63;
  }

  .page {
    width: 210mm;
    min-height: 297mm;
    margin: 0 auto;
    background: #fff;
    padding: 12mm;
  }

  .top-actions {
    width: 210mm;
    margin: 16px auto 10px;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
  }

  .btn {
    border: 1px solid #cfdceb;
    background: #fff;
    color: #183b63;
    border-radius: 10px;
    padding: 10px 14px;
    text-decoration: none;
    font-weight: 700;
    cursor: pointer;
  }

  .btn-primary {
    background: #0d6efd;
    border-color: #0d6efd;
    color: #fff;
  }

  .header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 18px;
    border-bottom: 2px solid #e7eef7;
    padding-bottom: 14px;
  }

  .brand img {
    max-width: 180px;
    max-height: 65px;
    object-fit: contain;
  }

  .doc-title {
    text-align: right;
  }

  .doc-title h1 {
    margin: 0 0 6px;
    font-size: 26px;
    color: #16395f;
  }

  .doc-sub {
    font-size: 13px;
    color: #607792;
    line-height: 1.5;
  }

  .section {
    margin-bottom: 16px;
    border: 1px solid #d9e3ef;
    border-radius: 14px;
    overflow: hidden;
  }

  .section-header {
    background: #f7faff;
    border-bottom: 1px solid #d9e3ef;
    padding: 12px 14px;
    font-weight: 700;
    font-size: 16px;
  }

  .section-body {
    padding: 14px;
  }

  .grid-2,
  .grid-3,
  .grid-4 {
    display: grid;
    gap: 10px;
  }

  .grid-2 { grid-template-columns: 1fr 1fr; }
  .grid-3 { grid-template-columns: 1fr 1fr 1fr; }
  .grid-4 { grid-template-columns: 1fr 1fr 1fr 1fr; }

  .box {
    background: #f9fbfe;
    border: 1px solid #e1ebf5;
    border-radius: 10px;
    padding: 10px 12px;
  }

  .label {
    font-size: 11px;
    color: #6b8197;
    margin-bottom: 5px;
    text-transform: uppercase;
    letter-spacing: .3px;
  }

  .value {
    font-size: 14px;
    font-weight: 700;
    color: #16395f;
    line-height: 1.45;
    word-break: break-word;
  }

  .hotel-image {
    margin-bottom: 14px;
  }

  .hotel-image img {
    width: 100%;
    max-height: 210px;
    object-fit: cover;
    border-radius: 12px;
    border: 1px solid #dce7f3;
  }

  table {
    width: 100%;
    border-collapse: collapse;
  }

  th, td {
    border: 1px solid #dce7f3;
    padding: 10px;
    font-size: 13px;
    text-align: left;
    vertical-align: top;
  }

  th {
    background: #f7faff;
    color: #16395f;
  }

  .notes {
    white-space: pre-line;
    line-height: 1.7;
    font-size: 13px;
  }

  .totals {
    display: grid;
    grid-template-columns: 1fr;
    gap: 10px;
  }

  .total-row {
    display: flex;
    justify-content: space-between;
    gap: 14px;
    border: 1px solid #dce7f3;
    background: #f9fbfe;
    border-radius: 10px;
    padding: 12px 14px;
    font-weight: 700;
  }

  .footer-note {
    margin-top: 18px;
    font-size: 11px;
    color: #6b8197;
    text-align: center;
  }

  @media print {
    body {
      background: #fff;
    }

    .top-actions {
      display: none !important;
    }

    .page {
      width: auto;
      min-height: auto;
      margin: 0;
      padding: 0;
    }
  }
</style>
</head>
<body>

<div class="top-actions">
  <a class="btn" href="/services/show.php?id=<?= (int)$service['id'] ?>">Back</a>
  <button class="btn btn-primary" onclick="window.print()">Print</button>
</div>

<div class="page">

  <div class="header">
    <div class="brand">
      <img src="<?= e($logo) ?>" alt="KamalTur">
    </div>

    <div class="doc-title">
      <h1>Hotel Service Voucher</h1>
      <div class="doc-sub">
        Service ID: #<?= (int)$service['id'] ?><br>
        Reference: <?= e($service['reference'] ?: '-') ?><br>
        Status: <?= e(ucfirst((string)$service['status'])) ?>
      </div>
    </div>
  </div>

  <div class="section">
    <div class="section-header">Customer Information</div>
    <div class="section-body">
      <div class="grid-3">
        <div class="box">
          <div class="label">Client</div>
          <div class="value"><?= e($service['client_name'] ?: '-') ?></div>
        </div>
        <div class="box">
          <div class="label">Email</div>
          <div class="value"><?= e($service['client_email'] ?: '-') ?></div>
        </div>
        <div class="box">
          <div class="label">Phone</div>
          <div class="value"><?= e($service['client_phone'] ?: '-') ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="section">
    <div class="section-header">Hotel Information</div>
    <div class="section-body">

      <?php if (!empty($hotel['image'])): ?>
        <div class="hotel-image">
          <img src="<?= e($hotel['image']) ?>" alt="Hotel Image">
        </div>
      <?php endif; ?>

      <div class="grid-2">
        <div class="box">
          <div class="label">Hotel Name</div>
          <div class="value"><?= e($hotel['hotel_name'] ?? '-') ?></div>
        </div>
        <div class="box">
          <div class="label">Stars</div>
          <div class="value"><?= str_repeat('★', max(0, (int)($hotel['stars'] ?? 0))) ?></div>
        </div>
        <div class="box" style="grid-column: 1 / -1;">
          <div class="label">Address</div>
          <div class="value"><?= e($hotel['hotel_address'] ?? '-') ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="section">
    <div class="section-header">Stay Details</div>
    <div class="section-body">
      <div class="grid-4">
        <div class="box">
          <div class="label">Check-in</div>
          <div class="value"><?= e($hotel['checkin'] ?? '-') ?></div>
        </div>
        <div class="box">
          <div class="label">Check-out</div>
          <div class="value"><?= e($hotel['checkout'] ?? '-') ?></div>
        </div>
        <div class="box">
          <div class="label">Nights</div>
          <div class="value"><?= e($hotel['nights'] ?? '-') ?></div>
        </div>
        <div class="box">
          <div class="label">Rooms</div>
          <div class="value"><?= e($hotel['rooms'] ?? '-') ?></div>
        </div>
        <div class="box">
          <div class="label">Main Room Type</div>
          <div class="value"><?= e($hotel['room_type'] ?? '-') ?></div>
        </div>
        <div class="box">
          <div class="label">Meal Plan</div>
          <div class="value"><?= e($hotel['meal'] ?? '-') ?></div>
        </div>
        <div class="box">
          <div class="label">Supplier</div>
          <div class="value"><?= e($service['supplier_name'] ?: '-') ?></div>
        </div>
        <div class="box">
          <div class="label">Currency</div>
          <div class="value"><?= e($service['currency'] ?: 'BRL') ?></div>
        </div>
      </div>
    </div>
  </div>

  <?php if ($guests): ?>
    <div class="section">
      <div class="section-header">Guests</div>
      <div class="section-body">
        <table>
          <thead>
            <tr>
              <th style="width:70px;">#</th>
              <th>Name</th>
              <th style="width:180px;">Type</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($guests as $i => $guest): ?>
              <tr>
                <td><?= $i + 1 ?></td>
                <td><?= e($guest['full_name']) ?></td>
                <td><?= e($guest['type']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($rooms): ?>
    <div class="section">
      <div class="section-header">Room Details</div>
      <div class="section-body">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Room Type</th>
              <th>Guests</th>
              <th>Meal</th>
              <th>Beds</th>
              <th>Qty</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rooms as $i => $room): ?>
              <tr>
                <td><?= $i + 1 ?></td>
                <td><?= e($room['room_type']) ?></td>
                <td><?= e($room['guests']) ?></td>
                <td><?= e($room['meal']) ?></td>
                <td><?= e($room['beds']) ?></td>
                <td><?= e($room['quantity']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!empty($hotel['cancel_policy'])): ?>
    <div class="section">
      <div class="section-header">Cancellation Policy</div>
      <div class="section-body">
        <div class="notes"><?= e($hotel['cancel_policy']) ?></div>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!empty($service['notes'])): ?>
    <div class="section">
      <div class="section-header">Notes</div>
      <div class="section-body">
        <div class="notes"><?= e($service['notes']) ?></div>
      </div>
    </div>
  <?php endif; ?>

  <div class="section">
    <div class="section-header">Financial Summary</div>
    <div class="section-body">
      <div class="totals">
        <div class="total-row">
          <span>Total Amount</span>
          <span><?= money_fmt($service['total_amount'], (string)$service['currency']) ?></span>
        </div>
        <div class="total-row">
          <span>Cost Amount</span>
          <span><?= money_fmt($service['cost_amount'], (string)$service['currency']) ?></span>
        </div>
        <div class="total-row">
          <span>Profit</span>
          <span><?= money_fmt($profit, (string)$service['currency']) ?></span>
        </div>
      </div>
    </div>
  </div>

  <div class="footer-note">
    Generated on <?= date('d/m/Y H:i') ?>
  </div>

</div>
</body>
</html>
