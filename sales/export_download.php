<?php
declare(strict_types=1);

ob_start();

require __DIR__ . '/../inc/auth.php';
require_login();
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/helpers.php';

function export_clean_filename(string $name): string {
  return preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?: 'sales-export';
}

function export_csv_string(array $headers, array $rows): string {
  $fh = fopen('php://temp', 'r+');
  fwrite($fh, "\xEF\xBB\xBF");
  fputcsv($fh, $headers, ',', '"', '\\');
  foreach ($rows as $row) {
    $line = [];
    foreach ($headers as $header) $line[] = $row[$header] ?? '';
    fputcsv($fh, $line, ',', '"', '\\');
  }
  rewind($fh);
  $csv = stream_get_contents($fh);
  fclose($fh);
  return (string)$csv;
}

function export_rows_by_invoice(PDO $pdo, string $sql, array $ids): array {
  if (!$ids) return [];
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $st = $pdo->prepare(str_replace('__IDS__', $placeholders, $sql));
  $st->execute($ids);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function export_output_download(string $filename, string $contentType, string $body): void {
  while (ob_get_level() > 0) {
    ob_end_clean();
  }
  header('Content-Type: ' . $contentType);
  header('Content-Disposition: attachment; filename="' . export_clean_filename($filename) . '"');
  header('Content-Length: ' . strlen($body));
  echo $body;
  exit;
}

$format = (string)($_GET['format'] ?? 'zip');
if (!in_array($format, ['zip', 'json', 'csv'], true)) $format = 'zip';
$includeDetails = isset($_GET['include_details']);
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));

[$agencyCondition, $agencyParams] = agency_scope_sql('i.agency_id');
$where = [$agencyCondition];
$params = $agencyParams;

if ($from !== '') { $where[] = 'i.issue_date >= ?'; $params[] = $from; }
if ($to !== '') { $where[] = 'i.issue_date <= ?'; $params[] = $to; }
if ($status !== '') { $where[] = 'LOWER(TRIM(i.status)) = LOWER(TRIM(?))'; $params[] = $status; }
if ($q !== '') {
  $where[] = '(i.invoice_number LIKE ? OR i.pnr_code LIKE ? OR c.name LIKE ?)';
  $like = '%' . $q . '%';
  array_push($params, $like, $like, $like);
}

$sql = "
  SELECT
    i.id AS external_sale_id,
    i.invoice_number AS sale_number,
    i.issue_date,
    i.travel_date,
    i.status,
    i.currency,
    i.pnr_code,
    i.scope,
    i.total_amount,
    i.amount_paid,
    i.total_paid,
    i.margin_value,
    i.supplier_tarifa,
    i.supplier_comissao,
    i.supplier_liquid,
    i.refund_rule,
    i.change_rule,
    i.notes,
    i.created_at,
    c.name AS client_name,
    c.document AS client_document,
    c.email AS client_email,
    c.phone AS client_phone
  FROM invoices i
  LEFT JOIN clients c ON c.id = i.client_id AND c.agency_id = i.agency_id
  WHERE " . implode(' AND ', $where) . "
  ORDER BY i.issue_date DESC, i.id DESC
";

$st = $pdo->prepare($sql);
$st->execute($params);
$sales = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
$ids = array_map(static fn(array $row): int => (int)$row['external_sale_id'], $sales);

$passengers = [];
$segments = [];
$services = [];
if ($includeDetails && $ids) {
  $passengers = export_rows_by_invoice($pdo, "
    SELECT invoice_id AS external_sale_id, name, ptype AS passenger_type, ticket_no, value
    FROM passengers
    WHERE invoice_id IN (__IDS__)
    ORDER BY invoice_id, id
  ", $ids);

  $segments = export_rows_by_invoice($pdo, "
    SELECT invoice_id AS external_sale_id, airline_code AS airline, flight_no, `origin`, `destination`, `class`, baggage, record_locator
    FROM segments
    WHERE invoice_id IN (__IDS__)
    ORDER BY invoice_id, id
  ", $ids);

  $services = export_rows_by_invoice($pdo, "
    SELECT invoice_id AS external_sale_id, service_type, code, service, start_date, end_date, value, cost_value, supplier_paid, details
    FROM aux_services
    WHERE invoice_id IN (__IDS__)
    ORDER BY invoice_id, id
  ", $ids);
}

$payload = [
  'schema' => 'kamaltur_sales_export_v1',
  'generated_at' => date('c'),
  'filters' => [
    'from' => $from,
    'to' => $to,
    'status' => $status,
    'q' => $q,
  ],
  'sales' => array_map(static function(array $sale) use ($passengers, $segments, $services): array {
    $saleId = (int)$sale['external_sale_id'];
    $sale['passengers'] = array_values(array_filter($passengers, static fn(array $row): bool => (int)$row['external_sale_id'] === $saleId));
    $sale['segments'] = array_values(array_filter($segments, static fn(array $row): bool => (int)$row['external_sale_id'] === $saleId));
    $sale['services'] = array_values(array_filter($services, static fn(array $row): bool => (int)$row['external_sale_id'] === $saleId));
    return $sale;
  }, $sales),
];

$stamp = date('Ymd-His');

if ($format === 'json') {
  $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  export_output_download("sales-export-{$stamp}.json", 'application/json; charset=utf-8', $body ?: '{}');
}

$salesHeaders = [
  'external_sale_id', 'sale_number', 'issue_date', 'travel_date', 'status', 'currency', 'pnr_code', 'scope',
  'client_name', 'client_document', 'client_email', 'client_phone',
  'total_amount', 'amount_paid', 'total_paid', 'margin_value',
  'supplier_tarifa', 'supplier_comissao', 'supplier_liquid',
  'refund_rule', 'change_rule', 'notes', 'created_at',
];
$salesCsv = export_csv_string($salesHeaders, $sales);

if ($format === 'csv') {
  export_output_download("sales-export-{$stamp}.csv", 'text/csv; charset=utf-8', $salesCsv);
}

if (!class_exists('ZipArchive')) {
  export_output_download("sales-export-{$stamp}.json", 'application/json; charset=utf-8', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
}

$zipPath = tempnam(sys_get_temp_dir(), 'sales_export_');
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
  throw new RuntimeException('Não foi possível criar o ZIP.');
}

$zip->addFromString('sales.csv', $salesCsv);
$zip->addFromString('passengers.csv', export_csv_string(['external_sale_id', 'name', 'passenger_type', 'ticket_no', 'value'], $passengers));
$zip->addFromString('segments.csv', export_csv_string(['external_sale_id', 'airline', 'flight_no', 'origin', 'destination', 'class', 'baggage', 'record_locator'], $segments));
$zip->addFromString('services.csv', export_csv_string(['external_sale_id', 'service_type', 'code', 'service', 'start_date', 'end_date', 'value', 'cost_value', 'supplier_paid', 'details'], $services));
$zip->addFromString('sales_export.json', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
$zip->addFromString('README.txt', "KAMALTUR neutral sales export v1\n\nUse external_sale_id to join sales.csv with passengers.csv, segments.csv and services.csv.\nJSON file contains the same data nested by sale.\nDates use YYYY-MM-DD. Amounts use decimal numbers with dot separator.\n");
$zip->close();

$body = (string)file_get_contents($zipPath);
@unlink($zipPath);
export_output_download("sales-export-{$stamp}.zip", 'application/zip', $body);
