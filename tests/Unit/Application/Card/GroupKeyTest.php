<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Card;

use Grizzly\Application\Card\GroupKey;
use PHPUnit\Framework\TestCase;

final class GroupKeyTest extends TestCase
{
    public function test_same_inputs_produce_same_key(): void
    {
        $a = GroupKey::compute(1, 'MERCADOLIVRE', 5658, 10, '2026-07-05');
        $b = GroupKey::compute(1, 'MERCADOLIVRE', 5658, 10, '2026-07-05');

        self::assertSame($a, $b);
    }

    /**
     * Mesmo estabelecimento, mesma data, mesmo n/N, valor de parcela diferente ->
     * são compras distintas e o group_key precisa refletir isso (docs/13 §2.6).
     */
    public function test_different_installment_amount_produces_different_key(): void
    {
        $a = GroupKey::compute(1, 'MERCADOLIVRE', 6496, 10, '2026-07-09');
        $b = GroupKey::compute(1, 'MERCADOLIVRE', 5633, 10, '2026-07-09');

        self::assertNotSame($a, $b);
    }

    public function test_different_card_produces_different_key(): void
    {
        $a = GroupKey::compute(1, 'MERCADOLIVRE', 5658, 10, '2026-07-05');
        $b = GroupKey::compute(2, 'MERCADOLIVRE', 5658, 10, '2026-07-05');

        self::assertNotSame($a, $b);
    }
}
