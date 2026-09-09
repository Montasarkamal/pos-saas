<?php
/**
 * Filename: /sales/bilhete_ar.php
 * Bilhete / Itinerário (AR) — A4 + Mobile — KamalTur
 */

declare(strict_types=1);

require __DIR__ . '/../inc/auth.php';

// ------------------------------
// 1) التحقق من الوصول
// ------------------------------
$id    = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$token = isset($_GET['token']) ? (string) $_GET['token'] : '';

$public_ok = ($id > 0 && $token !== '' && function_exists('check_public_token') && check_public_token($id, 'voucher', $token));
if (!$public_ok) { require_login(); }

// ------------------------------
// 2) ترويسات الأمان
// ------------------------------
header('Content-Type: text/html; charset=UTF-8');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer-when-downgrade');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// ------------------------------
// 3) جلب البيانات
// ------------------------------
$invSt = $pdo->prepare("SELECT * FROM invoices WHERE id=? " . (!$public_ok ? "AND agency_id=?" : "") . " LIMIT 1");
$invSt->execute(!$public_ok ? [$id, agency_id()] : [$id]);
$inv = $invSt->fetch(PDO::FETCH_ASSOC);
if (!$inv) { http_response_code(404); die("لم يتم العثور على الفاتورة."); }

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
// 4) توابع مساعدة
// ------------------------------
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function fmt_date_ar($d): string {
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

// نصوص عربية مختصرة (يمكن تعديلها بسهولة)
function ar_class($v): string {
  $v = mb_strtolower(trim((string)$v), 'UTF-8');
  if ($v === '') return 'اقتصادي';
  if (strpos($v, 'bus') !== false) return 'رجال الأعمال';
  if (strpos($v, 'first') !== false) return 'أولى';
  if (strpos($v, 'econ') !== false) return 'اقتصادي';
  if (strpos($v, 'prem') !== false) return 'اقتصادي مميز';
  return trim((string)$v);
}

$pnr        = (string)($inv['pnr_code'] ?? '');
$issue_date = (string)($inv['issue_date'] ?? '');
$travel_date= (string)($inv['travel_date'] ?? '');
$invoice_no = (string)($inv['invoice_number'] ?? '');
$main_pax   = $passengers[0]['name'] ?? 'غير متوفر';

?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>تذكرة إلكترونية / خط سير الرحلة — KamalTur</title>
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
      font-family: Tahoma, Arial, sans-serif;
      color:var(--ink);
      background:var(--bg);
      font-size:10.8px;
      line-height:1.45;
      -webkit-font-smoothing: antialiased;
      text-rendering: geometricPrecision;
    }

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
      direction:ltr; /* روابط/دومين */
      text-align:left;
    }

    .pnr{
      text-align:left; /* RTL: الصندوق في اليمين لكن الكتابة هنا أوضح لرمز PNR */
      min-width: 0;
    }
    .pnr .lbl{
      font-size:8px;
      letter-spacing:.08em;
      color:var(--muted2);
      font-weight:700;
      text-transform:uppercase;
      direction:rtl;
      text-align:right;
    }
    .pnr .val{
      font-size:18px;
      font-weight:900;
      letter-spacing:.06em;
      line-height:1.1;
      overflow-wrap:anywhere;
      direction:ltr; /* PNR */
      text-align:right;
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
      letter-spacing:.02em;
      font-weight:800;
    }
    .kv .v{
      font-size:10.8px;
      font-weight:900;
      margin-top:2px;
      overflow-wrap:anywhere;
      max-width: 100%;
    }
    .kv.leftish{ text-align:left; }
    .kv.leftish .v{ direction:ltr; } /* رقم/ID */

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
      max-width: 120mm;
      direction:ltr; /* كود شركة + رقم رحلة */
      text-align:left;
    }
    .seg-h .date{
      font-size:9px;
      font-weight:900;
      color:var(--muted);
      white-space:nowrap;
      direction:ltr;
      text-align:left;
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
      direction:ltr; /* IATA */
      text-align:left;
    }
    .loc .city{
      display:block;
      margin-top:3px;
      font-size:11px;
      font-weight:900;
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
      letter-spacing:.04em;
      font-weight:900;
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
      left:-2px; /* RTL: نضعها يسار الخط */
      top:-12px;
      font-size: 18px;
      color:#cbd5e1;
    }
    .mid .meta{
      display:flex;
      gap:10px;
      flex-wrap:wrap;
      justify-content:center;
      font-size:8px;
      font-weight:900;
    }

    .times{
      margin-top: 8px;
      display:flex;
      gap:12px;
      flex-wrap:wrap;
      border-top: 1px solid #f3f4f6;
      padding-top: 6px;
    }
    .tbox{ min-width: 74px; }
    .tbox .k{
      font-size:7.5px;
      color:var(--muted2);
      font-weight:800;
    }
    .tbox .v{
      font-size:14px;
      font-weight:900;
      margin-top:1px;
      line-height:1.1;
      direction:ltr; /* الوقت */
      text-align:left;
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
      letter-spacing:.04em;
      color:var(--ink);
      border-bottom: 1px solid #eef2f7;
      padding-bottom:6px;
      font-weight:900;
    }
    .box p{
      margin:4px 0;
      font-size:8.9px;
      color:var(--muted);
      line-height:1.55;
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
      line-height:1.5;
      direction:ltr; /* بيانات اتصال */
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
      .strip{ grid-template-columns: 1fr; }
      .seg-b{ grid-template-columns: 1fr; }
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
        <div class="lbl">كود الحجز</div>
        <div class="val"><?= e($pnr ?: '—') ?></div>
      </div>
    </div>

    <div class="strip">
      <div class="kv">
        <div class="k">اسم المسافر الرئيسي</div>
        <div class="v"><?= e($main_pax) ?></div>
      </div>
      <div class="kv">
        <div class="k">تاريخ الإصدار</div>
        <div class="v"><?= e(fmt_date_ar($issue_date)) ?></div>
      </div>
      <div class="kv leftish">
        <div class="k">رقم المستند</div>
        <div class="v">#<?= e($invoice_no ?: (string)$id) ?></div>
      </div>
    </div>

    <?php if (!$segments): ?>
      <div class="box" style="margin-top:12px;">
        <h4>الرحلات</h4>
        <p>لا توجد مقاطع طيران (Segments) لهذه الفاتورة.</p>
      </div>
    <?php endif; ?>

    <?php foreach ($segments as $seg): ?>
      <?php
        $air  = (string)($seg['airline_code'] ?? '');
        $fn   = trim((string)($seg['flight_no'] ?? ''));
        $orig = trim((string)($seg['origin'] ?? ''));
        $dest = trim((string)($seg['destination'] ?? ''));

        $dep_raw = pick_first($seg, ['departure_at','depart_at','dep_at','departure_time','depart_time','dep_time','saida','departure']);
        $arr_raw = pick_first($seg, ['arrival_at','arrive_at','arr_at','arrival_time','arrive_time','arr_time','chegada','arrival']);

        $dep_hm = $dep_raw ? fmt_time_hm($dep_raw) : '—';
        $arr_hm = $arr_raw ? fmt_time_hm($arr_raw) : '—';

        $seg_date_raw = pick_first($seg, ['travel_date','flight_date','departure_date','data','date']);
        $date_label = fmt_date_ar($seg_date_raw ?: $travel_date);

        $class = trim((string)($seg['class'] ?? ''));
        $bagg  = trim((string)($seg['baggage'] ?? ''));
        $loc   = trim((string)($seg['record_locator'] ?? ''));

        $termD = trim((string)($seg['terminal_departure'] ?? $seg['terminal'] ?? $seg['terminal_from'] ?? ''));
        $termA = trim((string)($seg['terminal_arrival'] ?? $seg['terminal_to'] ?? ''));

        $st = trim((string)($seg['status'] ?? 'مؤكد'));
        $is_direct = (isset($seg['stops']) && (string)$seg['stops'] !== '' && (int)$seg['stops'] > 0) ? 'مع توقفات' : 'مباشر';
      ?>

      <div class="seg">
        <div class="seg-h">
          <div class="left">
            <?php $logo = airline_logo_path($air); ?>
            <?php if ($logo): ?>
              <img class="air-logo" src="<?= e($logo) ?>" alt="<?= e($air) ?>" onerror="this.style.display='none'">
            <?php endif; ?>
            <div class="flight">
              رحلة <?= e($air ?: '—') ?> <?= e($fn ?: '') ?>
            </div>
          </div>
          <div class="date"><?= e($date_label) ?></div>
        </div>

        <div class="seg-b">
          <div class="loc">
            <h2 class="iata"><?= e($orig ?: '—') ?></h2>
            <span class="city"><?= e($orig ?: 'المغادرة') ?></span>
            <span class="apt">مطار المغادرة</span>

            <div class="times">
              <div class="tbox">
                <div class="k">الإقلاع</div>
                <div class="v"><?= e($dep_hm) ?></div>
                <div class="s"><?= $termD ? ('الترمينال: ' . e($termD)) : 'الترمينال: —' ?></div>
              </div>
            </div>
          </div>

          <div class="mid">
            <div class="status">الحالة: <?= e($st ?: 'مؤكد') ?></div>
            <div class="line"></div>
            <div class="meta">
              <span><?= e($is_direct) ?></span>
              <span>•</span>
              <span>مؤكد</span>
            </div>
          </div>

          <div class="loc" style="text-align:right;">
            <h2 class="iata"><?= e($dest ?: '—') ?></h2>
            <span class="city"><?= e($dest ?: 'الوصول') ?></span>
            <span class="apt">مطار الوصول</span>

            <div class="times" style="justify-content:flex-end;">
              <div class="tbox" style="text-align:right;">
                <div class="k">الوصول</div>
                <div class="v"><?= e($arr_hm) ?></div>
                <div class="s"><?= $termA ? ('الترمينال: ' . e($termA)) : 'الترمينال: —' ?></div>
              </div>
            </div>
          </div>
        </div>

        <div class="seg-f">
          <div class="kv">
            <div class="k">الدرجة</div>
            <div class="v"><?= e(ar_class($class)) ?></div>
          </div>
          <div class="kv">
            <div class="k">الأمتعة</div>
            <div class="v"><?= e($bagg ?: '0 PC') ?></div>
          </div>
          <div class="kv">
            <div class="k">المقعد</div>
            <div class="v">يُحدد عند إنهاء إجراءات السفر</div>
          </div>
          <div class="kv leftish">
            <div class="k">مرجع شركة الطيران</div>
            <div class="v"><?= e($loc ?: ($pnr ?: '—')) ?></div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>

    <div class="bottom">
      <div class="box">
        <h4>معلومات السفر</h4>
        <p>• يُفضل الوصول إلى المطار قبل ٣ ساعات للرحلات الدولية، وساعتين للداخلية.</p>
        <p>• تأكد من صلاحية جواز السفر والتأشيرات المطلوبة للوجهة.</p>

        <div style="margin-top:10px;">
          <h4 style="margin-top:12px;">قائمة المسافرين</h4>
          <div class="list">
            <?php if (!$passengers): ?>
              <p>• لا يوجد مسافرون مسجلون.</p>
            <?php else: ?>
              <?php foreach ($passengers as $p): ?>
                <p>• <?= e($p['name'] ?? '') ?> (رقم التذكرة: <?= e(($p['ticket_no'] ?? '') ?: 'قيد الإصدار') ?>)</p>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="box">
        <h4>القواعد والخدمات</h4>
        <p><strong>التعديل:</strong> <?= e(($inv['change_rule'] ?? '') ?: 'حسب سياسة شركة الطيران / حسب الاستعلام.') ?></p>
        <p><strong>الاسترداد:</strong> <?= e(($inv['refund_rule'] ?? '') ?: 'حسب سياسة شركة الطيران / حسب الاستعلام.') ?></p>

        <?php if ($aux_services): ?>
          <div style="margin-top:10px;">
            <h4 style="margin-top:12px;">خدمات إضافية</h4>
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
      Documento eletrônico. Transporte sujeito às normas IATA e regras da companhia aérea.
    </div>

    <div class="no-print">
      <button class="btn" onclick="window.print()">🖨️ طباعة A4</button>
    </div>

  </div>
</body>
</html>
