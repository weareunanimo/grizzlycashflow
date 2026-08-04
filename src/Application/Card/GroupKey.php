<?php

declare(strict_types=1);

namespace Grizzly\Application\Card;

/**
 * Reconhece a mesma compra entre faturas sucessivas sem duplicar (invariante I10).
 *
 * Ancorado em `purchase_date` quando disponível (docs/13 §2.1) — mais direto que o
 * `first_reference_month` derivado, e comprovadamente estável nos dados reais mesmo
 * quando a compra "rola" para o ciclo seguinte perto do fechamento.
 */
final class GroupKey
{
    public static function compute(
        int $creditCardId,
        string $merchantKey,
        int $installmentAmountCents,
        int $installmentsTotal,
        string $purchaseDateOrReferenceMonth,
    ): string {
        return hash('sha256', implode('|', [
            $creditCardId,
            $merchantKey,
            $installmentAmountCents,
            $installmentsTotal,
            $purchaseDateOrReferenceMonth,
        ]));
    }
}
