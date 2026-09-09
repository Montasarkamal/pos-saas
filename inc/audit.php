<?php
declare(strict_types=1);

if (!function_exists('ensure_audit_table')) {
    function ensure_audit_table(PDO $pdo): void {
        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            agency_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(80) NOT NULL,
            entity_type VARCHAR(80) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            details_json TEXT NULL,
            ip_address VARCHAR(45) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_audit_agency_created (agency_id, created_at),
            INDEX idx_audit_entity (entity_type, entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('audit_log')) {
    function audit_log(PDO $pdo, string $action, string $entityType, int $entityId, array $details = []): void {
        try {
            // DDL commits implicitly in MySQL, so transactional callers must call
            // ensure_audit_table() before beginTransaction().
            if (!$pdo->inTransaction()) ensure_audit_table($pdo);

            $st = $pdo->prepare("INSERT INTO audit_logs
                (agency_id, user_id, action, entity_type, entity_id, details_json, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $st->execute([
                function_exists('agency_id') ? agency_id() : 0,
                function_exists('user_id') ? user_id() : 0,
                $action,
                $entityType,
                $entityId,
                json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            ]);
        } catch (Throwable $e) {
            error_log('audit_log: ' . $e->getMessage());
        }
    }
}
