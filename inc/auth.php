<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/public_link.php';

if (!function_exists('auth_has_column')) {
    function auth_has_column(PDO $pdo, string $table, string $column): bool {
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
            error_log('auth_has_column: ' . $e->getMessage());
            return false;
        }
    }
}

/**
 * Check if user is logged in
 */
function is_logged_in(): bool {
    return !empty($_SESSION['uid']);
}

/**
 * Require login (SaaS safe)
 */
function require_login(): void {
    if (empty($_SESSION['uid']) || empty($_SESSION['agency_id'])) {
        header('Location: /login.php');
        exit;
    }
}

/**
 * Get current user ID
 */
function user_id(): int {
    return (int)($_SESSION['uid'] ?? 0);
}

function getCurrentUserId(): int {
    return user_id();
}

/**
 * Get current agency ID (IMPORTANT)
 */
function agency_id(): int {
    return (int)($_SESSION['agency_id'] ?? 0);
}

function is_superadmin(): bool {
    return ($_SESSION['role'] ?? '') === 'superadmin';
}

/**
 * Check role
 */
function is_admin(): bool {
    return in_array($_SESSION['role'] ?? '', ['admin', 'superadmin'], true);
}

/**
 * Require admin only
 */
function require_admin(): void {
    if (!is_admin()) {
        http_response_code(403);
        die('Access denied');
    }
}

function require_role_admin(): void {
    require_admin();
}

function agency_scope_sql(string $column = 'agency_id'): array {
    if (is_superadmin()) {
        return ['1=1', []];
    }

    return [$column . ' = ?', [agency_id()]];
}

function is_master_user(array $user): bool {
    $role = strtolower(trim((string)($user['role'] ?? '')));
    return $role === 'superadmin';
}

function csrf_token(): string {
    if (empty($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_check(?string $token): bool {
    return isset($_SESSION['_csrf']) && is_string($token) && hash_equals($_SESSION['_csrf'], $token);
}
