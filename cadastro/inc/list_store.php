<?php
declare(strict_types=1);

function list_store_dir(): string {
  return __DIR__ . '/options';
}

function list_store_defaults(): array {
  return [
    'airlines' => [
      ['code' => 'LA', 'label' => 'LATAM', 'logo' => ''],
      ['code' => 'G3', 'label' => 'GOL', 'logo' => ''],
      ['code' => 'AD', 'label' => 'AZUL', 'logo' => ''],
      ['code' => 'TP', 'label' => 'TAP Air Portugal', 'logo' => ''],
      ['code' => 'AA', 'label' => 'American Airlines', 'logo' => ''],
      ['code' => 'AF', 'label' => 'Air France', 'logo' => ''],
      ['code' => 'KL', 'label' => 'KLM', 'logo' => ''],
      ['code' => 'LH', 'label' => 'Lufthansa', 'logo' => ''],
      ['code' => 'EK', 'label' => 'Emirates', 'logo' => ''],
      ['code' => 'QR', 'label' => 'Qatar Airways', 'logo' => ''],
      ['code' => 'EY', 'label' => 'Etihad Airways', 'logo' => ''],
      ['code' => 'TK', 'label' => 'Turkish Airlines', 'logo' => ''],
      ['code' => 'BA', 'label' => 'British Airways', 'logo' => ''],
      ['code' => 'IB', 'label' => 'Iberia', 'logo' => ''],
      ['code' => 'UX', 'label' => 'Air Europa', 'logo' => ''],
      ['code' => 'AR', 'label' => 'Aerolineas Argentinas', 'logo' => ''],
      ['code' => 'CM', 'label' => 'Copa Airlines', 'logo' => ''],
      ['code' => 'AM', 'label' => 'Aeromexico', 'logo' => ''],
      ['code' => 'DL', 'label' => 'Delta Air Lines', 'logo' => ''],
      ['code' => 'UA', 'label' => 'United Airlines', 'logo' => ''],
      ['code' => 'AC', 'label' => 'Air Canada', 'logo' => ''],
      ['code' => 'QF', 'label' => 'Qantas', 'logo' => ''],
      ['code' => 'NZ', 'label' => 'Air New Zealand', 'logo' => ''],
      ['code' => 'CX', 'label' => 'Cathay Pacific', 'logo' => ''],
      ['code' => 'JL', 'label' => 'Japan Airlines', 'logo' => ''],
      ['code' => 'NH', 'label' => 'ANA', 'logo' => ''],
      ['code' => 'KE', 'label' => 'Korean Air', 'logo' => ''],
      ['code' => 'SQ', 'label' => 'Singapore Airlines', 'logo' => ''],
      ['code' => 'SA', 'label' => 'South African Airways', 'logo' => ''],
      ['code' => 'ET', 'label' => 'Ethiopian Airlines', 'logo' => ''],
      ['code' => 'MS', 'label' => 'EgyptAir', 'logo' => ''],
      ['code' => 'SV', 'label' => 'Saudia', 'logo' => ''],
      ['code' => 'RJ', 'label' => 'Royal Jordanian', 'logo' => ''],
      ['code' => 'ME', 'label' => 'Middle East Airlines', 'logo' => ''],
      ['code' => 'KU', 'label' => 'Kuwait Airways', 'logo' => ''],
      ['code' => 'WY', 'label' => 'Oman Air', 'logo' => ''],
      ['code' => 'GF', 'label' => 'Gulf Air', 'logo' => ''],
      ['code' => 'PK', 'label' => 'Pakistan International Airlines', 'logo' => ''],
      ['code' => 'AI', 'label' => 'Air India', 'logo' => ''],
      ['code' => '6E', 'label' => 'IndiGo', 'logo' => ''],
      ['code' => 'MH', 'label' => 'Malaysia Airlines', 'logo' => ''],
      ['code' => 'TG', 'label' => 'Thai Airways', 'logo' => ''],
      ['code' => 'VN', 'label' => 'Vietnam Airlines', 'logo' => ''],
      ['code' => 'PR', 'label' => 'Philippine Airlines', 'logo' => ''],
      ['code' => 'CI', 'label' => 'China Airlines', 'logo' => ''],
      ['code' => 'MU', 'label' => 'China Eastern', 'logo' => ''],
      ['code' => 'CA', 'label' => 'Air China', 'logo' => ''],
      ['code' => 'CZ', 'label' => 'China Southern', 'logo' => ''],
      ['code' => 'HU', 'label' => 'Hainan Airlines', 'logo' => ''],
      ['code' => 'SU', 'label' => 'Aeroflot', 'logo' => ''],
    ],
    'classes' => [
      'Economy',
      'Economy-Promo',
      'Economy-Light',
      'Economy-Plus',
      'Economy-Max',
      'Economy-Standard',
      'Economy-Full',
      'Premium Economy',
      'Business (Executiva)',
      'First Class',
    ],
    'baggage' => [
      'Somente Bagagem de Mao',
      'Bag de Mao + 1 Bag Despachadas 23K',
      'Sem Bag De Mao + Sem Bag Despachads',
      'Bag de Mao + 2 Bag Despachadas 23K',
      'Bag de Mao + 2 Bag Despachadas 32K',
    ],
  ];
}

