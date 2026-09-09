<?php
declare(strict_types=1);

if (!function_exists('load_dotenv_file')) {
  function load_dotenv_file(string $path): void {
    if (!is_readable($path)) return;

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
      $line = trim($line);
      if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
      [$key, $value] = array_map('trim', explode('=', $line, 2));
      $value = trim($value, "\"'");
      if ($key !== '' && getenv($key) === false) {
        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
      }
    }
  }
}

load_dotenv_file(dirname(__DIR__) . '/.env');

if (!function_exists('env_value')) {
  function env_value(string $key, ?string $default = null): ?string {
    $value = getenv($key);
    if ($value !== false) return $value;
    return $_ENV[$key] ?? $default;
  }
}

$DB_HOST = env_value('DB_HOST', 'localhost');
$DB_PORT = env_value('DB_PORT', '3306');
$DB_NAME = env_value('DB_NAME', 'u834141812_kamaltur_pos');
$DB_USER = env_value('DB_USER', 'u834141812_kamaluser');
$DB_PASS = env_value('DB_PASS');

if ($DB_PASS === null) {
  error_log('DB_PASS is not configured.');
  http_response_code(500);
  exit('Erro de configuração do banco de dados.');
}

$dsn = "mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4";
try {
  $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
} catch (PDOException $e) {
  error_log('DB connection error: ' . $e->getMessage());
  http_response_code(500);
  exit('Erro ao conectar ao banco de dados.');
}
