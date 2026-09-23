<?php
/**
 * KAMALTUR POS — demo data seeder (dev only)
 *
 * Inserts realistic Brazilian demo data so the modern UI can be reviewed:
 * clients, suppliers, invoices (current month), trips/passengers/segments
 * and refunds. Safe to run multiple times — it only adds rows when the
 * table is empty for the given agency.
 *
 * Usage: php local-setup/seed-demo-data.php
 */

declare(strict_types=1);

require __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/invoices_lib.php';

$agencyId = 1;

function demo_agency_has_data(PDO $pdo, int $agencyId, string $table): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE agency_id = ?");
    $st->execute([$agencyId]);
    return (int)$st->fetchColumn() > 0;
}

$out = [];

// ---- Clients -----------------------------------------------------------
if (!demo_agency_has_data($pdo, $agencyId, 'clients')) {
    $clients = [
        ['pf', 'Ana Beatriz Souza', '382.456.788-90', '(11) 98811-2233', 'ana.souza@gmail.com', '1988-03-14'],
        ['pf', 'Carlos Eduardo Lima', '177.204.665-33', '(21) 99722-4410', 'carlos.lima@gmail.com', '1979-11-02'],
        ['pj', 'Hotel Mar Azul Ltda', '12.345.678/0001-90', '(41) 3333-2211', 'contato@marazul.com.br', null],
        ['pf', 'Fernanda Rocha', '299.103.447-52', '(31) 98210-7788', 'fefe.rocha@outlook.com', '1992-07-25'],
    ];
    $ins = $pdo->prepare("INSERT INTO clients (client_type, name, document, phone, email, birth_date, agency_id, created_by) VALUES (?,?,?,?,?,?,?,1)");
    foreach ($clients as $c) {
        $ins->execute($c);
    }
    $out[] = 'clients +' . count($clients);
}

// ---- Suppliers ---------------------------------------------------------
if (!demo_agency_has_data($pdo, $agencyId, 'suppliers')) {
    $suppliers = [
        ['GOLLOG S.A.', '06.164.253/0001-87', '(11) 4003-1212'],
        ['LATAM Airlines Brasil', '02.012.862/0001-60', '(11) 4002-5700'],
        ['Azul Linhas Aereas', '09.305.994/0001-29', '(11) 4003-1118'],
        ['Hotel Transamerica', '54.693.391/0001-04', '(11) 3432-8750'],
    ];
    $ins = $pdo->prepare("INSERT INTO suppliers (supplier_type, name, document, phone, agency_id) VALUES ('pj',?,?,?,?)");
    foreach ($suppliers as $s) {
        $ins->execute([...$s, $agencyId]);
    }
    $out[] = 'suppliers +' . count($suppliers);
}

