<?php
// =====================================================================
// recibo.php — RECIBO (مطابق للتصميم الرسمي)
// نفس آلية voucher.php (id + token)
// =====================================================================

declare(strict_types=1);

require __DIR__ . '/../inc/auth.php'; // $pdo + check_public_token + require_login()

// ------------------------------
// 1) Access control (مثل voucher.php)
// ------------------------------
$id    = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$token = isset($_GET['token']) ? (string)$_GET['token'] : '';

$public_ok = (
  $id > 0 &&
  $token !== '' &&
  function_exists('check_public_token') &&
  check_public_token($id, 'voucher', $token) // نفس نوع voucher
);

if (!$public_ok) {
  require_login();
}

if ($id <= 0) {
  http_response_code(404);
  exit('ID inválido');
}

// ------------------------------
// Helpers
// ------------------------------
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function br_money($n){ return 'R$ ' . number_format((float)$n, 2, ',', '.'); }
function br_date_long($d){
  if(!$d) return '—';
  $t = strtotime((string)$d);
  if(!$t) return e($d);
  $dt = (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone('America/Sao_Paulo'));
  if (class_exists('IntlDateFormatter')) {
    $fmt = new IntlDateFormatter('pt_BR', IntlDateFormatter::LONG, IntlDateFormatter::NONE, 'America/Sao_Paulo');
    return $fmt->format($dt);
  }
  $months = [1=>'janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];
  return $dt->format('d') . ' de ' . $months[(int)$dt->format('n')] . ' de ' . $dt->format('Y');
}

// ------------------------------
// Invoice
// ------------------------------
$inv = $pdo->prepare("
  SELECT id, invoice_number, pnr_code, travel_date, issue_date
  FROM invoices
  WHERE id=? " . (!$public_ok ? "AND agency_id=?" : "") . "
  LIMIT 1
");
$inv->execute(!$public_ok ? [$id, agency_id()] : [$id]);
$invoice = $inv->fetch(PDO::FETCH_ASSOC);
if(!$invoice){ exit('Venda não encontrada'); }

// ------------------------------
// Total (igual voucher.php)
// ------------------------------
$totalCol = null;
foreach (['total_amount','grand_total','total','valor_total'] as $c) {
  try {
    $pdo->query("SELECT `$c` FROM invoices LIMIT 1");
    $totalCol = $c;
    break;
  } catch (Throwable $e) {}
}
$total = 0;
if ($totalCol) {
  $st = $pdo->prepare("SELECT `$totalCol` FROM invoices WHERE id=? " . (!$public_ok ? "AND agency_id=?" : ""));
  $st->execute(!$public_ok ? [$id, agency_id()] : [$id]);
  $total = (float)$st->fetchColumn();
}

// ------------------------------
// Client
// ------------------------------
$client = $pdo->prepare("
  SELECT c.name, c.document
  FROM invoices i
  LEFT JOIN clients c ON c.id = i.client_id
  WHERE i.id=?
");
$client->execute([$id]);
$cli = $client->fetch(PDO::FETCH_ASSOC);

// ------------------------------
// Passengers
// ------------------------------
$ps = $pdo->prepare("SELECT name FROM passengers WHERE invoice_id=?");
$ps->execute([$id]);
$passengers = array_column($ps->fetchAll(PDO::FETCH_ASSOC), 'name');

// ------------------------------
// Airline
// ------------------------------
$sg = $pdo->prepare("SELECT airline_code FROM segments WHERE invoice_id=? LIMIT 1");
$sg->execute([$id]);
$airline = $sg->fetchColumn();

// ------------------------------
// Data
// ------------------------------
$cliente     = $cli['name'] ?? '—';
$doc         = $cli['document'] ?? '—';
$pnr         = $invoice['pnr_code'] ?? '—';
$data_viagem = br_date_long($invoice['travel_date']);
$data_emis   = br_date_long($invoice['issue_date']);
$pass_txt    = $passengers ? implode(' e ', $passengers) : '—';
$airline_txt = $airline ?: '—';

?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Recibo — KamalTur</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
@page{ size:A4; margin:25mm; }
body{
  font-family: Arial, Helvetica, sans-serif;
  font-size:13px;
  line-height:1.7;
  color:#1f2937;
  background:#fff;
}
.logo{
  text-align:center;
  margin-bottom:30px;
}
.logo img{ width:220px; }
.title{
  text-align:center;
  font-weight:800;
  font-size:20px;
  margin-bottom:25px;
}
.content{
  max-width:720px;
  margin:0 auto;
}
.signature{
  margin-top:60px;
  text-align:center;
  font-size:14px;
}
.signature b{ display:block; margin-bottom:4px; }
.footer{
  margin-top:50px;
  text-align:center;
  font-size:12px;
  color:#4b5563;
}
@media print{
  .no-print{ display:none; }
}
</style>
</head>
<body>

<div class="logo">
  <img src="https://kamaltur.com/KAMALTUR.png" alt="KAMALTUR">
</div>

<div class="title">RECIBO</div>

<div class="content">
  <p>
    Declaramos, para os devidos fins, que recebemos de
    <b><?= e($cliente) ?></b>, inscrita no CNPJ/CPF sob o nº
    <b><?= e($doc) ?></b>, o valor total de
    <b><?= e(br_money($total)) ?></b>,
    referente à emissão de passagens aéreas em nome dos passageiros
    <b><?= e($pass_txt) ?></b>,
    sob a reserva <b><?= e($pnr) ?></b>,
    com voos operados pela <b><?= e($airline_txt) ?></b>,
    no dia <b><?= e($data_viagem) ?></b>.
  </p>

  <p>
    Data de emissão: <b><?= e($data_emis) ?></b>.
  </p>

  <div class="signature">
    <b>Montasar Kamal</b>
    Diretor – KamalTur
  </div>

  <div class="footer">
    Kamal Turismo LTDA | CNPJ: 13.403.060/0001-05 | Lago Norte, Brasília - DF<br>
    +55 (61) 99393-2819 | kamaltur@kamaltur.com.br
  </div>
</div>

<div class="no-print" style="text-align:center;margin-top:20px;">
  <button onclick="window.print()">Imprimir</button>
</div>

</body>
</html>
