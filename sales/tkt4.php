<?php
/**
 * Filename: /sales/mobile_ticket.php
 * تصميم مخصص للجوال - Mobile Boarding Pass Style
 */

declare(strict_types=1);

require __DIR__ . '/../inc/auth.php'; 

// 1) التحقق من الوصول
$id    = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$token = isset($_GET['token']) ? (string) $_GET['token'] : '';

$public_ok = ($id > 0 && $token !== '' && function_exists('check_public_token') && check_public_token($id, 'voucher', $token));
if (!$public_ok) { require_login(); }

header('Content-Type: text/html; charset=UTF-8');

// 2) جلب البيانات
$invSt = $pdo->prepare("SELECT * FROM invoices WHERE id=? " . (!$public_ok ? "AND agency_id=?" : "") . " LIMIT 1");
$invSt->execute(!$public_ok ? [$id, agency_id()] : [$id]);
$inv = $invSt->fetch(PDO::FETCH_ASSOC);

if (!$inv) { die("Ticket não encontrado."); }

$ps = $pdo->prepare("SELECT name, ticket_no FROM passengers WHERE invoice_id=? ORDER BY id ASC");
$ps->execute([$id]);
$pax = $ps->fetchAll(PDO::FETCH_ASSOC);

$sg = $pdo->prepare("SELECT * FROM segments WHERE invoice_id=? ORDER BY id ASC");
$sg->execute([$id]);
$segments = $sg->fetchAll(PDO::FETCH_ASSOC);

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmt_date_mob($d){ return $d ? date('d M y', strtotime((string)$d)) : '—'; }
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Boarding Pass - <?= e($inv['pnr_code']) ?></title>
    <style>
        body {
            background-color: #eef2f5;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            margin: 0; padding: 20px 10px;
            display: flex; flex-direction: column; align-items: center;
        }

        .pass-container {
            width: 100%; max-width: 350px;
            background: white; border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            overflow: hidden; position: relative;
        }

        /* الجزء العلوي - الهيدر */
        .pass-header {
            background-color: #1a1a1a; color: white;
            padding: 20px; display: flex; justify-content: space-between; align-items: center;
        }
        .pass-header img { height: 25px; filter: brightness(0) invert(1); }
        .pass-header .pnr { text-align: right; }
        .pass-header .pnr span { font-size: 10px; opacity: 0.7; display: block; text-transform: uppercase; }
        .pass-header .pnr b { font-size: 18px; letter-spacing: 1px; }

        /* تفاصيل الرحلة */
        .pass-body { padding: 20px; }
        
        .flight-info { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .city-box { flex: 1; }
        .city-box.right { text-align: right; }
        .city-box h1 { font-size: 35px; margin: 0; font-weight: 800; color: #000; }
        .city-box span { font-size: 12px; color: #888; text-transform: uppercase; }

        .plane-icon { font-size: 24px; color: #ddd; flex: 0 0 50px; text-align: center; }

        /* شبكة المعلومات */
        .info-grid {
            display: grid; grid-template-columns: 1fr 1fr; row-gap: 20px;
            border-top: 1px dashed #eee; padding-top: 20px;
        }
        .info-item span { font-size: 10px; color: #888; text-transform: uppercase; display: block; }
        .info-item b { font-size: 14px; color: #000; }

        /* الفاصل المشرشر (التذكرة) */
        .pass-cut {
            height: 20px; background: #eef2f5; position: relative; margin: 10px 0;
            display: flex; align-items: center;
        }
        .pass-cut::before, .pass-cut::after {
            content: ''; width: 20px; height: 20px; background: #eef2f5; 
            border-radius: 50%; position: absolute;
        }
        .pass-cut::before { left: -10px; }
        .pass-cut::after { right: -10px; }
        .pass-cut .line { border-bottom: 2px dashed white; width: 100%; height: 0; }

        /* الجزء السفلي - الباركود */
        .pass-footer { padding: 0 20px 30px; text-align: center; }
        .barcode-area {
            background: #f9f9f9; padding: 20px; border-radius: 10px;
            margin-bottom: 15px; border: 1px solid #f0f0f0;
        }
        .pax-name { font-size: 14px; font-weight: bold; text-transform: uppercase; margin-bottom: 5px; display: block; }
        .ticket-no { font-size: 11px; color: #888; }

        .btn-print {
            margin-top: 20px; width: 100%; max-width: 350px;
            background: #000; color: #fff; border: none; padding: 15px;
            border-radius: 10px; font-weight: bold; cursor: pointer;
        }

        @media print { .btn-print { display: none; } }
    </style>
</head>
<body>

<?php foreach ($segments as $seg): ?>
<div class="pass-container" style="margin-bottom: 20px;">
    
    <div class="pass-header">
        <img src="https://kamaltur.com/KAMALTUR.png" alt="Logo">
        <div class="pnr">
            <span>Localizador</span>
            <b><?= e($inv['pnr_code']) ?></b>
        </div>
    </div>

    <div class="pass-body">
        <div class="flight-info">
            <div class="city-box">
                <h1><?= e($seg['origin']) ?></h1>
                <span>Origem</span>
            </div>
            <div class="plane-icon">✈</div>
            <div class="city-box right">
                <h1><?= e($seg['destination']) ?></h1>
                <span>Destino</span>
            </div>
        </div>

        <div class="info-grid">
            <div class="info-item">
                <span>Voo</span>
                <b><?= e($seg['airline_code']) ?> <?= e($seg['flight_no']) ?></b>
            </div>
            <div class="info-item" style="text-align: right;">
                <span>Data</span>
                <b><?= fmt_date_mob($inv['travel_date']) ?></b>
            </div>
            <div class="info-item">
                <span>Classe</span>
                <b><?= e($seg['class'] ?: 'Economy') ?></b>
            </div>
            <div class="info-item" style="text-align: right;">
                <span>Assento</span>
                <b>Check-in</b>
            </div>
            <div class="info-item">
                <span>Bagagem</span>
                <b><?= e($seg['baggage'] ?: 'Consultar') ?></b>
            </div>
            <div class="info-item" style="text-align: right;">
                <span>Status</span>
                <b style="color: green;">Confirmado</b>
            </div>
        </div>
    </div>

    <div class="pass-cut">
        <div class="line"></div>
    </div>

    <div class="pass-footer">
        <div class="barcode-area">
            <span class="pax-name"><?= e($pax[0]['name']) ?></span>
            <span class="ticket-no">TKT: <?= e($pax[0]['ticket_no'] ?: 'PENDENTE') ?></span>
            <div style="margin-top: 15px; font-size: 10px; color: #ccc;">DIGITAL BOARDING PASS</div>
        </div>
        <div style="font-size: 9px; color: #888;">Apresente este QR Code no portão de embarque.</div>
    </div>
</div>
<?php endforeach; ?>

<button class="btn-print" onclick="window.print()">Salvar como PDF</button>

<div style="margin-top: 20px; font-size: 10px; color: #888; text-align: center;">
    KAMAL TUR • Consultoria de Viagens<br>
    www.kamaltur.com
</div>

</body>
</html>
