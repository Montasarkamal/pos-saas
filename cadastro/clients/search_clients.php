<?php
require __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=UTF-8');

$q    = trim($_GET['q'] ?? '');
$page = (int)($_GET['page'] ?? 1);
if ($page < 1) $page = 1;

if (mb_strlen($q, 'UTF-8') < 2) {
  echo json_encode(['results' => []], JSON_UNESCAPED_UNICODE);
  exit;
}

$limit  = 20;                         // يكفي لـ autocomplete
$offset = ($page - 1) * $limit;

$sql = "
  SELECT id, name
  FROM clients
  WHERE agency_id = ? AND client_type = 'pj' AND name LIKE ?
  ORDER BY name ASC
  LIMIT ? OFFSET ?
";

$st = $pdo->prepare($sql);
$st->bindValue(1, agency_id(), PDO::PARAM_INT);
$st->bindValue(2, '%'.$q.'%', PDO::PARAM_STR);
$st->bindValue(3, $limit,      PDO::PARAM_INT);
$st->bindValue(4, $offset,     PDO::PARAM_INT);
$st->execute();

$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// واجهة jQuery UI عندك تتوقع data.results
$out = [
  'results' => array_map(
    fn($r) => ['id' => (int)$r['id'], 'text' => $r['name']],
    $rows
  )
];

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
