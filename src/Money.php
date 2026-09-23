<?php

declare(strict_types=1);

namespace Kamaltur;

/**
 * Money/pricing helpers — pure functions (unit-testable).
 *
 * Extraído do cálculo de margem que vivia duplicado em
 * sales/create.php e sales/edit.php: margem = total do cliente - total pago.
 */
final class Money
{
    /**
     * Margem/lucro da venda (mesma fórmula usada em sales/create.php e sales/edit.php).
     */
    public static function margin(float $totalAmount, float $totalPaid): float
    {
        return $totalAmount - $totalPaid;
    }

    /**
     * Formata como moeda brasileira: R$ 1.234,56
     */
    public static function brl(float $value): string
    {
        return 'R$ ' . number_format($value, 2, ',', '.');
    }
}