<?php

use Kamaltur\Money;

it('calcula a margem como total do cliente menos total pago', function () {
    expect(Money::margin(1000.0, 700.0))->toBe(300.0);
    expect(Money::margin(1000.0, 1000.0))->toBe(0.0);
    expect(Money::margin(0.0, 0.0))->toBe(0.0);
    expect(Money::margin(500.0, 550.0))->toBe(-50.0);
});

it('mantém precisão simples sem arredondar (mesma semântica do código legado)', function () {
    // 0.1 + 0.2 - 0.3 deve continuar igual à subtração pura
    expect(Money::margin(0.1 + 0.2, 0.3))->toBe(0.1 + 0.2 - 0.3);
});

it('formata valores como moeda brasileira', function () {
    expect(Money::brl(1234.56))->toBe('R$ 1.234,56');
    expect(Money::brl(0))->toBe('R$ 0,00');
    expect(Money::brl(5.5))->toBe('R$ 5,50');
    expect(Money::brl(1000000.0))->toBe('R$ 1.000.000,00');
});