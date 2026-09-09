<?php
/**
 * Filename: /sales/modern_ticket.php
 * تصميم عصري ومودرن لتذكرة الطيران
 */

declare(strict_types=1);
require __DIR__ . '/../inc/auth.php'; 

$id    = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$token = isset($_GET['token']) ? (string) $_GET['token'] : '';

$public_ok = ($id > 0 && $token !== '' && function_exists('check_public_token') && check_public_token($id, 'voucher', $token));
if (!$public_ok) { require_login(); }

header('Content-Type: text/html; charset=UTF-8');

$invSt = $pdo->prepare("SELECT * FROM invoices WHERE id=? " . (!$public_ok ? "AND agency_id=?" : "") . " LIMIT 1");
$invSt->execute(!$public_ok ? [$id, agency_id()] : [$id]);
$inv = $invSt->fetch(PDO::FETCH_ASSOC);
if (!$inv) { die("Venda não encontrada."); }

$ps = $pdo->prepare("SELECT name, ptype, ticket_no FROM passengers WHERE invoice_id=? ORDER BY id ASC");
$ps->execute([$id]);
$passengers = $ps->fetchAll(PDO::FETCH_ASSOC);

$sg = $pdo->prepare("SELECT * FROM segments WHERE invoice_id=? ORDER BY id ASC");
$sg->execute([$id]);
$segments = $sg->fetchAll(PDO::FETCH_ASSOC);

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function fmt_date_br($d){ return $d ? date('d/m/Y', strtotime((string)$d)) : '—'; }
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>E-Ticket - KAMALTUR</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-bg: #f5f7fa;
            --card-bg: white;
            --text-dark: #1a1a1a;
            --text-medium: #555;
            --text-light: #999;
            --accent-color: #000;
            --border-color: #e0e0e0;
            --shadow: 0 8px 25px rgba(0,0,0,0.08);
            --radius: 18px;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--primary-bg);
            margin: 0; padding: 20px;
            color: var(--text-dark);
            display: flex; flex-direction: column; align-items: center;
            min-height: 100vh;
        }

        .ticket-wrapper {
            width: 100%; max-width: 700px; /* مرن للجوال والويب */
            background: var(--card-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 30px;
        }

        /* الجزء العلوي - الشريحة السوداء */
        .ticket-header {
            background-color: var(--accent-color);
            color: white;
            padding: 20px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header-left { display: flex; align-items: center; gap: 10px; }
        .header-left img { height: 25px; filter: brightness(0) invert(1); }
        .header-left span { font-weight: 600; font-size: 16px; }

        .pnr-box { background: rgba(255,255,255,0.15); padding: 7px 15px; border-radius: 12px; }
        .pnr-box small { font-size: 9px; display: block; opacity: 0.7; }
        .pnr-box b { font-size: 16px; letter-spacing: 1px; }

        /* معلومات المسافر الأساسية */
        .ticket-info-bar {
            background: #fcfcfc;
            padding: 15px 25px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            color: var(--text-medium);
        }
        .info-item b { color: var(--text-dark); font-weight: 600; }

        /* تفاصيل الرحلة الأساسية */
        .flight-segment {
            padding: 30px 25px;
            display: grid;
            grid-template-columns: 1fr 60px 1fr; /* ثلاث أعمدة لمنع التداخل */
            align-items: center;
            gap: 15px;
            position: relative;
        }
        .flight-segment:not(:last-of-type)::after {
            content: '';
            position: absolute;
            left: 25px; right: 25px; bottom: 0;
            border-bottom: 1px dashed var(--border-color);
        }

        .location-details { flex: 1; }
        .location-details.destination { text-align: right; }

        .iata-code { font-size: 48px; font-weight: 700; margin: 0; line-height: 1; letter-spacing: -2px; }
        .city-full-name { font-size: 14px; color: var(--text-dark); margin-top: 5px; font-weight: 500; }
        .airport-full-name { 
            font-size: 10px; color: var(--text-medium); margin-top: 3px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap; 
        }

        .plane-icon { font-size: 24px; color: var(--border-color); text-align: center; }

        .time-date { 
            display: flex; gap: 20px; margin-top: 15px;
            font-size: 12px; color: var(--text-dark);
        }
        .time-date strong { display: block; font-weight: 600; font-size: 15px; margin-top: 3px; }
        .time-date .label { font-size: 9px; color: var(--text-light); text-transform: uppercase; }

        /* تفاصيل إضافية في الفوتر */
        .ticket-details-footer {
            background: #fafafa;
            padding: 20px 25px;
            border-top: 1px solid var(--border-color);
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr)); /* مرن للموبايل */
            gap: 15px 20px;
        }
        .detail-item small { font-size: 9px; color: var(--text-light); text-transform: uppercase; display: block; margin-bottom: 4px; }
        .detail-item b { font-size: 13px; font-weight: 600; color: var(--text-dark); }

        /* منطقة القواعد والتذييل */
        .rules-section {
            padding: 25px;
            font-size: 10px;
            color: var(--text-medium);
            line-height: 1.6;
            border-top: 1px solid var(--border-color);
        }
        .rules-section b { font-weight: 600; color: var(--text-dark); }
        .company-info { text-align: center; margin-top: 20px; font-size: 9px; color: var(--text-light); }
        
        .print-button {
            background-color: var(--accent-color);
            color: white;
            padding: 15px 25px;
            border: none;
            border-radius: var(--radius);
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
            margin-top: 30px;
            width: 100%; max-width: 300px;
        }

        /* تعديلات الطباعة */
        @media print {
            body { background-color: white !important; padding: 0 !important; }
            .ticket-wrapper { box-shadow: none !important; border: 1px solid #ddd; max-width: 100% !important; border-radius: 0 !important; }
            .print-button { display: none !important; }
            .ticket-header, .ticket-info-bar, .flight-segment, .ticket-details-footer, .rules-section { padding-left: 15mm !important; padding-right: 15mm !important; }
            .ticket-header { border-radius: 0 !important; }
        }

        /* تعديلات الجوال */
        @media (max-width: 500px) {
            body { padding: 15px 10px; }
            .ticket-header { padding: 15px 18px; }
            .header-left span { font-size: 14px; }
            .pnr-box b { font-size: 14px; }
            .ticket-info-bar { flex-direction: column; align-items: flex-start; gap: 8px; font-size: 11px; padding: 12px 18px; }
            .flight-segment { grid-template-columns: 1fr; padding: 25px 18px; }
            .location-details.destination { text-align: left; margin-top: 20px; }
            .plane-icon { display: none; }
            .iata-code { font-size: 38px; }
            .city-full-name { font-size: 13px; }
            .time-date { flex-wrap: wrap; gap: 10px; }
            .ticket-details-footer { padding: 15px 18px; gap: 10px; }
            .rules-section { padding: 18px; }
            .print-button { font-size: 14px; padding: 12px 20px; }
        }
    </style>
</head>
<body>

<div class="ticket-wrapper">
    <div class="ticket-header">
        <div class="header-left">
            <img src="https://kamaltur.com/KAMALTUR.png" alt="KAMALTUR Logo">
            <span>KAMALTUR</span>
        </div>
        <div class="pnr-box">
            <small>Localizador (PNR)</small>
            <b><?= e($inv['pnr_code']) ?></b>
        </div>
    </div>

    <div class="ticket-info-bar">
        <div class="info-item">Passageiro: <b><?= e($passengers[0]['name'] ?? 'N/A') ?></b></div>
        <div class="info-item">Emitido em: <b><?= fmt_date_br($inv['issue_date']) ?></b></div>
    </div>

    <?php foreach ($segments as $seg): ?>
    <div class="flight-segment">
        <div class="location-details origin">
            <h2 class="iata-code"><?= e($seg['origin']) ?></h2>
            <p class="city-full-name">Aeroporto de Origem</p>
            <p class="airport-full-name">Consulte os monitores do aeroporto para o terminal de embarque.</p>
            <div class="time-date">
                <div>
                    <span class="label">Saída</span>
                    <strong>--:--</strong>
                </div>
                <div>
                    <span class="label">Data</span>
                    <strong><?= fmt_date_br($inv['travel_date']) ?></strong>
                </div>
            </div>
        </div>

        <div class="plane-icon">✈</div>

        <div class="location-details destination">
            <h2 class="iata-code"><?= e($seg['destination']) ?></h2>
            <p class="city-full-name">Aeroporto de Destino</p>
            <p class="airport-full-name">Horário local previsto para a chegada.</p>
            <div class="time-date" style="justify-content: flex-end;">
                <div>
                    <span class="label">Chegada</span>
                    <strong>--:--</strong>
                </div>
                <div>
                    <span class="label">Status</span>
                    <strong style="color: #28a745;">Confirmado</strong>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="ticket-details-footer">
        <div class="detail-item">
            <small>Cia Aérea</small>
            <b><?= e($segments[0]['airline_code'] ?? 'N/A') ?></b>
        </div>
        <div class="detail-item">
            <small>Voo</small>
            <b><?= e($segments[0]['flight_no'] ?? 'N/A') ?></b>
        </div>
        <div class="detail-item">
            <small>Classe</small>
            <b><?= e($segments[0]['class'] ?: 'Economy') ?></b>
        </div>
        <div class="detail-item">
            <small>Bagagem</small>
            <b><?= e($segments[0]['baggage'] ?: 'Consultar') ?></b>
        </div>
        <div class="detail-item">
            <small>Assento</small>
            <b>Check-in</b>
        </div>
        <div class="detail-item">
            <small>Localizador Cia</small>
            <b><?= e($segments[0]['record_locator'] ?: $inv['pnr_code']) ?></b>
        </div>
    </div>

    <div class="rules-section">
        <b>Informações importantes:</b><br>
        • Leve um documento oficial com foto e o <b>localizador (PNR)</b> indicado acima.<br>
        • Horários de voo podem mudar. Consulte o status no site da companhia aérea antes do embarque.<br>
        • Bagagem e assentos seguem as regras da tarifa. Alterações e reembolsos:
        <b><?= e($inv['refund_rule'] ?: '—') ?></b> / <b><?= e($inv['change_rule'] ?: '—') ?></b>.<br>
        • Em caso de urgência, WhatsApp: <b>(61) 993932819</b>.
        <div class="company-info">
            KAMAL TUR • <a href="https://www.kamaltur.com" style="color: inherit; text-decoration: none;">www.kamaltur.com</a> • <a href="mailto:kamaltur@kamaltur.com" style="color: inherit; text-decoration: none;">kamaltur@kamaltur.com</a>
        </div>
    </div>
</div>

<button class="print-button" onclick="window.print()">IMPRIMIR / SALVAR PDF</button>

</body>
</html>