function list_store_path(string $name): string {
  $allowed = ['airlines', 'classes', 'baggage'];
  if (!in_array($name, $allowed, true)) {
    throw new InvalidArgumentException('Invalid list name.');
  }
  return list_store_dir() . '/' . $name . '.json';
}

function list_store_decode(string $name): array {
  $path = list_store_path($name);
  if (!is_file($path)) {
    return list_store_defaults()[$name];
  }

  $json = file_get_contents($path);
  $rows = json_decode((string)$json, true);
  if (!is_array($rows)) {
    return list_store_defaults()[$name];
  }

  if ($name === 'airlines') {
    $out = [];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $code = strtoupper(trim((string)($row['code'] ?? $row['value'] ?? '')));
      $label = trim((string)($row['label'] ?? $row['text'] ?? ''));
      if ($label !== '' && strpos($label, '–') !== false) {
        [, $label] = array_map('trim', explode('–', $label, 2));
      }
      $logo = trim((string)($row['logo'] ?? ''));
      if ($code === '' || $label === '') continue;
      $out[] = [
        'code' => mb_substr($code, 0, 3, 'UTF-8'),
        'label' => mb_substr($label, 0, 120, 'UTF-8'),
        'logo' => mb_substr($logo, 0, 255, 'UTF-8'),
      ];
    }
    return $out ?: list_store_defaults()[$name];
  }

  $out = [];
  foreach ($rows as $row) {
    $value = is_array($row) ? (string)($row['text'] ?? $row['value'] ?? '') : (string)$row;
    if (strpos($value, '–') !== false && preg_match('/^[A-Z0-9]{1,5}\s+–\s+/u', $value)) {
      [, $value] = array_map('trim', explode('–', $value, 2));
    }
    $value = trim($value);
    if ($value === '') continue;
    $out[] = mb_substr($value, 0, 160, 'UTF-8');
  }
  return array_values(array_unique($out)) ?: list_store_defaults()[$name];
}

function list_store_all(): array {
  return [
    'airlines' => list_store_decode('airlines'),
    'classes' => list_store_decode('classes'),
    'baggage' => list_store_decode('baggage'),
  ];
}

function list_store_save(string $name, array $rows): void {
  $dir = list_store_dir();
  if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
  }

  if ($name === 'airlines') {
    $clean = [];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $code = strtoupper(trim((string)($row['code'] ?? '')));
      $label = trim((string)($row['label'] ?? ''));
      $logo = trim((string)($row['logo'] ?? ''));
      if ($code === '' || $label === '') continue;
      $clean[$code] = [
        'code' => mb_substr($code, 0, 3, 'UTF-8'),
        'label' => mb_substr($label, 0, 120, 'UTF-8'),
        'logo' => mb_substr($logo, 0, 255, 'UTF-8'),
      ];
    }
    $rows = array_values($clean);
  } else {
    $clean = [];
    foreach ($rows as $row) {
      $value = trim((string)$row);
      if ($value === '') continue;
      $clean[$value] = mb_substr($value, 0, 160, 'UTF-8');
    }
    $rows = array_values($clean);
  }

  $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  if ($json === false) {
    throw new RuntimeException('Could not encode list JSON.');
  }
  if (file_put_contents(list_store_path($name), $json . "\n", LOCK_EX) === false) {
    throw new RuntimeException('Could not save list file.');
  }
}
