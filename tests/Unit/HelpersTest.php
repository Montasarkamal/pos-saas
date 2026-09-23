<?php

require_once __DIR__ . '/../../inc/helpers.php';

it('brl formata moeda com padrão brasileiro', function () {
    expect(brl(1234.5))->toBe('R$ 1.234,50');
    expect(brl(0))->toBe('R$ 0,00');
});

it('ymd_to_br converte datas e trata vazios', function () {
    expect(ymd_to_br('2026-01-05'))->toBe('05/01/2026');
    expect(ymd_to_br(''))->toBe('—');
    expect(ymd_to_br(null))->toBe('—');
    expect(ymd_to_br('invalida'))->toBe('—');
});

it('normalize_status mapeia variações de status', function () {
    expect(normalize_status('paid'))->toBe('pago');
    expect(normalize_status('Pago'))->toBe('pago');
    expect(normalize_status('unpaid'))->toBe('nao pago');
    expect(normalize_status('não pago'))->toBe('nao pago');
    expect(normalize_status('partial'))->toBe('pago parcial');
    expect(normalize_status('canceled'))->toBe('cancelado');
    expect(normalize_status('desconhecido'))->toBe('desconhecido'); // valor livre
});

it('status_label traduz para pt-BR e trata vazio', function () {
    expect(status_label('pago'))->toBe('Pago');
    expect(status_label('nao pago'))->toBe('Não pago');
    expect(status_label('pending'))->toBe('Pendente');
    expect(status_label('confirmed'))->toBe('Confirmado');
    expect(status_label('refunded'))->toBe('Reembolsado');
    expect(status_label(''))->toBe('—');
});

it('month_window devolve primeiro dia do mês atual e do próximo', function () {
    [$from, $to] = month_window();
    expect($from)->toMatch('/^\d{4}-\d{2}-01$/');
    expect($to)->toMatch('/^\d{4}-\d{2}-01$/');
    expect($to)->toBeGreaterThan($from);
});

it('tz_sp retorna fuso de São Paulo', function () {
    expect(tz_sp()->getName())->toBe('America/Sao_Paulo');
});