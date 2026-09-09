<?php
// inc/helpers.php — أدوات عامة موحّدة

if (!function_exists('brl')) {
  function brl($valor) {
    return 'R$ ' . number_format((float)$valor, 2, ',', '.');
  }
}

if (!function_exists('ymd_to_br')) {
  function ymd_to_br($s){
    if (!$s) return '—';
    $t = strtotime((string)$s);
    return $t ? date('d/m/Y', $t) : '—';
  }
}

if (!function_exists('tz_sp')) {
  function tz_sp(){
    static $z;
    return $z ?? ($z = new DateTimeZone('America/Sao_Paulo'));
  }
}

if (!function_exists('normalize_status')) {
  function normalize_status($s){
    $k = mb_strtolower(trim((string)$s), 'UTF-8');
    $map = [
      'paid' => 'pago', 'pago' => 'pago',
      'unpaid' => 'nao pago', 'não pago' => 'nao pago', 'nao pago' => 'nao pago',
      'partial' => 'pago parcial', 'pago parcial' => 'pago parcial',
      'cancelado' => 'cancelado', 'canceled' => 'cancelado',
    ];
    return $map[$k] ?? $k;
  }
}

if (!function_exists('status_badge_class')) {
  function status_badge_class($status): string {
    $status = normalize_status($status);
    return match ($status) {
      'pago', 'confirmed' => 'bg-success-lt',
      'pago parcial', 'pending' => 'bg-warning-lt',
      'nao pago' => 'bg-danger-lt',
      'cancelado', 'cancelled' => 'bg-danger-lt',
      'refunded' => 'bg-secondary-lt',
      default => 'bg-secondary-lt',
    };
  }
}

if (!function_exists('status_label')) {
  function status_label($status): string {
    return match (normalize_status($status)) {
      'pago' => 'Pago',
      'pago parcial' => 'Pago parcial',
      'nao pago' => 'Não pago',
      'cancelado' => 'Cancelado',
      'confirmed' => 'Confirmado',
      'pending' => 'Pendente',
      'refunded' => 'Reembolsado',
      default => trim((string)$status) ?: '—',
    };
  }
}

if (!function_exists('has_column')) {
  function has_column(PDO $pdo, string $table, string $column): bool {
    try {
      $st = $pdo->prepare("
        SELECT COUNT(*)
          FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
      ");
      $st->execute([$table, $column]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      error_log('has_column: '.$e->getMessage());
      return false;
    }
  }
}

if (!function_exists('has_table')) {
  function has_table(PDO $pdo, string $table): bool {
    try {
      $st = $pdo->prepare("
        SELECT COUNT(*)
          FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
      ");
      $st->execute([$table]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      error_log('has_table: '.$e->getMessage());
      return false;
    }
  }
}

if (!function_exists('month_window')) {
  function month_window(): array {
    $tz = tz_sp();
    $from = (new DateTime('first day of this month 00:00:00', $tz))->format('Y-m-d');
    $to   = (new DateTime('first day of next month 00:00:00',  $tz))->format('Y-m-d');
    return [$from, $to];
  }
}
