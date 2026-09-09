<?php
// inc/options.php — يرجع قوائم العملاء/الموردين بصيغة JSON
require __DIR__ . '/auth.php'; require_login();
require_once __DIR__ . '/helpers.php';
header('Content-Type: application/json; charset=utf-8');

try {
  [$clientAgencyCondition, $clientAgencyParams] = agency_scope_sql('agency_id');
  $stClients = $pdo->prepare("SELECT id, name, document, client_type FROM clients WHERE $clientAgencyCondition ORDER BY name");
  $stClients->execute($clientAgencyParams);
  $clients = $stClients->fetchAll();

  $suppliers = [];
  if (has_table($pdo, 'suppliers')) {
    [$supplierAgencyCondition, $supplierAgencyParams] = agency_scope_sql('agency_id');
    $stSuppliers = $pdo->prepare("SELECT id, name, document, supplier_type FROM suppliers WHERE $supplierAgencyCondition ORDER BY name");
    $stSuppliers->execute($supplierAgencyParams);
    $suppliers = $stSuppliers->fetchAll();
  }

  echo json_encode([
    'clients' => array_map(fn($c)=>[
      'id'=>(int)$c['id'],
      'name'=> $c['name'],
      'text'=> $c['name'] . ' — ' . $c['document'] . ' (' . ($c['client_type']==='pj'?'PJ':'PF') . ')'
    ], $clients),
    'suppliers' => array_map(fn($s)=>[
      'id'=>(int)$s['id'],
      'text'=> $s['name'] . ' — ' . $s['document'] . ' (' . ($s['supplier_type']==='pj'?'PJ':'PF') . ')'
    ], $suppliers),
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  error_log('options.php: ' . $e->getMessage());
  echo json_encode(['error' => 'Erro ao carregar opções.']);
}
