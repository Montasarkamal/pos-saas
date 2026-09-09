<?php
// =====================================================================
// sales/print.php — Impressão pública/privada da Venda (versão limpa)
// - Se ?id=..&token=.. válido (tipo: invoice) → acesso externo permitido
// - Caso contrário → exige sessão (require_login)
// - Layout: cabeçalho (empresa), cliente+venda, passageiros, segmentos,
//           serviços auxiliares (se houver), valores, assinatura+banco.
// =====================================================================

require __DIR__ . '/../inc/auth.php'; // inclui session/db + token helpers

$id    = (int)($_GET['id'] ?? 0);
$token = (string)($_GET['token'] ?? '');

// Porta aberta só se houver token válido de "invoice"
$public_ok = ($id > 0 && $token !== '' && function_exists('check_public_token') && check_public_token($id, 'invoice', $token));
if ($public_ok) {
  // ok, acesso público
} else {
  require_login();
}

header('Content-Type: text/html; charset=UTF-8');

if ($id <= 0) {
  http_response_code(404);
  echo "<h1>404</h1><p>Parâmetro ID inválido.</p>";
  exit;
}

// === Carrega venda + cliente
$sqlInv = $pdo->prepare("
  SELECT i.*,
         c.name      AS client_name,
         c.document  AS client_document,
         c.address   AS client_address,
         c.email     AS client_email,
         c.phone     AS client_phone
    FROM invoices i
    LEFT JOIN clients c ON c.id = i.client_id
   WHERE i.id = ?" . (!$public_ok ? " AND i.agency_id = ?" : "") . "
  LIMIT 1
");
$sqlInv->execute(!$public_ok ? [$id, agency_id()] : [$id]);
$inv = $sqlInv->fetch(PDO::FETCH_ASSOC);
if (!$inv) {
  http_response_code(404);
  echo "<h1>404</h1><p>Venda não encontrada.</p>";
  exit;
}

// === Passageiros
$sqlP = $pdo->prepare("SELECT name, ptype, ticket_no, value FROM passengers WHERE invoice_id = ? ORDER BY id ASC");
$sqlP->execute([$id]);
$passengers = $sqlP->fetchAll(PDO::FETCH_ASSOC);

// === Segmentos
$sqlS = $pdo->prepare("
  SELECT airline_code, flight_no, `origin`, `destination`, `class`, `baggage`, record_locator
    FROM segments
   WHERE invoice_id = ?
   ORDER BY id ASC
");
$sqlS->execute([$id]);
$segments = $sqlS->fetchAll(PDO::FETCH_ASSOC);

// === Serviços da Venda
$auxCols = ['code', 'service', 'value'];
foreach (['service_type', 'start_date', 'end_date', 'details'] as $col) {
  if (function_exists('has_column') && has_column($pdo, 'aux_services', $col)) $auxCols[] = $col;
}
$sqlA = $pdo->prepare("
  SELECT `" . implode('`,`', $auxCols) . "`
    FROM aux_services
   WHERE invoice_id = ?
   ORDER BY id ASC
");
$sqlA->execute([$id]);
$aux_services = $sqlA->fetchAll(PDO::FETCH_ASSOC);

// === Helpers
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money_br($n){ return 'R$ ' . number_format((float)$n, 2, ',', '.'); }
function url_with_scheme(string $u){ $u=trim($u); if($u==='') return '#'; return preg_match('~^https?://~i',$u)?$u:'https://'.ltrim($u,'/'); }
$saleServiceTypes = [
  'hotel' => 'Hotel',
  'car' => 'Aluguel de carro',
  'insurance' => 'Seguro saúde',
  'reception' => 'Recepção',
  'guide' => 'Guia turístico',
  'transfer' => 'Transfer',
  'other' => 'Outro serviço',
];

// Totais
$sumPassengers = 0.0;
foreach ($passengers as $p) { $sumPassengers += (float)$p['value']; }

$auxTotal = 0.0;
foreach ($aux_services as $a) { $auxTotal += (float)$a['value']; }

// Branding
$companyLogo = 'https://kamaltur.com/KAMALTUR.png';

// Assinatura + Banco
$assinaturaPath = '/assinatura.png';
$signatureEnabled = !array_key_exists('show_signature', $inv) || (int)$inv['show_signature'] === 1;
$showSignature  = $signatureEnabled && is_file(($_SERVER['DOCUMENT_ROOT'] ?? '') . $assinaturaPath);

// Status badge cor
$status = strtolower(trim($inv['status'] ?? 'nao pago'));
$stColor = '#b91c1c'; // red
if ($status === 'pago parcial') $stColor = '#a16207'; // amber
if ($status === 'pago')         $stColor = '#15803d'; // green
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<title>Venda #<?= e($inv['invoice_number']) ?> — <?= e($inv['client_name'] ?? '') ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">

<style>
  :root{
    --paper:#ffffff;
    --line:#e5e7eb;
    --text:#0f172a;
    --muted:#475569;
    --accent:#24324a;
    --head:#f3f3f3;
    --fz-title:12px;
    --fz-body:10px;
    --content-w:920px;
    --pad:14px;
    --radius:8px;
  }

  html,body{margin:0;padding:0}
  body{
    color:var(--text);
    font-family: 'Inter', Arial, Helvetica, sans-serif;
    font-size:var(--fz-body);
    -webkit-font-smoothing:antialiased;
    text-rendering:geometricPrecision;
  }

  .container{ max-width:var(--content-w); margin:18px auto; padding:0 10px; }
  .page{ background:var(--paper); }

  .header{
    display:flex; align-items:center; justify-content:space-between; gap:16px;
    padding-bottom:10px; border-bottom:1px solid var(--line);
  }
  .header img{ height:52px; width:auto; object-fit:contain; }
  .agency{ text-align:right; color:var(--muted); }
  .agency a{ color:inherit; text-decoration:none; }

  .title{ margin:12px 0 0; font-size:var(--fz-title); font-weight:700; color:var(--accent); }

  .grid-2{
    display:grid; grid-template-columns: 2fr 1fr; gap:12px; margin-top:12px;
  }
  .card{ border:1px solid var(--line); border-radius:var(--radius); background:#fff; }
  .card .card-header{ background:var(--head); border-bottom:1px solid var(--line); padding:8px 10px; font-weight:700; font-size:var(--fz-body); color:var(--accent); }
  .card .card-body{ padding:var(--pad); }

  .kv{ display:grid; grid-template-columns: auto 1fr; row-gap:6px; column-gap:8px; }
  .kv .k{ color:var(--muted); }
  .kv .v{ color:var(--text); font-weight:600; }

  .status-badge{ display:inline-block; padding:2px 8px; border-radius:999px; color:#fff; font-weight:700; font-size:var(--fz-body); }

  table{ width:100%; border-collapse:collapse; margin-top:10px; }
  th, td{ border:1px solid var(--line); padding:8px; vertical-align:top; font-size:var(--fz-body); }
  thead th{ background:var(--head); color:var(--accent); text-transform:uppercase; letter-spacing:.04em; font-weight:700; }

  .segments col.cia{ width: 50px; } .segments col.voo{ width: 40px; }
  .segments col.orig{ width: 160px; } .segments col.dest{ width: 160px; }
  .segments col.cls{ width: 100px; } .segments col.bag{ width: 110px; }
  .segments col.loc{ width: 40px; }

  .section-title{ margin-top:14px; font-size:var(--fz-title); font-weight:700; color:var(--accent); }

  .sign-bank{ display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-top:14px; }
  .sign-box, .bank-box{ border:1px solid var(--line); border-radius:var(--radius); background:#fff; }
  .sign-box .card-body, .bank-box .card-body{ padding:var(--pad); }
  .stamp{ max-height:70px; height:auto; width:auto; display:block; }

  .notes{ border:1px solid var(--line); border-radius:var(--radius); margin-top:14px; background:#fff; }
  .notes .card-header{ background:var(--head); border-bottom:1px solid var(--line); padding:8px 10px; font-weight:700; color:var(--accent); }
  .notes .card-body{ padding:var(--pad); color:var(--text); }
  .notes ul{ margin:0; padding-left:18px; }

  .print-btn{ display:inline-block; background-color:#4285f4; color:#fff; font-size:14px; font-weight:600; padding:10px 24px; border:none; border-radius:8px; cursor:pointer; text-decoration:none; transition:background-color .2s ease; }
  .print-btn:hover{ background-color:#3367d6; }
  @media print { .print-btn{ display:none !important; } }

  .right{ text-align:right; }
</style>
</head>
<body>
  <div class="container">
    <div class="page">

      <!-- Cabeçalho — empresa -->
      <div class="header">
        <img src="<?= e($companyLogo) ?>" alt="KAMAL TUR">
        <div class="agency">
          <div><strong>KAMAL TUR</strong></div>
          <div>Email: <a href="mailto:kamaltur@kamaltur.com.br">kamaltur@kamaltur.com.br</a></div>
          <div>Site: <a href="<?= e(url_with_scheme('www.kamaltur.com.br')) ?>" target="_blank" rel="noopener">kamaltur.com.br</a></div>
          <div>WhatsApp: (61) 99999-9999</div>
        </div>
      </div>

      <!-- Cliente + Venda -->
      <div class="grid-2">
        <div class="card">
          <div class="card-header">Dados do Cliente</div>
          <div class="card-body">
            <div class="kv">
              <div class="k">Nome</div><div class="v"><?= e($inv['client_name'] ?? '—') ?></div>
              <div class="k">CPF/CNPJ</div><div class="v"><?= e($inv['client_document'] ?? '—') ?></div>
              <div class="k">Contato</div>
              <div class="v">
                <?= e($inv['client_email'] ?? '—') ?>
                <?= !empty($inv['client_phone']) ? ' | '.e($inv['client_phone']) : '' ?>
              </div>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-header">Dados da Venda</div>
          <div class="card-body">
            <div class="kv">
              <div class="k">Nº da Venda</div><div class="v"><?= e($inv['invoice_number']) ?></div>
              <div class="k">Emissão</div><div class="v"><?= e($inv['issue_date']) ?></div>
              <div class="k">Status</div><div class="v"><span class="status-badge" style="background:<?= e($stColor) ?>"><?= e(strtoupper($status)) ?></span></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Passageiros -->
      <div class="section-title">Passageiros</div>
      <table>
        <thead>
          <tr>
            <th>Nome</th>
            <th style="width:120px;">PNR</th>
            <th style="width:90px;">Tipo</th>
            <th style="width:120px;">Viagem</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($passengers): foreach ($passengers as $p): ?>
            <tr>
              <td><?= e($p['name']) ?></td>
              <td><?= e($inv['pnr_code'] ?: '—') ?></td>
              <td><?= e($p['ptype']) ?></td>
              <td><?= e($inv['travel_date'] ?: '—') ?></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="4" style="color:var(--muted)">Sem passageiros.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>

      <!-- Segmentos (Voos) -->
      <div class="section-title">Segmentos (Voos)</div>
      <table>
        <colgroup class="segments">
          <col class="cia"><col class="voo"><col class="orig"><col class="dest"><col class="cls"><col class="bag"><col class="loc">
        </colgroup>
        <thead>
          <tr>
            <th>Cia</th>
            <th>Nº voo</th>
            <th>Origem</th>
            <th>Destino</th>
            <th>Classe</th>
            <th>Bagagem</th>
            <th>Loc Cia</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($segments): foreach ($segments as $s): ?>
            <tr>
              <td><?= e($s['airline_code']) ?></td>
              <td><?= e($s['flight_no']) ?></td>
              <td><?= e($s['origin']) ?></td>
              <td><?= e($s['destination']) ?></td>
              <td><?= e($s['class'] ?: '—') ?></td>
              <td><?= e($s['baggage'] ?: '—') ?></td>
              <td><?= e($s['record_locator'] ?: ($inv['pnr_code'] ?: '—')) ?></td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="7" style="color:var(--muted)">Sem segmentos.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>

      <!-- Serviços da Venda -->
      <?php if (!empty($aux_services)): ?>
        <div class="section-title">Serviços da Venda</div>
        <table>
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
                <td>
                  <?= e($a['service']) ?>
                  <?php if (!empty($a['service_type']) || !empty($a['start_date']) || !empty($a['end_date']) || !empty($a['details'])): ?>
                    <br><span style="color:var(--muted);font-size:12px;">
                      <?= e($saleServiceTypes[$a['service_type'] ?? ''] ?? ($a['service_type'] ?? '')) ?>
                      <?php if (!empty($a['start_date']) || !empty($a['end_date'])): ?>
                        · <?= e($a['start_date'] ?: '—') ?> → <?= e($a['end_date'] ?: '—') ?>
                      <?php endif; ?>
                      <?php if (!empty($a['details'])): ?>
                        · <?= e($a['details']) ?>
                      <?php endif; ?>
                    </span>
                  <?php endif; ?>
                </td>
                
              </tr>
            <?php endforeach; ?>
          </tbody>
          
        </table>
      <?php endif; ?>

      <!-- Valores (Resumo Financeiro) -->
<div class="section-title">Valores</div>
<table>
  <thead>
    <tr>
      <th>Descrição</th>
      <th style="width:160px;" class="right">Valor</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>Tarifa Aérea (Passageiros)</td>
      <td class="right"><?= money_br($sumPassengers) ?></td>
    </tr>
    <tr>
      <td>Serviços Auxiliares</td>
      <td class="right"><?= money_br($auxTotal) ?></td>
    </tr>
  </tbody>
  <tfoot>
    <tr>
      <th class="right">Valor Total </th>
      <th class="right"><?= money_br($sumPassengers + $auxTotal) ?></th>
    </tr>
  </tfoot>
</table>


      <!-- Assinatura + Dados Bancários -->
      <div class="sign-bank">
        <div class="sign-box card">
          <div class="card-header">Assinatura</div>
          <div class="card-body" style="display:flex;align-items:center;gap:12px;">
            <?php if ($showSignature): ?>
              <img src="<?= e($assinaturaPath) ?>" alt="Assinatura" class="stamp">
            <?php endif; ?>
            <div>
              <div><strong>Montasar Kamal</strong></div>
              <div style="color:var(--muted)">Diretor — KAMAL TUR</div>
            </div>
          </div>
        </div>
        <div class="bank-box card">
          <div class="card-header">Dados Bancários</div>
          <div class="card-body">
            <div><strong>Banco:</strong> 403 - CORA SCFI</div>
            <div><strong>AGÊNCIA:</strong> 0001</div>
            <div><strong>CONTA CORRENTE:</strong> 5547643-9</div>
            <div><strong>Chave Pix / CNPJ:</strong> 13.403.060/0001-05</div>
            <div><strong>Nome:</strong> KAMAL TURISMO </div>
          </div>
        </div>
      </div>

      <!-- Observações -->
      <div class="notes">
        <div class="card-header">Observações & Regras</div>
        <div class="card-body">
          <ul>
            <li>Bilhetes aéreos seguem as regras da companhia aérea (remarcação/cancelamento podem gerar custos adicionais).</li>
            <li>Verifique o status do voo no site/app da companhia antes do embarque.</li>
            <li>Mantenha um documento oficial com foto e o localizador (PNR).</li>
            <li>Em caso de dúvidas: WhatsApp (61) 993932819 • <?= e(url_with_scheme('www.kamaltur.com.br')) ?></li>
          </ul>
        </div>
      </div>

    </div>

    <!-- زر الطباعة -->
    <div style="text-align:right; margin-top:20px;">
      <a href="javascript:window.print()" class="print-btn">Imprimir</a>
    </div>

  </div>
</body>
</html>