// ---- Invoices (current month) -----------------------------------------
$invoiceCount = demo_agency_has_data($pdo, $agencyId, 'invoices') ? 0 : 6;
if ($invoiceCount > 0) {
    $clientIds = $pdo->query("SELECT id FROM clients WHERE agency_id={$agencyId} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $supplierIds = $pdo->query("SELECT id FROM suppliers WHERE agency_id={$agencyId} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

    $day = (int)date('j');
    $month = date('m');
    $year = date('Y');
    $today = date('Y-m-d');
    $design = [
        // [clientIdx, supplierIdx, total, status, pnr, travel (days ahead), margin]
        [0, 1, 4850.00, 'pago',      'KMT2X9A', 12,  720.00],
        [1, 2, 3120.50, 'nao pago',  'KMT7B3F', 8,   410.00],
        [2, 0, 2890.00, 'parcial',   'KMT1C4D', 20,  560.00],
        [3, 3, 12450.00, 'pago',     'KMT9E1H', 45,  1890.00],
        [0, 1, 1980.00, 'pago',      'KMT4K8L', 3,   120.00],
        [3, 2, 6475.00, 'nao pago',  'KMT6M2N', 30,  905.00],
    ];

    $clientById = [];
    $st = $pdo->prepare("SELECT id, name FROM clients WHERE agency_id=?");
    $st->execute([$agencyId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $clientById[(int)$r['id']] = $r['name']; }

    foreach ($design as $i => $d) {
        [$cIdx, $sIdx, $total, $status, $pnr, $ahead, $margin] = $d;
        $clientId   = $clientIds[$cIdx % count($clientIds)];
        $supplierId = $supplierIds[$sIdx % count($supplierIds)];
        $issueDay   = max(1, min($day - 1, 28));
        $issueDate  = sprintf('%s-%s-%02d', $year, $month, $issueDay - ($i % 3));
        $travelDate = date('Y-m-d', strtotime("+{$ahead} days"));

        $amountPaid = match ($status) {
            'pago'     => $total,
            'parcial'  => round($total * 0.5, 2),
            default    => 0.00,
        };
        $supplierTarifa = round($total - $margin, 2);
        $supplierLiquid = round($supplierTarifa * 0.9, 2);

        $pdo->beginTransaction();
        $number = next_invoice_number($pdo);
        $ins = $pdo->prepare("INSERT INTO invoices
            (invoice_number, client_id, supplier_id, issue_date, status, currency, pnr_code,
             travel_date, scope, passengers_total, supplier_tarifa, supplier_comissao,
             supplier_liquid, supplier_paid, service_paid, total_paid, margin_value,
             amount_paid, refund_rule, change_rule, agency_id, created_by, total_amount)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)");
        $ins->execute([
            $number, $clientId, $supplierId, $issueDate, $status, 'BRL', $pnr,
            $travelDate, 'nacional', 2, $supplierTarifa, round($margin * 0.35, 2),
            $supplierLiquid, 1, 0.00, $amountPaid, $margin,
            $amountPaid, 'nao reembolsavel', 'nao permite', $agencyId, $total,
        ]);
        $invoiceId = (int)$pdo->lastInsertId();

        $insT = $pdo->prepare("INSERT INTO invoice_trips
            (invoice_id, trip_no, pnr_code, travel_date, supplier_id, currency,
             supplier_tarifa, supplier_comissao, supplier_liquid, supplier_pay_status,
             supplier_paid_amount, agency_id) VALUES (?,1,?,?,?,?,?,?,?,?,?,?)");
        $insT->execute([$invoiceId, $pnr, $travelDate, $supplierId, 'BRL',
            $supplierTarifa, round($margin * 0.35, 2), $supplierLiquid, 'pago',
            $supplierLiquid, $agencyId]);

        $insP = $pdo->prepare("INSERT INTO passengers (invoice_id, name, ptype, ticket_no, value, agency_id) VALUES (?,?,?,?,?,?)");
        $paxName = $clientById[$clientId] ?? 'Passageiro';
        $insP->execute([$invoiceId, mb_strtoupper(mb_substr($paxName, 0, 60, 'UTF-8'), 'UTF-8') . '/MARIA', 'ADT', 'TKT-' . $number, round($total / 2, 2), $agencyId]);
        $insP->execute([$invoiceId, mb_strtoupper(mb_substr($paxName, 0, 60, 'UTF-8'), 'UTF-8'), 'ADT', 'TKT-' . $number . 'B', round($total / 2, 2), $agencyId]);

        $insS = $pdo->prepare("INSERT INTO segments (invoice_id, trip_id, airline_code, flight_no, `origin`, `destination`, `class`, baggage, record_locator, agency_id) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $insS->execute([$invoiceId, 1, 'LA', '8032', 'GRU', 'REC', 'E', '23KG', $pnr, $agencyId]);
        $insS->execute([$invoiceId, 1, 'LA', '8021', 'REC', 'GRU', 'E', '23KG', $pnr, $agencyId]);

        $pdo->commit();
    }
    $out[] = 'invoices +' . $invoiceCount . ' (+trips/passengers/segments)';
}

// ---- Refunds -----------------------------------------------------------
if (!demo_agency_has_data($pdo, $agencyId, 'refunds')) {
    $clientIds = $pdo->query("SELECT id FROM clients WHERE agency_id={$agencyId} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $refundDesign = [
        ['SOLICITADO', 'Desistencia do pacote', 2400.00, 1800.00],
        ['PAGO',       'Cancelamento voo',      950.00, 950.00],
        ['SOLICITADO', 'Mudanca de data',       3200.00, 2750.00],
        ['PAGO',       'Reembolso hotel',       1210.00, 1210.00],
    ];
    $ins = $pdo->prepare("INSERT INTO refunds
        (client_id, supplier_id, type, motivo, descricao, valor_pago, valor_reembolsavel,
         valor_recebido, status, data_solicitacao, data_pagamento, agency_id)
        VALUES (?,1,?,?,?,?,?,?,?,?,?,?)");
    foreach ($refundDesign as $i => $r) {
        [$status, $motivo, $pago, $reembolsavel] = $r;
        $recebido = $status === 'PAGO' ? $reembolsavel : 0.00;
        $dataPag  = $status === 'PAGO' ? date('Y-m-d', strtotime('-3 days')) : null;
        $ins->execute([
            $clientIds[$i % count($clientIds)], 'Cancelamento', $motivo, 'Gerado automaticamente pelo seed de demonstracao.',
            $pago, $reembolsavel, $recebido, $status, date('Y-m-d', strtotime("-$i days")), $dataPag, $agencyId,
        ]);
    }
    $out[] = 'refunds +' . count($refundDesign);
}

// ---- Summary -----------------------------------------------------------
echo "Demo data seeded for agency #{$agencyId}:" . PHP_EOL;
foreach ($out as $line) {
    echo "  - {$line}" . PHP_EOL;
}
echo $out === [] ? "  (nothing to seed — data already present)" . PHP_EOL : '';