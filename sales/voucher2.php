<?php
declare(strict_types=1);

require __DIR__ . '/../inc/auth.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$token = isset($_GET['token']) ? (string) $_GET['token'] : '';

$public_ok = ($id > 0 && $token !== '' && function_exists('check_public_token') && check_public_token($id, 'voucher', $token));
if (!$public_ok) {
    require_login();
}

if ($id <= 0) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo "<h1>404</h1><p>Parâmetro ID inválido.</p>";
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer-when-downgrade');
header('Cache-Control: no-store, max-age=0, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$invSt = $pdo->prepare("
  SELECT id, agency_id, invoice_number, pnr_code, travel_date, issue_date, status, currency, refund_rule, change_rule, total_amount, scope
    FROM invoices
   WHERE id=? " . (!$public_ok ? "AND agency_id=?" : "") . " LIMIT 1
");
$invSt->execute(!$public_ok ? [$id, agency_id()] : [$id]);
$inv = $invSt->fetch(PDO::FETCH_ASSOC);

if (!$inv) {
    http_response_code(404);
    echo "<h1>404</h1><p>Venda não encontrada.</p>";
    exit;
}

$agencyId = (int)($inv['agency_id'] ?? agency_id());
$agencySt = $pdo->prepare("SELECT id, name, fantasy_name, legal_name, email, phone, logo_path FROM agencies WHERE id=? LIMIT 1");
$agencySt->execute([$agencyId]);
$agency = $agencySt->fetch(PDO::FETCH_ASSOC) ?: [];

$ps = $pdo->prepare("
  SELECT name, ptype, ticket_no
    FROM passengers
   WHERE invoice_id=?
   ORDER BY id ASC
");
$ps->execute([$id]);
$passengers = $ps->fetchAll(PDO::FETCH_ASSOC);

$sg = $pdo->prepare("
  SELECT airline_code, flight_no, `origin`, `destination`, `class`, `baggage`, record_locator
    FROM segments
   WHERE invoice_id=?
   ORDER BY id ASC
");
$sg->execute([$id]);
$segments = $sg->fetchAll(PDO::FETCH_ASSOC);

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fmt_date_br($value): string {
    if (!$value) return '—';
    $ts = strtotime((string)$value);
    return $ts ? date('d/m/Y', $ts) : '—';
}

function fmt_ticket_header_date($value): string {
    if (!$value) return 'DATA NÃO DISPONÍVEL';
    $ts = strtotime((string)$value);
    if (!$ts) return 'DATA NÃO DISPONÍVEL';
    $weekdays = ['DOM', 'SEG', 'TER', 'QUA', 'QUI', 'SEX', 'SÁB'];
    $months = ['JAN', 'FEV', 'MAR', 'ABR', 'MAI', 'JUN', 'JUL', 'AGO', 'SET', 'OUT', 'NOV', 'DEZ'];
    $weekday = $weekdays[(int)date('w', $ts)] ?? '';
    $month = $months[(int)date('n', $ts) - 1] ?? '';
    return $weekday . ' ' . date('d', $ts) . ' ' . $month;
}

function passenger_type_label(?string $ptype): string {
    $ptype = strtolower(trim((string)$ptype));
    return match ($ptype) {
        'chd', 'child' => 'Criança',
        'inf', 'infant' => 'Bebê',
        default => 'Adulto',
    };
}

function airline_display_name(string $code): string {
    $code = strtoupper(trim($code));
    return match ($code) {
        'G3' => 'GOL LINHAS AEREAS',
        'JJ', 'LA' => 'LATAM AIRLINES',
        'AD' => 'AZUL LINHAS AEREAS',
        'TP' => 'TAP AIR PORTUGAL',
        'AA' => 'AMERICAN AIRLINES',
        'AF' => 'AIR FRANCE',
        'EK' => 'EMIRATES',
        'QR' => 'QATAR AIRWAYS',
        default => $code !== '' ? $code . ' AIRLINES' : 'COMPANHIA AÉREA',
    };
}

function airline_logo_path(string $code): string {
    $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', $code));
    if ($code === '') return '/assets/airlines/_default.png';
    $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $base = $docRoot && is_dir($docRoot) ? $docRoot : dirname(__DIR__, 1);
    foreach (["/assets/airlines/logo/{$code}.png", "/assets/airlines/{$code}.png", "/assets/airlines/{$code}.gif", "/assets/airlines/{$code}.jpg"] as $rel) {
        if (is_file($base . $rel)) return $rel;
    }
    return '/assets/airlines/_default.png';
}

function company_logo_path(array $agency): string {
    $logo = trim((string)($agency['logo_path'] ?? ''));
    return $logo !== '' ? $logo : '/assets/img/kamaltur.png';
}

$agencyName = trim((string)($agency['fantasy_name'] ?? $agency['name'] ?? $agency['legal_name'] ?? 'KAMAL TUR'));
$agencyEmail = trim((string)($agency['email'] ?? ''));
$agencyPhone = trim((string)($agency['phone'] ?? ''));
$agencyLogo = company_logo_path($agency);
$mainAirlineCode = trim((string)($segments[0]['airline_code'] ?? ''));
$mainAirlineLogo = airline_logo_path($mainAirlineCode);
$mainAirlineName = airline_display_name($mainAirlineCode);
$displayUser = trim((string)($_SESSION['name'] ?? ''));
if ($displayUser === '') {
    $displayUser = $agencyName;
}

$ticketStatus = in_array(strtolower(trim((string)($inv['status'] ?? ''))), ['pago', 'paid'], true) ? 'Emitido' : 'Em processamento';
$ticketNumbers = array_values(array_filter(array_map(static fn(array $p): string => trim((string)($p['ticket_no'] ?? '')), $passengers)));
$primaryTicket = $ticketNumbers[0] ?? 'Pendente de emissão';
$headerTravelDate = fmt_ticket_header_date($inv['travel_date'] ?? '');

$generalNotes = [
    'Confirme se nome, número do bilhete, localizador e trechos correspondem exatamente ao documento do passageiro.',
    'Apresente-se no aeroporto com a antecedência exigida pela companhia aérea e com a documentação necessária.',
    'A franquia de bagagem pode variar por companhia, tarifa e trecho. Em caso de dúvida, confirme antes do embarque.',
    'Mudanças operacionais da companhia aérea podem ocorrer após a emissão. Acompanhe atualizações do voo.',
];
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Bilhete Aéreo - Itinerário do Passageiro</title>
  <style>
    :root{
      --bg:#eceef1;
      --paper:#ffffff;
      --ink:#414141;
      --ink-strong:#2a2d31;
      --muted:#6e7176;
      --line:#cfd1d4;
      --line-soft:#dfe2e6;
      --panel:#e6e8eb;
      --panel-strong:#dde1e6;
      --arrow:#52555a;
      --accent:#2f3338;
      --chip:#f6f7f8;
      --shadow:0 14px 42px rgba(25, 31, 38, 0.10);
      --radius:18px;
    }
    *{box-sizing:border-box}
    html,body{min-height:100%}
    body{
      margin:0;
      background:var(--bg);
      color:var(--ink);
      font-family:"Aptos","Segoe UI","Helvetica Neue",Arial,sans-serif;
      font-size:14px;
      padding:18px;
    }
    .sheet{
      width:min(1160px, 100%);
      margin:0 auto;
      background:var(--paper);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      padding:18px 18px 22px;
    }
    .top-code{
      display:flex;
      align-items:flex-end;
      gap:18px;
      padding:0 0 6px;
      border-bottom:3px solid #3f4144;
      color:var(--ink-strong);
      margin-bottom:8px;
    }
    .top-code .label{
      font-size:18px;
      text-transform:uppercase;
      letter-spacing:.01em;
    }
    .top-code .value{
      font-size:17px;
      font-weight:400;
    }
    .departure-line{
      display:flex;
      align-items:center;
      gap:10px;
      margin-bottom:12px;
      color:var(--ink-strong);
    }
    .plane-icon{
      width:68px;
      text-align:center;
      font-size:56px;
      line-height:1;
      color:#3f4144;
      transform:rotate(-8deg);
    }
    .departure-title{
      font-size:18px;
      line-height:1.15;
    }
    .departure-title strong{
      font-size:21px;
      letter-spacing:0;
    }
    .departure-title span{
      color:var(--muted);
      font-weight:400;
    }
    .cards{
      display:grid;
      gap:20px;
    }
    .segment-card{
      display:grid;
      grid-template-columns:356px 1fr;
      gap:8px;
      align-items:stretch;
    }
    .segment-left{
      background:linear-gradient(180deg, var(--panel) 0%, var(--panel-strong) 100%);
      padding:12px 16px 18px;
      min-height:306px;
      position:relative;
      border-radius:16px 0 0 16px;
      display:flex;
      flex-direction:column;
    }
    .segment-left::after{
      content:"";
      position:absolute;
      left:0;
      bottom:0;
      border-left:22px solid #fff;
      border-top:22px solid transparent;
    }
    .segment-airline{
      font-size:25px;
      color:var(--ink-strong);
      line-height:1.05;
      margin-bottom:6px;
      text-transform:uppercase;
    }
    .segment-flight{
      font-size:21px;
      font-weight:700;
      color:var(--ink-strong);
      margin-bottom:22px;
    }
    .ticket-data{
      display:grid;
      grid-template-columns:1fr;
      gap:14px;
      margin-top:auto;
    }
    .segment-left .label{
      font-size:15px;
      color:#4d5157;
      margin-bottom:2px;
    }
    .segment-left .value{
      font-size:16px;
      line-height:1.25;
      color:#4d5157;
      font-weight:600;
    }
    .ticket-row{
      display:grid;
      grid-template-columns:1fr 1fr;
      gap:14px;
    }
    .ticket-block{
      min-width:0;
    }
    .segment-right{
      border:2px solid var(--line);
      background:#fff;
      min-height:306px;
      display:grid;
      grid-template-columns:minmax(0, 1fr) 264px;
      border-radius:0 16px 16px 0;
      overflow:hidden;
    }
    .route-grid{
      border-right:1px dotted var(--line);
      display:grid;
      grid-template-rows:auto 1fr;
    }
    .route-top{
      display:grid;
      grid-template-columns:1fr 42px 1fr;
      align-items:center;
      padding:10px 16px 12px;
      border-bottom:1px dotted var(--line);
    }
    .airport-box{
      min-width:0;
    }
    .airport-code{
      font-size:23px;
      font-weight:500;
      color:var(--ink-strong);
      line-height:1;
    }
    .airport-name{
      font-size:17px;
      color:#4a4d52;
      margin-top:4px;
      text-transform:uppercase;
      line-height:1.15;
    }
    .route-arrow{
      text-align:center;
      font-size:22px;
      color:var(--arrow);
    }
    .route-bottom{
      display:grid;
      grid-template-columns:1fr 1fr;
      min-height:146px;
    }
    .time-col{
      padding:12px 16px;
    }
    .time-col + .time-col{
      border-left:1px dotted var(--line);
    }
    .time-label{
      font-size:18px;
      color:#4d5157;
      margin-bottom:4px;
    }
    .time-value{
      font-size:21px;
      color:var(--ink-strong);
      margin-bottom:16px;
      line-height:1;
    }
    .terminal-label{
      font-size:17px;
      color:#4d5157;
      margin-bottom:3px;
    }
    .terminal-value{
      font-size:17px;
      color:#4d5157;
      line-height:1.2;
    }
    .aircraft-box{
      padding:10px 16px;
      border-left:1px dotted var(--line);
      background:linear-gradient(180deg, #fff 0%, #fafafb 100%);
      display:flex;
      flex-direction:column;
      gap:14px;
    }
    .aircraft-label{
      font-size:15px;
      color:#4d5157;
      margin-bottom:2px;
    }
    .aircraft-value{
      font-size:16px;
      color:#4d5157;
      line-height:1.2;
      text-transform:uppercase;
      font-weight:700;
    }
    .aircraft-group{
      min-width:0;
    }
    .pax-table{
      width:100%;
      margin-top:22px;
      border-collapse:collapse;
      border:1px solid var(--line-soft);
      border-radius:14px;
      overflow:hidden;
      box-shadow:0 8px 24px rgba(25, 31, 38, 0.05);
    }
    .pax-table th{
      background:linear-gradient(180deg, #ececef 0%, #dfe1e5 100%);
      color:#42454a;
      text-align:left;
      padding:7px 10px;
      font-size:16px;
      font-weight:400;
    }
    .pax-table td{
      padding:8px 10px;
      font-size:16px;
      color:#4a4d52;
      border-left:1px dotted var(--line);
      border-top:1px solid #edf0f2;
      vertical-align:top;
    }
    .pax-table td:first-child,
    .pax-table th:first-child{
      border-left:0;
    }
    .passenger-name{
      line-height:1.45;
      font-weight:500;
    }
    .ticket-col{
      white-space:nowrap;
      color:var(--accent);
      font-weight:600;
    }
    .mobile-issued-by{
      display:none;
    }
    .notes{
      margin-top:18px;
      color:#c53929;
      font-size:11px;
      line-height:1.55;
      padding:14px 16px 6px;
      border:1px solid #f0d3cf;
      border-radius:14px;
      background:linear-gradient(180deg, #fff 0%, #fff9f8 100%);
    }
    .notes ul{
      margin:0;
      padding:0;
      list-style:none;
    }
    .notes li{
      padding:2px 0;
      border-bottom:1px solid #ececec;
      font-weight:700;
    }
    .notes li:last-child{
      border-bottom:0;
    }
    .footer{
      margin-top:14px;
      text-align:center;
      color:#49505a;
      font-size:14px;
      font-weight:600;
      letter-spacing:.02em;
    }
    @media (max-width: 980px){
      .sheet{
        padding:14px;
        border-radius:14px;
      }
      .segment-card{
        grid-template-columns:1fr;
      }
      .segment-left,
      .segment-right{
        border-radius:16px;
      }
      .segment-right{
        grid-template-columns:1fr;
      }
      .route-grid{
        border-right:0;
        border-bottom:1px dotted var(--line);
      }
      .pax-table th,
      .pax-table td{
        font-size:14px;
      }
    }
    @media (max-width: 720px){
      body{padding:10px}
      .sheet{
        padding:12px;
      }
      .meta-strip{
        gap:8px;
      }
      .plane-icon{width:48px;font-size:44px}
      .departure-title{font-size:18px}
      .departure-title strong{font-size:20px}
      .segment-left{min-height:auto}
      .ticket-row{
        grid-template-columns:1fr;
        gap:10px;
      }
      .route-top,
      .route-bottom{
        grid-template-columns:1fr;
      }
      .route-top{
        gap:8px;
      }
      .route-arrow{
        transform:rotate(90deg);
      }
      .time-col + .time-col{
        border-left:0;
        border-top:1px dotted var(--line);
      }
      .pax-table{
        display:block;
        overflow:auto;
      }
    }
    @media print{
      body{padding:0;background:#fff}
      .sheet{
        width:100%;
        border-radius:0;
        box-shadow:none;
        padding:0;
      }
    }
  </style>
</head>
<body>
  <div class="sheet">
    <div class="top-code">
      <div class="label">CÓDIGO DA RESERVA</div>
      <div class="value"><?= e($inv['pnr_code'] ?: '—') ?></div>
    </div>

    <div class="departure-line">
      <div class="plane-icon">✈</div>
      <div class="departure-title">
        SAÍDA: <strong><?= e($headerTravelDate) ?></strong>
        <span>Por favor, verifique o horário efetivo da decolagem dos voos.</span>
      </div>
    </div>

    <div class="cards">
	      <?php if ($segments): ?>
		        <?php foreach ($segments as $index => $seg): ?>
		          <?php $segmentTicket = $ticketNumbers[$index] ?? $primaryTicket; ?>
		          <section class="segment-card">
		            <div class="segment-left">
		              <div class="segment-airline"><?= e(airline_display_name((string)($seg['airline_code'] ?? $mainAirlineCode))) ?></div>
		              <div class="segment-flight"><?= e(trim(((string)$seg['airline_code']) . ' ' . ((string)$seg['flight_no']))) ?></div>
                  <div class="ticket-data">
                    <div class="ticket-row">
                      <div class="ticket-block">
                        <div class="label">Status:</div>
                        <div class="value"><?= e($ticketStatus) ?></div>
                      </div>
                      <div class="ticket-block">
                        <div class="label">Localizador:</div>
                        <div class="value"><?= e($inv['pnr_code'] ?: 'Não disponível') ?></div>
                      </div>
                    </div>

                    <div class="ticket-row">
                      <div class="ticket-block">
                        <div class="label">Família de tarifas:</div>
                        <div class="value"><?= e($seg['class'] ?: 'Não disponível') ?></div>
                      </div>
                      <div class="ticket-block">
                        <div class="label">Bilhete eletrônico:</div>
                        <div class="value"><?= e($segmentTicket) ?></div>
                      </div>
                    </div>

                    <div class="ticket-row">
                      <div class="ticket-block">
                        <div class="label">Cabine:</div>
                        <div class="value"><?= e($seg['class'] ?: 'Não disponível') ?></div>
                      </div>
                      <div class="ticket-block">
                        <div class="label">Emissão:</div>
                        <div class="value"><?= e(fmt_date_br($inv['issue_date'] ?? '')) ?></div>
                      </div>
                    </div>

                    <div class="ticket-row">
                      <div class="ticket-block">
                        <div class="label">Franquia:</div>
                        <div class="value"><?= e($seg['baggage'] ?: 'Não disponível') ?></div>
                      </div>
                      <div class="ticket-block">
                        <div class="label">Emitido por:</div>
                        <div class="value"><?= e($displayUser) ?></div>
                      </div>
                    </div>
                  </div>
	            </div>

            <div class="segment-right">
              <div class="route-grid">
                <div class="route-top">
                  <div class="airport-box">
                    <div class="airport-code"><?= e($seg['origin'] ?: '---') ?></div>
                    <div class="airport-name">ORIGEM</div>
                  </div>
                  <div class="route-arrow">▶</div>
                  <div class="airport-box">
                    <div class="airport-code"><?= e($seg['destination'] ?: '---') ?></div>
                    <div class="airport-name">DESTINO</div>
                  </div>
                </div>

                <div class="route-bottom">
                  <div class="time-col">
                    <div class="time-label">Partindo às:</div>
                    <div class="time-value">Não disponível</div>
                    <div class="terminal-label">Terminal:</div>
                    <div class="terminal-value">Não disponível</div>
                  </div>
                  <div class="time-col">
                    <div class="time-label">Chegando às:</div>
                    <div class="time-value">Não disponível</div>
                    <div class="terminal-label">Terminal:</div>
                    <div class="terminal-value">Não disponível</div>
                  </div>
                </div>
              </div>

	              <div class="aircraft-box">
                    <div class="aircraft-group">
	                  <div class="aircraft-label">Aeronave:</div>
	                  <div class="aircraft-value">Não disponível</div>
                    </div>
                    <div class="aircraft-group">
	                  <div class="aircraft-label">Agência:</div>
	                  <div class="aircraft-value"><?= e($agencyName) ?></div>
                    </div>
                    <div class="aircraft-group">
	                  <div class="aircraft-label">Contato:</div>
	                  <div class="aircraft-value"><?= e($agencyPhone !== '' ? $agencyPhone : ($agencyEmail !== '' ? $agencyEmail : 'Não disponível')) ?></div>
                    </div>
	              </div>
	            </div>
	          </section>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <table class="pax-table">
	      <thead>
	        <tr>
	          <th style="width:37%">Nome do passageiro:</th>
	          <th style="width:14%">Assentos:</th>
	          <th style="width:49%">Recibo(s) de Bilhete(s) Eletrônico(s):</th>
	        </tr>
	      </thead>
	      <tbody>
	        <?php if ($passengers): ?>
	          <?php foreach ($passengers as $p): ?>
	            <tr>
	              <td class="passenger-name">» <?= e($p['name']) ?></td>
	              <td>Não disponível</td>
	              <td class="ticket-col"><?= e(trim((string)($p['ticket_no'] ?? '')) !== '' ? (string)$p['ticket_no'] : 'Pendente de emissão') ?></td>
	            </tr>
	          <?php endforeach; ?>
	        <?php else: ?>
	          <tr>
	            <td colspan="3">Nenhum passageiro cadastrado.</td>
	          </tr>
	        <?php endif; ?>
	      </tbody>
	    </table>

    <div class="notes">
      <ul>
        <?php foreach ($generalNotes as $note): ?>
          <li><?= e($note) ?></li>
        <?php endforeach; ?>
        <?php if (trim((string)($inv['refund_rule'] ?? '')) !== ''): ?>
          <li><?= e((string)$inv['refund_rule']) ?></li>
        <?php endif; ?>
        <?php if (trim((string)($inv['change_rule'] ?? '')) !== ''): ?>
          <li><?= e((string)$inv['change_rule']) ?></li>
        <?php endif; ?>
      </ul>
    </div>

    <div class="footer">E-mail automático, não responda esse e-mail</div>
  </div>
</body>
</html>
