<?php
declare(strict_types=1);
require_once __DIR__ . '/../../inc/db.php';

$openaiApiKey = env_value('OPENAI_API_KEY');
if (!$openaiApiKey) {
    error_log('OPENAI_API_KEY is not configured.');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Configuração da IA ausente.'], JSON_UNESCAPED_UNICODE);
    exit;
}

define('OPENAI_API_KEY', $openaiApiKey);
