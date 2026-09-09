<?php
// inc/public_link.php
require_once __DIR__.'/db.php';

function public_link_secret(): string {
  $secret = env_value('PUBLIC_LINK_SECRET');
  if (!$secret || strlen($secret) < 32) {
    error_log('PUBLIC_LINK_SECRET is missing or too short.');
    http_response_code(500);
    exit('Erro de configuração do link público.');
  }
  return $secret;
}

function sign_params(array $params, string $type): string {
  ksort($params);
  $payload = $type . '|' . http_build_query($params);
  return hash_hmac('sha256', $payload, public_link_secret());
}

function build_public_url(string $type, int $invoiceId, int $ttlHours=72): string {
  $path = $type === 'invoice' ? '/sales/print.php' : '/sales/voucher.php';
  $ttlHours = max(1, min($ttlHours, 24 * 7));
  $expiresAt = time() + ($ttlHours * 3600);
  $qs = http_build_query([
    'id' => $invoiceId,
    'exp' => $expiresAt,
    'token' => make_public_token($invoiceId, $type, $expiresAt),
  ]);
  return "{$path}?{$qs}";
}

function make_public_token(int $invoiceId, string $type, int $expiresAt): string {
  return sign_params(['id' => $invoiceId, 'exp' => $expiresAt], $type);
}

function check_public_token(int $invoiceId, string $type, string $token): bool {
  $expiresAt = (int)($_GET['exp'] ?? 0);
  if ($invoiceId <= 0 || $expiresAt < time() || $expiresAt > time() + (24 * 7 * 3600) || $token === '') return false;
  return hash_equals(make_public_token($invoiceId, $type, $expiresAt), $token);
}

function assert_valid_signature(string $type): array {
  $id  = (int)($_GET['id'] ?? 0);
  $exp = (int)($_GET['exp'] ?? 0);
  $sig = (string)($_GET['sig'] ?? '');

  if ($id<=0 || $exp<=0 || !$sig) fail_public('Link inválido.');
  if ($exp < time())            fail_public('Link expirado.');

  $check = sign_params(['id'=>$id,'exp'=>$exp], $type);
  if (!hash_equals($check, $sig)) fail_public('Assinatura inválida.');

  return ['id'=>$id,'exp'=>$exp];
}

function fail_public(string $msg){
  http_response_code(403);
  echo "<!doctype html><meta charset='utf-8'><title>Acesso negado</title>
        <link rel='stylesheet' href='https://cdn.jsdelivr.net/npm/@tabler/core@1.4.0/dist/css/tabler.min.css'>
        <div class='container py-6'><div class='alert alert-danger'>{$msg}</div>
        <a class='btn btn-primary' href='/'>Voltar</a></div>";
  exit;
}
