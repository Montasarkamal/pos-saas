<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/openai-config.php';

$input = json_decode(file_get_contents('php://input'), true);
$rawText = trim((string)($input['raw_text'] ?? ''));

if ($rawText === '') {
    echo json_encode([
        'ok' => false,
        'error' => 'Texto vazio.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = [
    'model' => 'gpt-4o-mini',
    'messages' => [
        [
            'role' => 'system',
            'content' => 'Extraia dados de reserva e responda somente em JSON válido.'
        ],
        [
            'role' => 'user',
            'content' => "Texto:\n\n" . $rawText
        ]
    ],
    'response_format' => [
        'type' => 'json_schema',
        'json_schema' => [
            'name' => 'booking_extract',
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'pnr' => ['type' => 'string'],
                    'ticket_number' => ['type' => 'string'],
                    'issue_date' => ['type' => 'string']
                ],
                'required' => ['pnr', 'ticket_number', 'issue_date']
            ]
        ]
    ]
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENAI_API_KEY,
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_TIMEOUT => 60,
]);

$response = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $curlErr) {
    echo json_encode([
        'ok' => false,
        'error' => 'cURL error: ' . $curlErr
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($response, true);

if (!is_array($data)) {
    echo json_encode([
        'ok' => false,
        'error' => 'Resposta inválida da OpenAI',
        'raw' => $response
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($httpCode >= 400) {
    echo json_encode([
        'ok' => false,
        'error' => $data['error']['message'] ?? 'Erro da OpenAI',
        'raw' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$content = $data['choices'][0]['message']['content'] ?? '';

$parsed = json_decode($content, true);

if (!is_array($parsed)) {
    echo json_encode([
        'ok' => false,
        'error' => 'A OpenAI respondeu, mas não retornou JSON parseável.',
        'content' => $content
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

echo json_encode([
    'ok' => true,
    'data' => $parsed
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
