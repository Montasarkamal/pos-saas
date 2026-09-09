<?php
/**
 * Filename: /sales/bilhete.php
 * Bilhete / Itinerário — Premium Minimalist Design — KamalTur
 */

declare(strict_types=1);

require __DIR__ . '/../inc/auth.php';

// 1) Access control
$id    = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$token = isset($_GET['token']) ? (string) $_GET['token'] : '';

$public_ok = ($id > 0 && $token !== '' && function_exists('check_public_token') && check_public_token($id, 'voucher', $token));
if (!$public_ok) { require_login(); }

// 2) Security headers
header('Content-Type: text/html; charset=UTF-8');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// 3) Fetch data
$invSt = $pdo->prepare("SELECT * FROM invoices WHERE id=? " . (!$public_ok ? "AND agency_id=?" : "") . " LIMIT 1");
$invSt->execute(!$public_ok ? [$id, agency_id()] : [$id]);
$inv = $invSt->fetch(PDO::FETCH_ASSOC);
if (!$inv) { http_response_code(404); die("Venda não encontrada."); }

$ps = $pdo->prepare("SELECT name, ptype, ticket_no FROM passengers WHERE invoice_id=? ORDER BY id ASC");
$ps->execute([$id]);
$passengers = $ps->fetchAll(PDO::FETCH_ASSOC);

$sg = $pdo->prepare("SELECT * FROM segments WHERE invoice_id=? ORDER BY id ASC");
$sg->execute([$id]);
$segments = $sg->fetchAll(PDO::FETCH_ASSOC);

$ax = $pdo->prepare("SELECT code, service, value FROM aux_services WHERE invoice_id=? ORDER BY id ASC");
$ax->execute([$id]);
$aux_services = $ax->fetchAll(PDO::FETCH_ASSOC);

// 4) Helpers
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function fmt_date_br($d): string {
    if (!$d) return '—';
    $ts = strtotime((string)$d);
    return $ts ? date('d/m/Y', $ts) : '—';
}

function fmt_time_hm($d): string {
    if (!$d) return '—';
    $ts = strtotime((string)$d);
    return $ts ? date('H:i', $ts) : '—';
}

function pick_first(array $row, array $keys): string {
    foreach ($keys as $k) { if (!empty($row[$k])) return (string)$row[$k]; }
    return '';
}

function airline_logo_path(string $code): string {
    $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', $code));
    return $code ? "/assets/airlines/{$code}.png" : '';
}

