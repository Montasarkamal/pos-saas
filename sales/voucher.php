<?php
// =====================================================================
// sales/voucher.php — قسيمة سفر (Voucher) للمسافر
// تطوير: عرض "السعر الإجمالي" (Valor Total) أعلى معلومات الرحلة
// =====================================================================

declare(strict_types=1);

require __DIR__ . '/../inc/auth.php'; // يوفّر $pdo + الجلسة + check_public_token + require_login()

// ------------------------------
// 1) التقاط معاملات الرابط والتحقق من صلاحية التوكن
// ------------------------------
$id    = isset($_GET['id']) ? (int) $_GET['id'] : 0;
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

// ------------------------------
// 2) رؤوس أمان + تعطيل التخزين المؤقّت
// ------------------------------
header('Content-Type: text/html; charset=UTF-8');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer-when-downgrade');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header('Cache-Control: no-store, max-age=0, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// ------------------------------
// 3) جلب البيانات الأساسية
// ------------------------------
$invSt = $pdo->prepare("
  SELECT id, invoice_number, pnr_code, travel_date, issue_date,
         refund_rule, change_rule, currency
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

// === Serviços Auxiliares
$ax = $pdo->prepare("
  SELECT code, service, value
    FROM aux_services
   WHERE invoice_id=?
   ORDER BY id ASC
");
$ax->execute([$id]);
$aux_services = $ax->fetchAll(PDO::FETCH_ASSOC);

// ------------------------------
// 4) توابع مساعدة
// ------------------------------
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function fmt_date_br($d){
  if(!$d) return '—';
  $t = strtotime((string)$d);
  return $t ? date('d/m/Y',$t) : e($d);
}

function url_with_scheme(string $u): string {
  $u = trim($u);
  if ($u === '') return '#';
  if (preg_match('~^https?://~i', $u)) return $u;
  return 'https://' . ltrim($u, '/');
}

function money_br($n){ return 'R$ ' . number_format((float)$n, 2, ',', '.'); }

function airline_logo_path(string $code): string {
  $code = strtoupper(preg_replace('/[^A-Z0-9]/', '', $code));
  if ($code === '') return '/assets/airlines/_default.png';
  $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
  $base = $docRoot && is_dir($docRoot) ? $docRoot : dirname(__DIR__, 1);
  foreach (["/assets/airlines/{$code}.svg", "/assets/airlines/{$code}.png"] as $rel) {
    if (is_file($base . $rel)) return $rel;
  }
  return '/assets/airlines/_default.png';
}

/**
 * يرجّع أول عمود موجود من قائمة أعمدة (آمن لأنه يتحقق من INFORMATION_SCHEMA)
 */
function first_existing_column(PDO $pdo, string $table, array $candidates): ?string {
  if (!$candidates) return null;
  try {
    $db = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
    if ($db === '') return null;

    $in = implode(',', array_fill(0, count($candidates), '?'));
    $sql = "
      SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
       WHERE TABLE_SCHEMA = ?
         AND TABLE_NAME = ?
         AND COLUMN_NAME IN ($in)
       LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $st->execute(array_merge([$db, $table], $candidates));
    $col = $st->fetchColumn();
    return $col ? (string)$col : null;
  } catch (Throwable $e) {
    return null;
  }
}

// ------------------------------
// 5) حساب السعر الإجمالي (Valor Total)
//    أولوية: عمود إجمالي داخل invoices (إن وجد)
//    احتياطي: مجموع aux_services فقط
// ------------------------------
$auxTotal = 0.0;
foreach ($aux_services as $a) { $auxTotal += (float)($a['value'] ?? 0); }

// جرّب إيجاد عمود إجمالي في invoices (غيّر القائمة لو عندك اسم مختلف)
$totalCol = first_existing_column($pdo, 'invoices', [
  'total_amount',
  'grand_total',
  'total',
  'amount_total',
  'total_value',
  'valor_total'
]);

$invoiceTotal = 0.0;
if ($totalCol) {
  $st = $pdo->prepare("SELECT `$totalCol` FROM invoices WHERE id=? LIMIT 1");
  $st->execute([$id]);
  $invoiceTotal = (float)($st->fetchColumn() ?: 0);
}

// الاختيار النهائي: لو الإجمالي في invoices = 0 اعرض مجموع aux كحد أدنى
$grandTotal = ($invoiceTotal > 0) ? $invoiceTotal : $auxTotal;

// جمع أكواد شركات الطيران الفريدة لعرض الشريط
$airlineCodes = [];
foreach ($segments as $s) {
  $c = strtoupper(trim((string)($s['airline_code'] ?? '')));
  if ($c) { $airlineCodes[$c] = true; }
}
$airlineCodes = array_keys($airlineCodes);

?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <title>Voucher — KAMAL TUR</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/core@1.4.0/dist/css/tabler.min.css">
  <style>
  :root{
    --border:#98A2B3; --muted:#344054; --text:#0A0A0A;
    --fz-base:11px; --fz-key:9px; --fz-val:12px;
    --pad:6px; --radius:8px;
    --logo-air:64px; --canvas-w:800px; --footer-h:120px;
  }
  html,body{ margin:0; padding:0; }
  body{ color:var(--text); background:#fff; font-size:var(--fz-base); -webkit-font-smoothing:antialiased; text-rendering:geometricPrecision; overflow-x:hidden; }
  .k-canvas{ width:var(--canvas-w); margin:0 auto; min-height:100vh; box-sizing:border-box; padding-bottom:calc(var(--footer-h) + 12px); }
  .k-card{ border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; background:#fff; }
  .k-card .card-header{ background:#F9FAFB;color:#0F172A; border-bottom:1px solid var(--border); padding:var(--pad); }
  .k-card .card-body,.k-card .card-footer{ padding:var(--pad); }
  .k-key{ font-size:var(--fz-key); color:var(--muted); text-transform:uppercase; letter-spacing:.05em; margin-bottom:2px; }
  .k-value{ font-size:var(--fz-val); font-weight:700; }
  .k-muted{ color:var(--muted); font-size:var(--fz-base); }
  .k-brand{ display:flex; align-items:center; justify-content:space-between; gap:12px; }
  .k-company-logo{ width:180px; height:auto; object-fit:contain; }
  .k-air-strip{ display:flex; flex-wrap:wrap; gap:6px; align-items:center; }
  .k-air-badge{ display:inline-flex; align-items:center; gap:4px; padding:4px 6px; border:1px solid var(--border); border-radius:8px; background:#fff; }
  .k-air-logo{ width:var(--logo-air); height:auto; object-fit:contain; display:block; }
  .k-air-cell img{ width:var(--logo-air); height:auto; object-fit:contain; }
  table{ width:100%; border-collapse:separate; border-spacing:0; }
  thead th{ font-size:var(--fz-key); text-transform:uppercase; letter-spacing:.05em; color:var(--muted); background:#F9FAFB; border-bottom:1px solid var(--border); padding:4px 6px; text-align:left; }
  tbody td{ font-size:var(--fz-base); color:var(--text); padding:4px 6px; border-bottom:1px solid var(--border); vertical-align:middle; }

  /* إبراز إجمالي السعر */
  .k-total{
    display:flex; align-items:center; justify-content:space-between; gap:12px;
    padding:10px 12px; background:#F9FAFB;
    border:1px solid var(--border); border-radius:var(--radius);
  }
  .k-total .label{
    font-size:var(--fz-key); color:var(--muted);
    text-transform:uppercase; letter-spacing:.06em;
  }
  .k-total .amount{ font-size:18px; font-weight:800; line-height:1; }

  .k-footer-card{ position:fixed; left:50%; transform:translateX(-50%); bottom:0; width:var(--canvas-w); z-index:999; background:#fff; border-top:1px solid var(--border); border-left:1px solid var(--border); border-right:1px solid var(--border); border-radius:var(--radius) var(--radius) 0 0; box-shadow:0 -6px 16px rgba(16,24,40,.06); }
  .k-footer-card .card-header{ background:#F9FAFB; border-bottom:1px solid var(--border); padding:var(--pad); }
  .k-footer-card .card-body,.k-footer-card .card-footer{ padding:var(--pad); }

  @media print{
    .d-print-none{ display:none !important; }
    .k-footer-card{ position:static !important; transform:none !important; width:auto !important; box-shadow:none !important; border:1px solid var(--border); border-radius:var(--radius); margin-top:8px; page-break-inside:avoid; }
    .k-canvas{ width:auto !important; padding-bottom:0 !important; min-height:auto !important; }
    img, table{ max-width:100% !important; height:auto !important; }
  }
  
  .route-cell{
  display:flex;
  align-items:center;
  gap:6px;
  white-space:nowrap;
}
.route-code{
  font-weight:600;
}
.route-plane{
  display:inline-flex;
  align-items:center;
  color:#475569; /* رمادي أنيق */
}


  </style>
</head>
<body>
<div class="k-canvas">

  <div class="mb-3">
    <div class="k-brand">
      <div>
        <img class="k-company-logo" src="https://kamaltur.com/KAMALTUR.png" alt="Logo KAMAL TUR" loading="lazy">
      </div>
      <div class="k-air-strip">
  <?php if (!empty($airlineCodes)): ?>
    <?php foreach ($airlineCodes as $code): ?>
      <div class="k-air-badge">
        <img
          src="<?= e(airline_logo_path($code)) ?>"
          alt="Airline <?= e($code) ?>"
          class="k-air-logo"
          loading="lazy"
        >
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <div class="k-muted">—</div>
  <?php endif; ?>
</div>

    </div>
  </div>

  <div class="k-card card mb-3">
    <div class="card-header"><h3 class="card-title">Informações da Viagem</h3></div>
    <div class="card-body">

     

      <table class="k-trip-table">
        <thead>
          <tr>
            <th>Localizador (PNR)</th>
            <th>Data de Viagem</th>
            <th>Emitido em</th>
            <th>Valor total</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td><?= e($inv['pnr_code'] ?: '—') ?></td>
            <td><?= fmt_date_br($inv['travel_date']) ?></td>
            <td><?= fmt_date_br($inv['issue_date']) ?></td>
            <td> <div class="amount"><?= e(money_br($grandTotal)) ?></div> </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>


  <div class="k-card card mb-3">
    <div class="card-header"><h3 class="card-title">Passageiros</h3></div>
    <div class="table-responsive">
      <table class="table table-vcenter">
        <thead><tr><th>Nome</th><th>Tipo</th><th>Bilhete</th></tr></thead>
        <tbody>
          <?php if ($passengers): foreach ($passengers as $p): ?>
            <tr>
              <td class="text-uppercase"><?= e($p['name']) ?></td>
              <td><?= e($p['ptype']) ?></td>
              <td><?= e($p['ticket_no'] ?: '—') ?></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="3" class="text-secondary">—</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="k-card card mb-3">
    <div class="card-header"><h3 class="card-title">Detalhes do voo</h3></div>
    <div class="table-responsive">
      <table class="table table-vcenter">
        <thead>
          <tr>
            <th>Cia</th>
            <th>Nº voo</th>
            <th>Origem</th>
            <th></th>
            <th>Destino</th>
            <th>Classe</th>
            <th>Bagagem</th>
            <th>Localizador</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($segments): foreach ($segments as $s): ?>
            <tr>
              <td>
                <div class="k-air-cell">
                  <img src="<?= e(airline_logo_path((string)($s['airline_code'] ?? ''))) ?>" alt="Airline logo" loading="lazy">
                </div>
              </td>
              <td><?= e($s['flight_no']) ?></td>
              <td><?= e($s['origin']) ?></td>
             <td style="width:10px; text-align:center; padding:0;">
  <span style="font-size:30px; line-height:1; display:inline-block; ;">
    ✈
  </span>
</td>
              <td><?= e($s['destination']) ?></td>
              <td><?= e($s['class'] ?: '—') ?></td>
              <td><?= e($s['baggage'] ?: '—') ?></td>
              <td><?= e($s['record_locator'] ?: ($inv['pnr_code'] ?: '—')) ?></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="7" class="text-secondary">—</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if (!empty($aux_services)): ?>
    <div class="k-card card mb-3">
      <div class="card-header"><h3 class="card-title">Serviços Auxiliares</h3></div>
      <div class="table-responsive">
        <table class="table table-vcenter">
          <thead>
            <tr>
              <th style="width:140px;">Código</th>
              <th>Serviço</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($aux_services as $a): ?>
              <tr>
                <td><?= e($a['code'] ?: '—') ?></td>
                <td><?= e($a['service']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- (اختياري) عرض إجمالي الخدمات المساعدة داخل القسم -->
      <div class="card-footer d-flex justify-content-between">
        <span class="k-muted">Total (Serviços Auxiliares)</span>
        <b><?= e(money_br($auxTotal)) ?></b>
      </div>
    </div>
  <?php endif; ?>

  <div class="k-card card mb-3">
    <div class="card-header"><h3 class="card-title">Informações importantes</h3></div>
    <div class="card-body">
      <ul class="mb-0">
        <li>Leve um documento oficial com foto e o <b>localizador (PNR)</b> indicado acima.</li>
        <li>Horários de voo podem mudar. Consulte o status no site da companhia aérea antes do embarque.</li>
        <li>Bagagem e assentos seguem as regras da tarifa. Alterações e reembolsos:
          <b><?= e($inv['refund_rule'] ?: '—') ?></b> / <b><?= e($inv['change_rule'] ?: '—') ?></b>.
        </li>
        <li>Em caso de urgência, WhatsApp: <b>(61) 993932819</b>.</li>
      </ul>
    </div>
    <div class="card-footer k-muted d-flex flex-wrap align-items-center gap-2">
  <strong>KAMAL TUR</strong> •

  <!-- Globe -->
  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
    <circle cx="12" cy="12" r="10"></circle>
    <line x1="2" y1="12" x2="22" y2="12"></line>
  </svg>
  <a href="<?= e(url_with_scheme('www.kamaltur.com')) ?>" target="_blank">www.kamaltur.com</a> •

  <!-- Mail -->
  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
    <rect x="2" y="4" width="20" height="16" rx="2"></rect>
    <polyline points="22,6 12,13 2,6"></polyline>
  </svg>
  <a href="mailto:kamaltur@kamaltur.com">kamaltur@kamaltur.com</a> •

  <!-- WhatsApp -->
  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
    <path d="M3 21l1.65-3.8A9 9 0 1 1 21 12a9 9 0 0 1-15.35 5.2L3 21z"></path>
  </svg>
  <a href="https://wa.me/5561993932819" target="_blank">(61) 99393-2819</a>
</div>

  </div>

  <div class="mt-3 d-print-none">
    <a href="javascript:window.print()" class="btn btn-primary">Imprimir</a>
  </div>

</div>
</body>
</html>
