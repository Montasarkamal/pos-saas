<?php
require __DIR__ . '/../inc/auth.php';
require_login();
header('Content-Type: text/plain; charset=UTF-8');

echo "PHP_VERSION=" . PHP_VERSION . PHP_EOL;

try {
  $dbv = $pdo->query("SELECT VERSION()")->fetchColumn();
  echo "DB_VERSION=" . $dbv . PHP_EOL . PHP_EOL;

  echo "-- SHOW CREATE TABLE clients --" . PHP_EOL;
  $r = $pdo->query("SHOW CREATE TABLE clients")->fetch(PDO::FETCH_ASSOC);
  echo ($r['Create Table'] ?? '') . ";" . PHP_EOL . PHP_EOL;

  echo "-- SHOW FULL COLUMNS FROM clients --" . PHP_EOL;
  foreach ($pdo->query("SHOW FULL COLUMNS FROM clients") as $row) {
    echo implode("\t", [
      $row['Field'],$row['Type'],$row['Null'],$row['Key'],
      $row['Default'],$row['Extra'],$row['Collation'],$row['Comment']
    ]) . PHP_EOL;
  }

  echo PHP_EOL . "-- INDEXES (SHOW INDEX FROM clients) --" . PHP_EOL;
  foreach ($pdo->query("SHOW INDEX FROM clients") as $row) {
    echo implode("\t", [
      $row['Key_name'],$row['Non_unique'],$row['Seq_in_index'],
      $row['Column_name'],$row['Sub_part'],$row['Index_type']
    ]) . PHP_EOL;
  }

  echo PHP_EOL . "-- FOREIGN KEYS --" . PHP_EOL;
  $sqlFK = "SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'clients'
              AND REFERENCED_TABLE_NAME IS NOT NULL";
  foreach ($pdo->query($sqlFK) as $row) {
    echo implode("\t", [
      $row['CONSTRAINT_NAME'],$row['COLUMN_NAME'],
      $row['REFERENCED_TABLE_NAME'],$row['REFERENCED_COLUMN_NAME']
    ]) . PHP_EOL;
  }

  echo PHP_EOL . "-- TRIGGERS --" . PHP_EOL;
  foreach ($pdo->query("SHOW TRIGGERS WHERE `Table`='clients'") as $row) {
    echo $row['Trigger'] . "\t" . $row['Timing'] . " " . $row['Event'] . PHP_EOL;
  }

} catch (Throwable $e) {
  http_response_code(500);
  echo "ERROR: " . $e->getMessage();
}
