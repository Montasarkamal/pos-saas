<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

function usd_bcb_cache_path(): string {
    return sys_get_temp_dir() . '/kamaltur_bcb_usd.json';
}

function usd_bcb_http_get(string $url): ?string {
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 4,
            'header' => "Accept: application/json\r\nUser-Agent: KAMALTUR-POS/1.0\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    return is_string($body) && $body !== '' ? $body : null;
}

$cachePath = usd_bcb_cache_path();
if (is_file($cachePath) && (time() - filemtime($cachePath)) < 3600) {
    readfile($cachePath);
    exit;
}

$tz = new DateTimeZone('America/Sao_Paulo');
$today = new DateTimeImmutable('now', $tz);
$result = [
    'ok' => false,
    'source' => 'Banco Central do Brasil',
    'currency' => 'USD',
    'rate' => null,
    'date' => null,
];

for ($i = 0; $i < 10; $i++) {
    $day = $today->modify("-{$i} days");
    $date = $day->format('m-d-Y');
    $encodedDate = rawurlencode("'" . $date . "'");
    $url = "https://olinda.bcb.gov.br/olinda/servico/PTAX/versao/v1/odata/CotacaoDolarDia(dataCotacao=@dataCotacao)?@dataCotacao={$encodedDate}&\$format=json";
    $body = usd_bcb_http_get($url);
    if ($body === null) continue;

    $json = json_decode($body, true);
    $rows = is_array($json['value'] ?? null) ? $json['value'] : [];
    if (!$rows) continue;

    $last = end($rows);
    $rate = isset($last['cotacaoVenda']) ? (float)$last['cotacaoVenda'] : 0.0;
    if ($rate <= 0) continue;

    $result = [
        'ok' => true,
        'source' => 'Banco Central do Brasil',
        'currency' => 'USD',
        'rate' => $rate,
        'date' => $day->format('Y-m-d'),
    ];
    break;
}

$payload = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($payload === false) {
    $payload = '{"ok":false,"source":"Banco Central do Brasil","currency":"USD","rate":null,"date":null}';
}
@file_put_contents($cachePath, $payload);
echo $payload;