$pnr = (string)($inv['pnr_code'] ?? '');
$issue_date = (string)($inv['issue_date'] ?? '');
$travel_date = (string)($inv['travel_date'] ?? '');
$invoice_no = (string)($inv['invoice_number'] ?? '');
$main_pax = $passengers[0]['name'] ?? 'N/A';
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Voucher de Viagem — <?= e($pnr) ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap');

        :root {
            --primary: #0f172a;
            --accent: #1e40af;
            --bg: #f3f4f6;
            --card: #ffffff;
            --border: #e5e7eb;
            --text-main: #111827;
            --text-light: #6b7280;
        }

        * { box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background: var(--bg); margin: 0; padding: 20px; color: var(--text-main); -webkit-print-color-adjust: exact; }

        .page { max-width: 800px; margin: 0 auto; background: var(--card); border-radius: 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); overflow: hidden; }

        /* Header Area */
        .header { background: var(--primary); color: white; padding: 30px; display: flex; justify-content: space-between; align-items: center; }
        .logo img { height: 40px; filter: brightness(0) invert(1); }
        .pnr-box { text-align: right; }
        .pnr-box label { font-size: 10px; text-transform: uppercase; opacity: 0.7; display: block; letter-spacing: 1px; }
        .pnr-box span { font-size: 24px; font-weight: 800; letter-spacing: 1px; }

        /* Top Meta Info */
        .meta-strip { display: flex; padding: 20px 30px; border-bottom: 1px solid var(--border); background: #fafafa; gap: 40px; }
        .meta-item label { display: block; font-size: 10px; color: var(--text-light); text-transform: uppercase; font-weight: 700; margin-bottom: 4px; }
        .meta-item span { font-size: 14px; font-weight: 600; color: var(--text-main); }

        /* Flight Card */
        .segment { padding: 30px; border-bottom: 1px dashed var(--border); position: relative; }
        .segment:last-of-type { border-bottom: none; }

        .airline-row { display: flex; align-items: center; gap: 10px; margin-bottom: 25px; }
        .airline-row img { height: 22px; width: auto; }
        .airline-row .flight-no { font-weight: 700; font-size: 14px; color: var(--accent); }
        .airline-row .flight-date { font-weight: 600; color: var(--text-light); margin-left: auto; }

        .journey-grid { display: grid; grid-template-columns: 1fr 120px 1fr; align-items: center; text-align: center; }

        .location { text-align: left; }
        .location.dest { text-align: right; }

        .iata { font-size: 42px; font-weight: 900; line-height: 1; color: var(--primary); margin-bottom: 5px; }
        .city { font-size: 14px; font-weight: 700; display: block; }
        .airport { font-size: 11px; color: var(--text-light); display: block; margin-top: 2px; }
        .time-box { margin-top: 15px; background: var(--bg); display: inline-block; padding: 5px 12px; border-radius: 6px; }
        .time-box .time { font-size: 18px; font-weight: 800; color: var(--accent); }

        .route-path { position: relative; display: flex; flex-direction: column; align-items: center; }
        .route-path .line { width: 100%; height: 1px; border-top: 2px dotted var(--border); position: relative; top: 12px; }
        .route-path .plane { background: white; padding: 0 10px; z-index: 1; color: var(--border); font-size: 20px; }
        .route-path .duration { margin-top: 15px; font-size: 9px; font-weight: 700; color: var(--text-light); text-transform: uppercase; }

        /* Details Footer inside Card */
        .flight-details { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-top: 30px; padding-top: 20px; border-top: 1px solid #f9fafb; }

        /* Secondary Info Blocks */
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; padding: 30px; background: #f9fafb; }
        .info-card { background: white; border: 1px solid var(--border); border-radius: 12px; padding: 20px; }
        .info-card h4 { margin: 0 0 15px 0; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: var(--text-light); border-bottom: 1px solid var(--bg); padding-bottom: 8px; }
        
        .pax-table { width: 100%; border-collapse: collapse; }
        .pax-table td { padding: 8px 0; font-size: 13px; }
        .pax-table td:last-child { text-align: right; color: var(--text-light); font-family: monospace; }

        .footer { padding: 30px; text-align: center; color: var(--text-light); font-size: 11px; line-height: 1.6; }

        @media (max-width: 600px) {
            .meta-strip { flex-wrap: wrap; gap: 20px; }
            .journey-grid { grid-template-columns: 1fr; gap: 30px; }
            .location, .location.dest { text-align: center; }
            .flight-details { grid-template-columns: 1fr 1fr; gap: 15px; }
            .info-grid { grid-template-columns: 1fr; }
        }

        @media print {
            body { background: white; padding: 0; }
            .page { box-shadow: none; border-radius: 0; max-width: 100%; }
            .btn-print { display: none; }
        }

        .btn-print { position: fixed; bottom: 20px; right: 20px; background: var(--accent); color: white; border: none; padding: 12px 25px; border-radius: 30px; font-weight: 700; cursor: pointer; box-shadow: 0 5px 15px rgba(0,0,0,0.2); }
    </style>
</head>
<body>

<div class="page">
    <div class="header">
        <div class="logo">
            <img src="https://kamaltur.com/KAMALTUR.png" alt="KamalTur" onerror="this.style.display='none'">
        </div>
        <div class="pnr-box">
            <label>Localizador de Reserva</label>
            <span><?= e($pnr) ?></span>
        </div>
    </div>

    <div class="meta-strip">
        <div class="meta-item">
            <label>Passageiro Principal</label>
            <span><?= e($main_pax) ?></span>
        </div>
        <div class="meta-item">
            <label>Data de Emissão</label>
            <span><?= e(fmt_date_br($issue_date)) ?></span>
        </div>
        <div class="meta-item">
            <label>Documento</label>
            <span>#<?= e($invoice_no ?: $id) ?></span>
        </div>
    </div>

    <?php foreach ($segments as $seg): 
        $dep_at = pick_first($seg, ['departure_at','depart_at','saida']);
        $arr_at = pick_first($seg, ['arrival_at','arrive_at','chegada']);
    ?>
    <div class="segment">
        <div class="airline-row">
            <?php $logo = airline_logo_path((string)$seg['airline_code']); ?>
            <?php if($logo): ?><img src="<?= e($logo) ?>" alt=""><?php endif; ?>
            <span class="flight-no">Voo <?= e($seg['airline_code']) ?> <?= e($seg['flight_no']) ?></span>
            <span class="flight-date"><?= e(fmt_date_br(pick_first($seg, ['travel_date','flight_date']) ?: $travel_date)) ?></span>
        </div>

        <div class="journey-grid">
            <div class="location">
                <div class="iata"><?= e($seg['origin'] ?: '???') ?></div>
                <span class="city"><?= e($seg['origin_city'] ?? 'Origem') ?></span>
                <span class="airport">Aeroporto de Origem</span>
                <div class="time-box">
                    <span class="time"><?= e(fmt_time_hm($dep_at)) ?></span>
                </div>
                <?php if(!empty($seg['terminal_departure'])): ?>
                    <div style="font-size: 10px; margin-top:5px; font-weight:700;">TERMINAL <?= e($seg['terminal_departure']) ?></div>
                <?php endif; ?>
            </div>

            <div class="route-path">
                <span class="plane">✈</span>
                <div class="line"></div>
                <div class="duration"><?= (isset($seg['stops']) && (int)$seg['stops'] > 0) ? e($seg['stops']).' PARADA(S)' : 'VOO DIRETO' ?></div>
            </div>

            <div class="location dest">
                <div class="iata"><?= e($seg['destination'] ?: '???') ?></div>
                <span class="city"><?= e($seg['dest_city'] ?? 'Destino') ?></span>
                <span class="airport">Aeroporto de Destino</span>
                <div class="time-box">
                    <span class="time"><?= e(fmt_time_hm($arr_at)) ?></span>
                </div>
                <?php if(!empty($seg['terminal_arrival'])): ?>
                    <div style="font-size: 10px; margin-top:5px; font-weight:700;">TERMINAL <?= e($seg['terminal_arrival']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="flight-details">
            <div class="meta-item">
                <label>Classe</label>
                <span><?= e($seg['class'] ?? 'Economy') ?></span>
            </div>
            <div class="meta-item">
                <label>Bagagem</label>
                <span><?= e($seg['baggage'] ?? 'Check rules') ?></span>
            </div>
            <div class="meta-item">
                <label>Assento</label>
                <span>Check-in</span>
            </div>
            <div class="meta-item" style="text-align: right;">
                <label>Ref. Cia</label>
                <span><?= e($seg['record_locator'] ?: $pnr) ?></span>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="info-grid">
        <div class="info-card">
            <h4>Passageiros</h4>
            <table class="pax-table">
                <?php foreach ($passengers as $p): ?>
                <tr>
                    <td><strong><?= e($p['name']) ?></strong></td>
                    <td><?= e($p['ticket_no'] ?: 'Tkt pendente') ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <div class="info-card">
            <h4>Informações Adicionais</h4>
            <div class="meta-item" style="margin-bottom: 10px;">
                <label>Reembolso / Alterações</label>
                <span style="font-size: 12px;"><?= e($inv['refund_rule'] ?? 'Sob consulta') ?></span>
            </div>
            <?php if($aux_services): ?>
                <label style="font-size: 10px; font-weight: 700; color: var(--text-light); text-transform: uppercase;">Serviços Extras</label>
                <?php foreach ($aux_services as $aux): ?>
                    <div style="font-size: 12px; margin-top: 4px;">• <?= e($aux['service']) ?></div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="footer">
        <strong>KAMALTUR VIAGENS E TURISMO</strong><br>
        WhatsApp: +55 (61) 99393-2819 | kamaltur@kamaltur.com.br<br>
        Este documento é um resumo do itinerário. Apresente-se no balcão da Cia Aérea com antecedência.
    </div>
</div>

<button class="btn-print" onclick="window.print()">Imprimir Voucher</button>

</body>
</html>
