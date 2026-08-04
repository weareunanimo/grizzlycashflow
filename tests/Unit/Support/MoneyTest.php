<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Grizzly\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_from_cents_and_currency_default(): void
    {
        $m = Money::fromCents(12345);

        self::assertSame(12345, $m->cents());
        self::assertSame('BRL', $m->currency());
    }

    public function test_from_decimal_string_with_comma(): void
    {
        self::assertSame(450_00, Money::fromDecimalString('450,00')->cents());
        self::assertSame(1_23, Money::fromDecimalString('1,23')->cents());
        self::assertSame(-2_710_53, Money::fromDecimalString('-2710,53')->cents());
    }

    public function test_from_decimal_string_with_dot(): void
    {
        self::assertSame(89_00, Money::fromDecimalString('89.00')->cents());
    }

    public function test_from_decimal_string_rejects_garbage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::fromDecimalString('not-a-number');
    }

    public function test_add_and_subtract(): void
    {
        $a = Money::fromCents(1000);
        $b = Money::fromCents(250);

        self::assertSame(1250, $a->add($b)->cents());
        self::assertSame(750, $a->subtract($b)->cents());
    }

    public function test_cannot_operate_across_currencies(): void
    {
        $brl = Money::fromCents(100, 'BRL');
        $usd = Money::fromCents(100, 'USD');

        $this->expectException(InvalidArgumentException::class);
        $brl->add($usd);
    }

    public function test_negate_and_abs(): void
    {
        $m = Money::fromCents(500);

        self::assertSame(-500, $m->negate()->cents());
        self::assertSame(500, $m->negate()->abs()->cents());
    }

    public function test_comparisons(): void
    {
        $small = Money::fromCents(100);
        $big = Money::fromCents(200);

        self::assertTrue($big->greaterThan($small));
        self::assertTrue($small->lessThan($big));
        self::assertTrue($small->equals(Money::fromCents(100)));
    }

    /**
     * ⭐ O caso que motivou o Value Object: 12x de R$ 450,00 nunca pode "vazar" ou "sumir" centavo.
     */
    public function test_allocate_distributes_remainder_without_losing_cents(): void
    {
        $total = Money::fromCents(45000)->multiply(12); // R$ 5.400,00 em 12x
        $parts = $total->allocate(12);

        self::assertCount(12, $parts);

        $sum = array_reduce($parts, fn (Money $carry, Money $p) => $carry->add($p), Money::zero());
        self::assertTrue($sum->equals($total));
    }

    public function test_allocate_distributes_remainder_evenly_when_not_divisible(): void
    {
        // R$ 1.000,00 em 3x -> 333,34 + 333,33 + 333,33 = 1.000,00 (nunca 999,99 nem 1.000,01)
        $total = Money::fromCents(100_000);
        $parts = $total->allocate(3);

        self::assertSame([33334, 33333, 33333], array_map(fn (Money $p) => $p->cents(), $parts));

        $sum = array_reduce($parts, fn (Money $carry, Money $p) => $carry->add($p), Money::zero());
        self::assertTrue($sum->equals($total));
    }

    public function test_allocate_handles_negative_totals(): void
    {
        $total = Money::fromCents(-100);
        $parts = $total->allocate(3);

        $sum = array_reduce($parts, fn (Money $carry, Money $p) => $carry->add($p), Money::zero());
        self::assertTrue($sum->equals($total));
    }

    public function test_format_brl(): void
    {
        self::assertSame('R$ 1.234,56', Money::fromCents(123456)->formatBrl());
        self::assertSame('R$ 0,04', Money::fromCents(4)->formatBrl());
        self::assertSame('-R$ 2.710,53', Money::fromCents(-271053)->formatBrl());
        self::assertSame('R$ 23.413,96', Money::fromCents(2341396)->formatBrl());
    }

    public function test_is_zero_positive_negative(): void
    {
        self::assertTrue(Money::zero()->isZero());
        self::assertTrue(Money::fromCents(1)->isPositive());
        self::assertTrue(Money::fromCents(-1)->isNegative());
    }
}
