<?php
// suppliers/show.php — Visualização Detalhada do Fornecedor
declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php'; 
require_once __DIR__ . '/../inc/auth.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
[$agencyCondition, $agencyParams] = agency_scope_sql('agency_id');

// 5. جلب بيانات المورد الأساسية
$st = $pdo->prepare("SELECT * FROM suppliers WHERE id = ? AND $agencyCondition");
$st->execute(array_merge([$id], $agencyParams));
$s = $st->fetch(PDO::FETCH_ASSOC);

if (!$s) { 
    http_response_code(404); 
    die('Fornecedor não encontrado (ID: ' . $id . ')'); 
}

$token = csrf_token();

// 6. جلب آخر 10 فواتير مرتبطة (اختياري)
$invoices = [];
try {
    $si = $pdo->prepare("SELECT id, invoice_number, issue_date, status, total_amount 
                         FROM invoices WHERE supplier_id = ? AND " . (is_superadmin() ? "1=1" : "agency_id = ?") . " ORDER BY id DESC LIMIT 10");
    $si->execute(array_merge([$id], is_superadmin() ? [] : [agency_id()]));
    $invoices = $si->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // نترك المصفوفة فارغة إذا لم يكن جدول الفواتير جاهزاً
}

$pageTitle = 'Fornecedor: ' . (string)($s['name'] ?? '');
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
ob_start();
require_once __DIR__ . '/../inc/ui.php';
?>
<!-- Header -->
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
  <div>
    <p class="text-xs font-bold uppercase tracking-wider text-brand-600">Fornecedor</p>
    <h2 class="text-xl font-bold text-ink-950"><?= h($s['name']) ?></h2>
    <p class="mt-0.5 text-sm text-ink-500">Visualizando informações completas do ID #<?= (int)$id ?></p>
  </div>
  <div class="flex flex-wrap items-center gap-2">
    <a class="btn-ghost" href="/suppliers/index.php">
      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"></polyline></svg>
      Voltar
    </a>
    <a class="btn-primary" href="/suppliers/edit.php?id=<?= (int)$id ?>">
      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
      Editar
    </a>
    <form action="/suppliers/delete.php" method="post" class="inline" onsubmit="return confirm('Excluir este fornecedor?');">
      <input type="hidden" name="csrf" value="<?= h($token) ?>">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <button class="btn-ghost hover:bg-red-50" type="submit">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
        Excluir
      </button>
    </form>
  </div>
</div>

<!-- Layout principal -->
<div class="grid grid-cols-1 gap-4 xl:grid-cols-12">
  <!-- Informações do cadastro -->
  <div class="card overflow-hidden xl:col-span-8">
    <div class="border-b border-ink-100 px-5 py-4">
      <h3 class="text-sm font-bold text-ink-950">Informações do Cadastro</h3>
    </div>
    <div class="p-5">
      <div class="grid grid-cols-1 gap-x-6 gap-y-5 sm:grid-cols-2">
        <div>
          <p class="text-[11px] font-bold uppercase tracking-wider text-ink-400">Nome / Razão Social</p>
          <p class="mt-1 text-lg font-bold text-ink-950"><?= h($s['name']) ?></p>
        </div>
        <div>
          <p class="text-[11px] font-bold uppercase tracking-wider text-ink-400"><?= $s['supplier_type'] === 'pj' ? 'CNPJ' : 'CPF' ?></p>
          <p class="mt-1 font-semibold text-ink-950"><?= h($s['document']) ?></p>
        </div>
        <div>
          <p class="text-[11px] font-bold uppercase tracking-wider text-ink-400">Tipo</p>
          <p class="mt-1"><span class="badge-soft bg-sky-100 text-sky-700"><?= $s['supplier_type'] === 'pj' ? 'Pessoa Jurídica' : 'Pessoa Física' ?></span></p>
        </div>
        <div>
          <p class="text-[11px] font-bold uppercase tracking-wider text-ink-400">Status</p>
          <p class="mt-1">
            <?php if ((int)$s['is_active'] === 1): ?>
              <span class="badge-soft bg-emerald-100 text-emerald-700">Ativo</span>
            <?php else: ?>
              <span class="badge-soft bg-red-100 text-red-700">Inativo</span>
            <?php endif; ?>
          </p>
        </div>
        <div>
          <p class="text-[11px] font-bold uppercase tracking-wider text-ink-400">Telefone</p>
          <p class="mt-1 font-semibold"><?= h($s['phone'] ?: '—') ?></p>
        </div>
        <div>
          <p class="text-[11px] font-bold uppercase tracking-wider text-ink-400">E-mail</p>
          <p class="mt-1"><?= h($s['email'] ?: '—') ?></p>
        </div>
        <div class="sm:col-span-2">
          <p class="text-[11px] font-bold uppercase tracking-wider text-ink-400">Endereço</p>
          <p class="mt-1"><?= h($s['address'] ?: '—') ?></p>
        </div>
        <div class="sm:col-span-2">
          <p class="text-[11px] font-bold uppercase tracking-wider text-ink-400">Observações</p>
          <p class="mt-1 rounded-xl bg-ink-50 p-3 text-sm"><?= nl2br(h($s['notes'] ?? 'Nenhuma observação registrada.')) ?></p>
        </div>
      </div>
    </div>
  </div>

  <!-- Coluna direita -->
  <div class="grid gap-4 xl:col-span-4">
    <div class="card overflow-hidden">
      <div class="border-b border-ink-100 px-5 py-4">
        <h3 class="text-sm font-bold text-ink-950">Comunicação</h3>
      </div>
      <div class="p-5">
        <div class="grid gap-2">
          <?php if (!empty($s['phone'])): ?>
            <a class="btn-ghost w-full hover:bg-emerald-50" href="https://wa.me/<?= preg_replace('/\D+/', '', $s['phone']) ?>" target="_blank" rel="noopener">Chamar no WhatsApp</a>
          <?php endif; ?>
          <?php if (!empty($s['email'])): ?>
            <a class="btn-ghost w-full" href="mailto:<?= h($s['email']) ?>">Enviar E-mail</a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="card overflow-hidden">
      <div class="p-6 text-center">
        <p class="text-[11px] font-bold uppercase tracking-wider text-ink-400">Histórico</p>
        <p class="mt-2 text-4xl font-bold tabular-nums text-ink-950"><?= count($invoices) ?></p>
        <p class="mt-1 text-xs text-ink-400">Faturas recentes encontradas</p>
      </div>
    </div>
  </div>

  <!-- Últimas faturas -->
  <div class="card overflow-hidden xl:col-span-12">
    <div class="border-b border-ink-100 px-5 py-4">
      <h3 class="text-sm font-bold text-ink-950">Últimas Faturas do Fornecedor</h3>
    </div>
    <div class="overflow-x-auto">
      <table class="table-modern min-w-[820px]">
        <thead>
          <tr>
            <th>Fatura #</th>
            <th>Emissão</th>
            <th>Status</th>
            <th class="text-right">Valor</th>
            <th class="text-right"></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($invoices): foreach ($invoices as $inv): ?>
            <tr>
              <td><?= h($inv['invoice_number']) ?></td>
              <td><?= date('d/m/Y', strtotime($inv['issue_date'])) ?></td>
              <td><span class="<?= ui_status_badge((string)$inv['status']) ?>"><?= htmlspecialchars(ui_status_label((string)$inv['status'])) ?></span></td>
              <td class="text-right font-semibold tabular-nums"><?= number_format((float)$inv['total_amount'], 2, ',', '.') ?></td>
              <td class="text-right">
                <div class="flex justify-end">
                  <a class="btn-xs border border-ink-200 bg-white text-ink-700 hover:bg-ink-50" href="/sales/show.php?id=<?= (int)$inv['id'] ?>">Ver</a>
                </div>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="5" class="px-4 py-8 text-center text-sm text-ink-400">Nenhuma fatura encontrada para este fornecedor.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php
$body = ob_get_clean();
require __DIR__ . '/../inc/layout.php';