<?php
declare(strict_types=1);

require __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/helpers.php';

$columns = [
    'service_type' => "ALTER TABLE aux_services ADD COLUMN service_type varchar(50) DEFAULT 'other' AFTER invoice_id",
    'supplier_id' => "ALTER TABLE aux_services ADD COLUMN supplier_id int(11) DEFAULT NULL AFTER service_type",
    'start_date' => "ALTER TABLE aux_services ADD COLUMN start_date date DEFAULT NULL AFTER service",
    'end_date' => "ALTER TABLE aux_services ADD COLUMN end_date date DEFAULT NULL AFTER start_date",
    'cost_value' => "ALTER TABLE aux_services ADD COLUMN cost_value decimal(12,2) NOT NULL DEFAULT 0.00 AFTER value",
    'supplier_paid' => "ALTER TABLE aux_services ADD COLUMN supplier_paid tinyint(1) NOT NULL DEFAULT 0 AFTER cost_value",
    'details' => "ALTER TABLE aux_services ADD COLUMN details text DEFAULT NULL AFTER supplier_paid",
];

foreach ($columns as $column => $sql) {
    if (!has_column($pdo, 'aux_services', $column)) {
        $pdo->exec($sql);
        echo "added {$column}\n";
    }
}

if (!has_column($pdo, 'aux_services', 'service_type')) {
    echo "migration incomplete\n";
    exit(1);
}

$pdo->exec("UPDATE aux_services SET service_type = 'other' WHERE service_type IS NULL OR service_type = ''");

echo "aux service sales columns ready\n";
