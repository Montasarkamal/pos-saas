<?php
// refunds/index.php — modern layout (Phase 2)

require_once '../inc/db.php';
require_once '../inc/auth.php';
require_once '../inc/helpers.php';
require_once '../inc/ui.php';
require_login();

$statusFilter   = $_GET['status']  ?? '';
$clienteSearch  = $_GET['cliente'] ?? '';

$statuses = [
    'SOLICITADO'            => 'Solicitado',
    'EM_ANALISE'            => 'Em análise',
    'AGUARDANDO_FORNECEDOR' => 'Aguardando fornecedor',
    'APROVADO'              => 'Aprovado',
    'NEGADO'                => 'Negado',
    'REEMBOLSADO'           => 'Reembolsado',
    'PAGO'                  => 'Pago',
];

$pageTitle = 'Reembolsos';

if (!has_table($pdo, 'refunds')) {
    ob_start();
    ?>
    <div class="card p-8 text-center">
        <p class="text-sm font-medium text-amber-700">Tabela de reembolsos não está disponível na base atual.</p>
    </div>
    <?php
    $body = ob_get_clean();
    require __DIR__ . '/../inc/layout.php';
    exit;
}

$selects = ['r.*'];
$joins = [];

if (has_table($pdo, 'clients')) {
    $selects[] = 'c.name AS client_name';
    $joins[] = 'LEFT JOIN clients c ON c.id = r.client_id AND c.agency_id = r.agency_id';
} else {
    $selects[] = 'NULL AS client_name';
}

if (has_table($pdo, 'suppliers')) {
    $selects[] = 's.name AS supplier_name';
    $joins[] = 'LEFT JOIN suppliers s ON s.id = r.supplier_id AND s.agency_id = r.agency_id';
} else {
    $selects[] = 'NULL AS supplier_name';
}

if (has_table($pdo, 'invoices')) {
    $selects[] = 'i.invoice_number';
    $joins[] = 'LEFT JOIN invoices i ON i.id = r.invoice_id AND i.agency_id = r.agency_id';
} else {
    $selects[] = 'NULL AS invoice_number';
}

if (has_table($pdo, 'passengers')) {
    $selects[] = 'p.name AS passenger_name';
    $joins[] = 'LEFT JOIN passengers p ON p.id = r.passenger_id AND p.agency_id = r.agency_id';
} else {
    $selects[] = 'NULL AS passenger_name';
}

$sql = "
    SELECT " . implode(",\n           ", $selects) . "
      FROM refunds r
      " . implode("\n ", $joins) . "
     WHERE r.agency_id = :agency_id
";

$params = [':agency_id' => agency_id()];

if ($statusFilter !== '') {
    $sql .= " AND r.status = :status ";
    $params[':status'] = $statusFilter;
}

if ($clienteSearch !== '' && has_table($pdo, 'clients')) {
    $sql .= " AND c.name LIKE :cliente ";
    $params[':cliente'] = '%' . $clienteSearch . '%';
}

$sql .= " ORDER BY r.data_atualizacao DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$refunds = $stmt->fetchAll(PDO::FETCH_ASSOC);

function refund_badge(string $status): string {
    $s = strtoupper(trim($status));
    return match ($s) {
        'REEMBOLSADO', 'PAGO' => 'badge-soft bg-emerald-100 text-emerald-700',
        'NEGADO'              => 'badge-soft bg-red-100 text-red-700',
        'APROVADO'            => 'badge-soft bg-teal-100 text-teal-700',
        'AGUARDANDO_FORNECEDOR' => 'badge-soft bg-amber-100 text-amber-700',
        'EM_ANALISE'          => 'badge-soft bg-blue-100 text-blue-700',
        default               => 'badge-soft bg-ink-100 text-ink-600',
    };
}

ob_start();
?>
<!-- Header -->
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h2 class="text-xl font-bold text-ink-950">Gestão de Reembolsos</h2>
        <p class="mt-0.5 text-sm text-ink-500">Solicitações de reembolso das vendas.</p>
    </div>
    <a href="create.php" class="btn-primary">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"></path><path d="M5 12h14"></path></svg>
        Nova Solicitação de Reembolso
    </a>
</div>

<!-- Filtros -->
<form method="get" class="card mb-5 p-5">
    <div class="grid grid-cols-1 items-end gap-4 md:grid-cols-2 lg:grid-cols-4">
        <div>
            <label class="label-field" for="f-status">Status</label>
            <select id="f-status" class="select-field" name="status">
                <option value="">Todos</option>
                <?php foreach ($statuses as $key => $label): ?>
                    <option value="<?= htmlspecialchars($key) ?>" <?= $statusFilter === $key ? 'selected' : '' ?>>
                        <?= htmlspecialchars($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="label-field" for="f-cliente">Cliente</label>
            <input id="f-cliente" class="input-field" type="text" name="cliente" value="<?= htmlspecialchars($clienteSearch) ?>" placeholder="Buscar por cliente">
        </div>
        <div class="flex gap-2 md:col-span-2">
            <button type="submit" class="btn-primary flex-1 sm:flex-none sm:px-8">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 3 2 3 10 12.46V19l4 2v-8.54L22 3z"></path></svg>
                Filtrar
            </button>
            <a href="index.php" class="btn-ghost">Limpar</a>
        </div>
    </div>
</form>

<div class="card overflow-hidden">
    <div class="border-b border-ink-100 px-5 py-4">
        <h3 class="text-sm font-bold text-ink-950">Solicitações de Reembolso</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="table-modern min-w-[1000px]">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Cliente</th>
                    <th>Passageiro</th>
                    <th>Tipo</th>
                    <th>Venda</th>
                    <th class="text-right">Valor Pago</th>
                    <th class="text-right">Valor Reembolsável</th>
                    <th>Status</th>
                    <th>Atualizado em</th>
                    <th class="sticky right-0 bg-white text-right"></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($refunds)): ?>
                    <tr>
                        <td colspan="10" class="px-4 py-10 text-center text-sm text-ink-400">Nenhuma solicitação encontrada.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($refunds as $r): ?>
                        <tr>
                            <td class="text-ink-400"><?= (int)$r['id'] ?></td>
                            <td class="font-medium text-ink-950"><?= htmlspecialchars($r['client_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($r['passenger_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($r['type']) ?></td>
                            <td class="font-mono text-xs"><?= htmlspecialchars($r['invoice_number'] ?? '-') ?></td>
                            <td class="text-right tabular-nums">R$ <?= number_format($r['valor_pago'], 2, ',', '.') ?></td>
                            <td class="text-right tabular-nums">R$ <?= number_format($r['valor_reembolsavel'], 2, ',', '.') ?></td>
                            <td>
                                <span class="<?= refund_badge((string)$r['status']) ?>">
                                    <?= htmlspecialchars($statuses[$r['status']] ?? $r['status']) ?>
                                </span>
                            </td>
                            <td class="text-ink-500"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($r['data_atualizacao']))) ?></td>
                            <td class="sticky right-0 bg-white text-right">
                                <a class="btn-soft border-brand-200 text-brand-700 hover:bg-brand-50" href="show.php?id=<?= (int)$r['id'] ?>">Detalhes</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
$body = ob_get_clean();
require __DIR__ . '/../inc/layout.php';