<?php

require_once __DIR__ . '/../../inc/invoices_lib.php';
require_once __DIR__ . '/../../inc/helpers.php';

/**
 * Obtém a conexão PDO de forma robusta dentro do harness do Pest.
 *
 * O Pest executa os arquivos de teste em um escopo próprio (não global), então
 * um `require` no topo do arquivo NÃO deixa $pdo no $GLOBALS. Aqui, o require de
 * inc/db.php acontece DENTRO do corpo desta função: as variáveis criadas pelo
 * arquivo incluído viram variáveis locais da função, e o static guarda a conexão.
 */
function test_pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        require __DIR__ . '/../../inc/db.php'; // define $pdo no escopo desta função
    }
    return $pdo;
}

/**
 * Estas provas usam o banco local de desenvolvimento, mas TODA mutação roda
 * dentro de uma transação que é revertida ao final (ROLLBACK) — nenhuma linha
 * é persistida durante os testes.
 */
it('gera número de fatura no formato YY####', function () {
    $pdo = test_pdo();
    $pdo->beginTransaction();
    try {
        $n = next_invoice_number($pdo);
        expect((string)$n)->toMatch('/^\d{6}$/');
        expect(substr($n, 0, 2))->toBe(date('y'));
    } finally {
        $pdo->rollBack();
    }
});

it('incrementa o número a cada chamada dentro da mesma transação', function () {
    $pdo = test_pdo();
    $pdo->beginTransaction();
    try {
        $n1 = next_invoice_number($pdo);
        $n2 = next_invoice_number($pdo);
        $n3 = next_invoice_number($pdo);

        expect($n2)->toBe((string)((int)$n1 + 1));
        expect($n3)->toBe((string)((int)$n2 + 1));
    } finally {
        $pdo->rollBack();
    }
});

it('não grava nenhuma linha quando a transação é revertida', function () {
    $pdo = test_pdo();

    $pdo->beginTransaction();
    $before = (int)$pdo->query('SELECT COUNT(*) FROM invoice_counters')->fetchColumn();
    next_invoice_number($pdo);
    $pdo->rollBack();
    $after = (int)$pdo->query('SELECT COUNT(*) FROM invoice_counters')->fetchColumn();

    expect($after)->toBe($before);
});

it('detecta tabelas e colunas do schema atual', function () {
    $pdo = test_pdo();

    expect(has_table($pdo, 'invoices'))->toBeTrue();
    expect(has_table($pdo, 'tabela_inexistente'))->toBeFalse();

    expect(has_column($pdo, 'invoices', 'margin_value'))->toBeTrue();
    expect(has_column($pdo, 'invoices', 'coluna_inexistente'))->toBeFalse();
});