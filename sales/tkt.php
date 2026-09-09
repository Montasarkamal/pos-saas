<?php
/**
 * Filename: /sales/bilhete.php
 * Bilhete / Itinerário — impressão A4 + mobile — KamalTur
 */

declare(strict_types=1);

require __DIR__ . '/../inc/auth.php';

// ------------------------------
// 1) Access control
// ------------------------------
$id    = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$token = isset($_GET['token']) ? (string) $_GET['token'] : '';

$public_ok = ($id > 0 && $token !== '' && function_exists('check_public_token') && check_public_token($id, 'voucher', $token));
if (!$public_ok) { require_login(); }

// ------------------------------
// 2) Security headers
// ------------------------------
header('Content-Type: text/html; charset=UTF-8');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer-when-downgrade');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// ------------------------------
// 3) Fetch data
// ------------------------------
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

// ------------------------------
// 4) Helpers
// ------------------------------
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function fmt_date_br($d): string {
  $d = trim((string)$d);
  if ($d === '') return '—';
  $ts = strtotime($d);
  return $ts ? date('d/m/Y', $ts) : '—';
}

function fmt_time_hm($d): string {
  $d = trim((string)$d);
  if ($d === '') return '—';
  $ts = strtotime($d);
  return $ts ? date('H:i', $ts) : '—';
}

