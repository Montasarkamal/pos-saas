<?php
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/db.php';
require_login();

header('Location: /sales/index.php');
exit;

function h($v) {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function brl($v) {
    return 'R$ ' . number_format((float)$v, 2, ',', '.');
}

function ymd_to_br($date) {
    if (!$date) return '';
    $ts = strtotime($date);
    return $ts ? date('d/m/Y', $ts) : $date;
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

$status = $_GET['status'] ?? '';
$type   = $_GET['type'] ?? '';
$q      = trim($_GET['q'] ?? '');

$where = [];
$params = [':agency_id' => agency_id()];
$where[] = "ss.agency_id = :agency_id";

if ($status !== '') {
    $where[] = "ss.status = :status";
    $params[':status'] = $status;
}

if ($type !== '') {
    $where[] = "ss.service_type = :type";
    $params[':type'] = $type;
}

if ($q !== '') {
    $where[] = "(
        c.name LIKE :q
        OR ss.reference LIKE :q
        OR sh.hotel_name LIKE :q
        OR sc.company_name LIKE :q
        OR si.provider_name LIKE :q
        OR sd.title LIKE :q
    )";
    $params[':q'] = "%{$q}%";
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "
SELECT
    ss.id,
    ss.service_type,
    ss.reference,
    ss.total_amount,
    ss.cost_amount,
    ss.currency,
    ss.status,
    ss.created_at,
    c.name AS client_name,
    s.name AS supplier_name,
    sh.hotel_name,
    sh.checkin,
    sh.checkout,
    sc.company_name,
    sc.pickup_date,
    sc.return_date,
    si.provider_name,
    si.start_date AS insurance_start_date,
    si.end_date AS insurance_end_date,
    sd.title AS detail_title,
    sd.start_date AS detail_start_date,
    sd.end_date AS detail_end_date
FROM service_sales ss
LEFT JOIN clients c ON c.id = ss.client_id AND c.agency_id = ss.agency_id
LEFT JOIN suppliers s ON s.id = ss.supplier_id AND s.agency_id = ss.agency_id
LEFT JOIN service_hotels sh ON sh.service_id = ss.id AND sh.agency_id = ss.agency_id
LEFT JOIN service_cars sc ON sc.service_id = ss.id AND sc.agency_id = ss.agency_id
LEFT JOIN service_insurance si ON si.service_id = ss.id AND si.agency_id = ss.agency_id
LEFT JOIN service_details sd ON sd.service_id = ss.id AND sd.agency_id = ss.agency_id
{$whereSql}
ORDER BY ss.id DESC
LIMIT 200
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$kpiSql = "
SELECT
    COUNT(*) AS total_services,
    SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed_services,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_services,
    SUM(total_amount) AS total_sales,
    SUM(cost_amount) AS total_cost
FROM service_sales
WHERE agency_id = ?
";
$kpiStmt = $pdo->prepare($kpiSql);
$kpiStmt->execute([agency_id()]);
$kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC);

$totalSales = (float)($kpi['total_sales'] ?? 0);
$totalCost  = (float)($kpi['total_cost'] ?? 0);
$totalProfit = $totalSales - $totalCost;

function status_badge_class($status) {
    $status = strtolower(trim((string)$status));
    if ($status === 'confirmed') return 'badge-confirmed';
    if ($status === 'pending') return 'badge-pending';
    if ($status === 'cancelled') return 'badge-cancelled';
    if ($status === 'refunded') return 'badge-refunded';
    return 'badge-default';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Services</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: #f5f7fb;
            color: #16395f;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
        }
        .title {
            font-size: 28px;
            font-weight: 700;
            margin: 0;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 11px 16px;
            border-radius: 10px;
            border: 1px solid #d9e3ef;
            background: #fff;
            color: #16395f;
            text-decoration: none;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
        }
        .btn-primary {
            background: #0d6efd;
            color: #fff;
            border-color: #0d6efd;
        }
        .btn-danger {
            background: #dc3545;
            color: #fff;
            border-color: #dc3545;
        }
        .btn-sm {
            padding: 8px 12px;
            font-size: 12px;
            border-radius: 8px;
        }
        .grid-kpi {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 14px;
            margin-bottom: 20px;
        }
        .kpi {
            background: #fff;
            border: 1px solid #d9e3ef;
            border-radius: 16px;
            padding: 18px;
        }
        .kpi-label {
            font-size: 12px;
            color: #6a7f96;
            margin-bottom: 8px;
        }
        .kpi-value {
            font-size: 24px;
            font-weight: 700;
        }
        .card {
            background: #fff;
            border: 1px solid #d9e3ef;
            border-radius: 16px;
            margin-bottom: 20px;
            overflow: hidden;
        }
        .card-head {
            padding: 16px 18px;
            border-bottom: 1px solid #e8eef5;
            font-size: 18px;
            font-weight: 700;
        }
        .filters {
            padding: 16px 18px;
            display: grid;
            grid-template-columns: 1.2fr 1fr 1fr auto;
            gap: 12px;
            border-bottom: 1px solid #e8eef5;
        }
        .filters input,
        .filters select {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #d9e3ef;
            border-radius: 10px;
            background: #fff;
        }
        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1050px;
        }
        th, td {
            padding: 14px 16px;
            border-bottom: 1px solid #eef2f7;
            text-align: left;
            vertical-align: middle;
            font-size: 14px;
        }
        th {
            background: #f9fbfe;
            color: #6a7f96;
            font-weight: 700;
            position: sticky;
            top: 0;
        }
        tr:hover td {
            background: #fbfdff;
        }
        .badge {
            display: inline-block;
            padding: 7px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }
        .badge-confirmed { background: #dcfce7; color: #166534; }
        .badge-pending   { background: #fef3c7; color: #92400e; }
        .badge-cancelled { background: #fee2e2; color: #991b1b; }
        .badge-refunded  { background: #e0e7ff; color: #3730a3; }
        .badge-default   { background: #e5e7eb; color: #374151; }

        .type-chip {
            display: inline-block;
            padding: 6px 10px;
            background: #eef4ff;
            color: #1d4ed8;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .empty {
            padding: 30px 18px;
            color: #6a7f96;
        }
        @media (max-width: 1100px) {
            .grid-kpi {
                grid-template-columns: repeat(2, 1fr);
            }
            .filters {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<div class="container">

    <div class="topbar">
        <h1 class="title">Service Sales</h1>
        <a href="/services/create.php" class="btn btn-primary">+ New Service</a>
    </div>

    <div class="grid-kpi">
        <div class="kpi">
            <div class="kpi-label">Total Services</div>
            <div class="kpi-value"><?= (int)($kpi['total_services'] ?? 0) ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Confirmed</div>
            <div class="kpi-value"><?= (int)($kpi['confirmed_services'] ?? 0) ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Pending</div>
            <div class="kpi-value"><?= (int)($kpi['pending_services'] ?? 0) ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Sales</div>
            <div class="kpi-value"><?= brl($totalSales) ?></div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Profit</div>
            <div class="kpi-value"><?= brl($totalProfit) ?></div>
        </div>
    </div>

    <div class="card">
        <div class="card-head">Service List</div>

        <form method="get" class="filters">
            <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search by client, reference, hotel...">

            <select name="type">
                <option value="">All Types</option>
                <?php foreach ($serviceTypes as $value => $label): ?>
                    <option value="<?= h($value) ?>" <?= $type === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>

            <select name="status">
                <option value="">All Status</option>
                <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="confirmed" <?= $status === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                <option value="refunded" <?= $status === 'refunded' ? 'selected' : '' ?>>Refunded</option>
            </select>

            <button class="btn" type="submit">Filter</button>
        </form>

        <?php if (!$rows): ?>
            <div class="empty">No services found.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Type</th>
                        <th>Client</th>
                        <th>Hotel / Reference</th>
                        <th>Supplier</th>
                        <th>Dates</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th style="width:220px;">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td>#<?= (int)$r['id'] ?></td>
                            <td><span class="type-chip"><?= h($serviceTypes[$r['service_type']] ?? $r['service_type']) ?></span></td>
                            <td><?= h($r['client_name']) ?></td>
                            <td>
                                <?php
                                    $serviceTitle = $r['hotel_name'] ?: ($r['company_name'] ?: ($r['provider_name'] ?: ($r['detail_title'] ?: $r['reference'])));
                                ?>
                                <?= h($serviceTitle ?: '-') ?>
                            </td>
                            <td><?= h($r['supplier_name']) ?></td>
                            <td>
                                <?php
                                    $dateStart = $r['checkin'] ?: ($r['pickup_date'] ?: ($r['insurance_start_date'] ?: $r['detail_start_date']));
                                    $dateEnd = $r['checkout'] ?: ($r['return_date'] ?: ($r['insurance_end_date'] ?: $r['detail_end_date']));
                                ?>
                                <?php if (!empty($dateStart) || !empty($dateEnd)): ?>
                                    <?= h(ymd_to_br($dateStart)) ?> → <?= h(ymd_to_br($dateEnd)) ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?= h($r['currency']) ?> <?= number_format((float)$r['total_amount'], 2, ',', '.') ?></td>
                            <td><span class="badge <?= status_badge_class($r['status']) ?>"><?= h($r['status']) ?></span></td>
                            <td><?= h(ymd_to_br(substr((string)$r['created_at'], 0, 10))) ?></td>
                            <td>
                                <div class="actions">
                                    <a class="btn btn-sm" href="/services/show.php?id=<?= (int)$r['id'] ?>">View</a>
                                    <?php if ($r['service_type'] === 'hotel'): ?>
                                        <a class="btn btn-sm" href="/services/print.php?id=<?= (int)$r['id'] ?>" target="_blank">Print</a>
                                    <?php endif; ?>
                                    <?php if ($r['service_type'] === 'hotel'): ?>
                                        <a class="btn btn-sm btn-primary" href="/services/edit.php?id=<?= (int)$r['id'] ?>">Edit</a>
                                    <?php endif; ?>
                                    <a class="btn btn-sm btn-danger"
                                       href="/services/delete.php?id=<?= (int)$r['id'] ?>"
                                       onclick="return confirm('Are you sure you want to delete this service?')">
                                       Delete
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>
</body>
</html>