function pick_first(array $row, array $keys): string {
  foreach ($keys as $k) {
    if (array_key_exists($k, $row) && trim((string)$row[$k]) !== '') return (string)$row[$k];
  }
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

// ------------------------------
// 5) Render
// ------------------------------
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Bilhete / Itinerário — KamalTur</title>
  <style>
    @page { size: A4; margin: 10mm; }

    :root{
      --ink:#0f172a;
      --muted:#475569;
      --muted2:#64748b;
      --line:#e2e8f0;
      --soft:#f8fafc;
      --bg:#f1f5f9;
      --card:#ffffff;
      --accent:#111827;
      --danger:#b91c1c;
    }

    *{ box-sizing:border-box; }
    html,body{ height:100%; }
    body{
      margin:0;
      font-family: Arial, Helvetica, sans-serif;
      color:var(--ink);
      background:var(--bg);
      font-size:10.5px;
      line-height:1.35;
      -webkit-font-smoothing: antialiased;
      text-rendering: geometricPrecision;
    }

    /* Container A4 */
    .page{
      width: 190mm;
      margin: 0 auto;
      padding: 0;
    }

    /* Header */
    .topbar{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap:12px;
      padding: 12px 0 10px 0;
      border-bottom: 2px solid var(--accent);
    }
    .brand{
      display:flex;
      flex-direction:column;
      gap:4px;
      min-width: 0;
    }
    .brand img{
      height: 34px;
      max-width: 100%;
      object-fit: contain;
    }
    .brand small{
      color:var(--muted2);
      font-size:8.5px;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
      max-width: 92mm;
    }

    .pnr{
      text-align:right;
      min-width: 0;
    }
    .pnr .lbl{
      font-size:8px;
      letter-spacing:.08em;
      color:var(--muted2);
      font-weight:700;
      text-transform:uppercase;
    }
    .pnr .val{
      font-size:18px;
      font-weight:900;
      letter-spacing:.06em;
      line-height:1.1;
      overflow-wrap:anywhere;
    }

    /* Info strip */
    .strip{
      margin-top: 10px;
      background: var(--soft);
      border: 1px solid var(--line);
      border-radius: 6px;
      padding: 10px 12px;
      display:grid;
      grid-template-columns: minmax(0,2fr) minmax(0,1fr) minmax(0,1fr);
      gap: 10px;
    }
    .kv{ min-width:0; }
    .kv .k{
      font-size:7.8px;
      color:var(--muted2);
      text-transform:uppercase;
      letter-spacing:.06em;
      font-weight:700;
    }
    .kv .v{
      font-size:10.5px;
      font-weight:800;
      margin-top:2px;
      overflow-wrap:anywhere;
      max-width: 100%;
    }
    .kv.right{ text-align:right; }

    /* Segment card */
    .seg{
      margin-top: 12px;
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: 10px;
      overflow: hidden;
      page-break-inside: avoid;
    }
    .seg-h{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap:10px;
      padding: 8px 12px;
      background: #fff;
      border-bottom: 1px dashed var(--line);
    }
    .seg-h .left{
      display:flex;
      align-items:center;
      gap:8px;
      min-width:0;
    }
    .air-logo{
      width: 34px;
      height: 18px;
      object-fit: contain;
      flex: 0 0 auto;
    }
    .flight{
      font-weight:900;
      color: var(--danger);
      font-size:11px;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
      max-width: 115mm;
    }
    .seg-h .date{
      font-size:9px;
      font-weight:800;
      color:var(--muted);
      white-space:nowrap;
    }

    .seg-b{
      display:grid;
      grid-template-columns: minmax(0,1fr) 130px minmax(0,1fr);
      gap: 10px;
      padding: 12px;
      align-items: start;
    }

    .loc{
      min-width:0;
      overflow:hidden;
    }
    .loc .iata{
      margin:0;
      font-size:24px;
      font-weight:900;
      line-height:1;
      letter-spacing:.02em;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }
    .loc .city{
      display:block;
      margin-top:3px;
      font-size:11px;
      font-weight:800;
      color:var(--ink);
      overflow-wrap:anywhere;
      max-width: 100%;
    }
    .loc .apt{
      display:block;
      margin-top:2px;
      font-size:8.7px;
      color:var(--muted);
      overflow-wrap:anywhere;
      max-width: 100%;
    }

    .mid{
      display:flex;
      flex-direction:column;
      align-items:center;
      justify-content:center;
      text-align:center;
      gap:6px;
      color:var(--muted2);
      min-width:0;
    }
    .mid .status{
      font-size:8px;
      text-transform:uppercase;
      letter-spacing:.06em;
      font-weight:800;
      color: var(--muted);
    }
    .mid .line{
      width:100%;
      position:relative;
      border-bottom: 1px solid var(--line);
      margin: 10px 0;
    }
    .mid .line::after{
      content:'✈';
      position:absolute;
      right:-2px;
      top:-12px;
      font-size: 18px; /* أكبر */
      color:#cbd5e1;
    }
    .mid .meta{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      justify-content:center;
      font-size:8px;
      font-weight:800;
      text-transform:uppercase;
      letter-spacing:.05em;
    }

    .times{
      margin-top: 8px;
      display:flex;
      gap:12px;
      flex-wrap:wrap;
      border-top: 1px solid #f3f4f6;
      padding-top: 6px;
    }
    .tbox{
      min-width: 74px;
    }
    .tbox .k{
      font-size:7.5px;
      color:var(--muted2);
      text-transform:uppercase;
      letter-spacing:.06em;
      font-weight:700;
    }
    .tbox .v{
      font-size:14px;
      font-weight:900;
      margin-top:1px;
      line-height:1.1;
    }
    .tbox .s{
      font-size:8px;
      color:var(--muted);
      margin-top:2px;
      overflow-wrap:anywhere;
    }

    .seg-f{
      display:grid;
      grid-template-columns: repeat(4, minmax(0,1fr));
      gap: 10px;
      padding: 10px 12px;
      background: #fafafa;
      border-top: 1px solid var(--line);
    }

    /* Bottom info */
    .bottom{
      margin-top: 14px;
      display:grid;
      grid-template-columns: minmax(0,1.5fr) minmax(0,1fr);
      gap: 14px;
      padding-top: 12px;
      border-top: 1px solid var(--line);
    }
    .box{
      background: #fff;
      border: 1px solid var(--line);
      border-radius: 10px;
      padding: 10px 12px;
      page-break-inside: avoid;
    }
    .box h4{
      margin:0 0 8px 0;
      font-size:9px;
      text-transform:uppercase;
      letter-spacing:.08em;
      color:var(--ink);
      border-bottom: 1px solid #eef2f7;
      padding-bottom:6px;
    }
    .box p{
      margin:4px 0;
      font-size:8.8px;
      color:var(--muted);
      line-height:1.45;
      overflow-wrap:anywhere;
    }
    .list p{ margin:3px 0; }

    .footer{
      margin: 14px 0 60px 0;
      text-align:center;
      color:#94a3b8;
      font-size:8px;
      border-top: 1px solid #e5e7eb;
      padding-top: 10px;
      line-height:1.4;
    }

    /* Print button */
    .no-print{
      position: fixed;
      bottom: 18px;
      right: 18px;
      z-index: 9999;
    }
    .btn{
      padding: 11px 16px;
      background: #111;
      color: #fff;
      border: none;
      border-radius: 10px;
      cursor: pointer;
      font-weight: 900;
      box-shadow: 0 10px 22px rgba(0,0,0,.20);
      font-size: 12px;
    }
    .btn:active{ transform: translateY(1px); }

    /* Mobile */
    @media (max-width: 820px){
      body{ font-size:11px; }
      .page{ width: 100%; padding: 10px; }
      .topbar{ padding: 10px 0; }
      .strip{ grid-template-columns: 1fr; }
      .seg-b{ grid-template-columns: 1fr; }
      .mid .line{ margin: 8px 0 6px; }
      .seg-f{ grid-template-columns: 1fr 1fr; }
      .bottom{ grid-template-columns: 1fr; }
      .pnr .val{ font-size: 16px; }
      .brand small{ max-width: 100%; }
      .flight{ max-width: 100%; }
      .no-print{ left: 10px; right: 10px; }
      .btn{ width: 100%; }
    }

    @media print{
      body{ background:#fff; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
      .no-print{ display:none !important; }
      .page{ width: 190mm; }
    }
  </style>
</head>

<body>
  <div class="page">

    <div class="topbar">
      <div class="brand">
        <img src="https://kamaltur.com/KAMALTUR.png" alt="KamalTur" onerror="this.style.display='none'">
        <small>KAMAL TUR — www.kamaltur.com</small>
      </div>

      <div class="pnr">
        <div class="lbl">CÓDIGO DE RESERVA</div>
        <div class="val"><?= e($pnr ?: '—') ?></div>
      </div>
    </div>

    <div class="strip">
      <div class="kv">
        <div class="k">Passageiro Principal</div>
        <div class="v"><?= e($main_pax) ?></div>
      </div>
      <div class="kv">
        <div class="k">Data de Emissão</div>
        <div class="v"><?= e(fmt_date_br($issue_date)) ?></div>
      </div>
      <div class="kv right">
        <div class="k">ID Documento</div>
        <div class="v">#<?= e($invoice_no ?: (string)$id) ?></div>
      </div>
    </div>

    <?php if (!$segments): ?>
      <div class="box" style="margin-top:12px;">
        <h4>Voos</h4>
        <p>Nenhum segmento encontrado para esta venda.</p>
      </div>
    <?php endif; ?>

    <?php foreach ($segments as $idx => $seg): ?>
      <?php
        $air = (string)($seg['airline_code'] ?? '');
        $fn  = trim((string)($seg['flight_no'] ?? ''));
        $orig = trim((string)($seg['origin'] ?? ''));
        $dest = trim((string)($seg['destination'] ?? ''));

        // tenta achar data/horário em vários campos possíveis no segments
        $dep_raw = pick_first($seg, ['departure_at','depart_at','dep_at','departure_time','depart_time','dep_time','saida','departure']);
        $arr_raw = pick_first($seg, ['arrival_at','arrive_at','arr_at','arrival_time','arrive_time','arr_time','chegada','arrival']);

        // se dep_raw/arr_raw não tem data, عادي: fmt_time_hm قد يرجع —
        $dep_hm = $dep_raw ? fmt_time_hm($dep_raw) : '—';
        $arr_hm = $arr_raw ? fmt_time_hm($arr_raw) : '—';

        // date label: prefer segment date, else invoice travel_date, else —
        $seg_date_raw = pick_first($seg, ['travel_date','flight_date','departure_date','data','date']);
        $date_label = fmt_date_br($seg_date_raw ?: $travel_date);

        $class = trim((string)($seg['class'] ?? ''));
        $bagg  = trim((string)($seg['baggage'] ?? ''));
        $loc   = trim((string)($seg['record_locator'] ?? ''));
        $termD = trim((string)($seg['terminal_departure'] ?? $seg['terminal'] ?? $seg['terminal_from'] ?? ''));
        $termA = trim((string)($seg['terminal_arrival'] ?? $seg['terminal_to'] ?? ''));

        $st = trim((string)($seg['status'] ?? 'OK'));
        $is_direct = (isset($seg['stops']) && (string)$seg['stops'] !== '' && (int)$seg['stops'] > 0) ? 'Com paradas' : 'Voo direto';
      ?>

      <div class="seg">
        <div class="seg-h">
          <div class="left">
            <?php $logo = airline_logo_path($air); ?>
            <?php if ($logo): ?>
              <img class="air-logo" src="<?= e($logo) ?>" alt="<?= e($air) ?>" onerror="this.style.display='none'">
            <?php endif; ?>
            <div class="flight">
              VOO <?= e($air ?: '—') ?> <?= e($fn ?: '') ?>
            </div>
          </div>
          <div class="date"><?= e($date_label) ?></div>
        </div>

        <div class="seg-b">
          <div class="loc">
            <h2 class="iata"><?= e($orig ?: '—') ?></h2>
            <span class="city"><?= e($orig ?: 'Origem') ?></span>
            <span class="apt">Aeroporto de Origem</span>

            <div class="times">
              <div class="tbox">
                <div class="k">Saída</div>
                <div class="v"><?= e($dep_hm) ?></div>
                <div class="s"><?= $termD ? ('Terminal: ' . e($termD)) : 'Terminal: —' ?></div>
              </div>
            </div>
          </div>

          <div class="mid">
            <div class="status">Confirmado</div>
            <div class="line"></div>
            <div class="meta">
              <span><?= e($is_direct) ?></span>
              <span>•</span>
              <span>Status: <?= e($st ?: 'OK') ?></span>
            </div>
          </div>

          <div class="loc" style="text-align:right;">
            <h2 class="iata"><?= e($dest ?: '—') ?></h2>
            <span class="city"><?= e($dest ?: 'Destino') ?></span>
            <span class="apt">Aeroporto de Destino</span>

            <div class="times" style="justify-content:flex-end;">
              <div class="tbox" style="text-align:right;">
                <div class="k">Chegada</div>
                <div class="v"><?= e($arr_hm) ?></div>
                <div class="s"><?= $termA ? ('Terminal: ' . e($termA)) : 'Terminal: —' ?></div>
              </div>
            </div>
          </div>
        </div>

        <div class="seg-f">
          <div class="kv">
            <div class="k">Classe</div>
            <div class="v"><?= e($class ?: 'Economy') ?></div>
          </div>
          <div class="kv">
            <div class="k">Bagagem</div>
            <div class="v"><?= e($bagg ?: '0 PC') ?></div>
          </div>
          <div class="kv">
            <div class="k">Assento</div>
            <div class="v">SOB CHECK-IN</div>
          </div>
          <div class="kv right">
            <div class="k">Localizador Cia</div>
            <div class="v"><?= e($loc ?: ($pnr ?: '—')) ?></div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>

    <div class="bottom">
      <div class="box">
        <h4>Informações de Embarque</h4>
        <p>• Apresente-se no balcão da Cia Aérea com 3h de antecedência para voos internacionais.</p>
        <p>• Verifique a validade do seu passaporte e vistos necessários para o destino.</p>

        <div style="margin-top:10px;">
          <h4 style="margin-top:12px;">Lista de Passageiros</h4>
          <div class="list">
            <?php if (!$passengers): ?>
              <p>• Nenhum passageiro cadastrado.</p>
            <?php else: ?>
              <?php foreach ($passengers as $p): ?>
                <p>• <?= e($p['name'] ?? '') ?> (Tkt: <?= e(($p['ticket_no'] ?? '') ?: 'Pendente') ?>)</p>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="box">
        <h4>Regras e Serviços</h4>
        <p><strong>Alterações:</strong> <?= e(($inv['change_rule'] ?? '') ?: 'Sob consulta.') ?></p>
        <p><strong>Reembolso:</strong> <?= e(($inv['refund_rule'] ?? '') ?: 'Sob consulta.') ?></p>

        <?php if ($aux_services): ?>
          <div style="margin-top:10px;">
            <h4 style="margin-top:12px;">Serviços Extras</h4>
            <div class="list">
              <?php foreach ($aux_services as $aux): ?>
                <p>• <?= e($aux['service'] ?? '') ?></p>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="footer">
      KAMAL TUR — WhatsApp: +55 (61) 99393-2819 — kamaltur@kamaltur.com.br<br>
      Documento eletrônico. O transporte está sujeito às normas da IATA e da Cia Aérea.
    </div>

    <div class="no-print">
      <button class="btn" onclick="window.print()">🖨️ IMPRIMIR A4</button>
    </div>

  </div>
</body>
</html>
