<?php

declare(strict_types=1);

namespace Grizzly\Support;

use InvalidArgumentException;

/**
 * Valor monetário imutável, sempre em centavos inteiros (ADR-0006).
 *
 * Nunca aceita float na construção: `0.1 + 0.2 !== 0.3` em ponto flutuante, e um bug de
 * arredondamento em dado financeiro não é aceitável. Toda operação retorna uma nova instância.
 */
final class Money
{
    private function __construct(
        private readonly int $cents,
        private readonly string $currency,
    ) {
    }

    public static function fromCents(int $cents, string $currency = 'BRL'): self
    {
        self::assertCurrency($currency);

        return new self($cents, strtoupper($currency));
    }

    /**
     * @param numeric-string $decimal Ex.: "1234.56" ou "1234,56"
     */
    public static function fromDecimalString(string $decimal, string $currency = 'BRL'): self
    {
        $normalized = str_replace(',', '.', trim($decimal));

        if (!preg_match('/^-?\d+(\.\d{1,2})?$/', $normalized)) {
            throw new InvalidArgumentException("Valor decimal inválido: \"{$decimal}\"");
        }

        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '-');
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '0');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        $cents = ((int) $whole) * 100 + (int) $fraction;

        return self::fromCents($negative ? -$cents : $cents, $currency);
    }

    public static function zero(string $currency = 'BRL'): self
    {
        return self::fromCents(0, $currency);
    }

    public function cents(): int
    {
        return $this->cents;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents + $other->cents, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents - $other->cents, $this->currency);
    }

    public function negate(): self
    {
        return new self(-$this->cents, $this->currency);
    }

    public function abs(): self
    {
        return new self(abs($this->cents), $this->currency);
    }

    public function multiply(int $factor): self
    {
        return new self($this->cents * $factor, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents && $this->currency === $other->currency;
    }

    public function greaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->cents > $other->cents;
    }

    public function lessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->cents < $other->cents;
    }

    /**
     * Divide o valor em N parcelas iguais, distribuindo o resto de centavos nas primeiras parcelas.
     *
     * Sem isso, 12x de R$ 100,00 vira R$ 1.199,99 em vez de R$ 1.200,00 — o clássico bug de
     * arredondamento de parcelamento. A soma de allocate() é sempre exatamente igual ao total.
     *
     * @return list<self>
     */
    public function allocate(int $installments): array
    {
        if ($installments < 1) {
            throw new InvalidArgumentException('Número de parcelas deve ser >= 1.');
        }

        $base = intdiv($this->cents, $installments);
        $remainder = $this->cents - ($base * $installments);
        $remainderSign = $remainder <=> 0;
        $remainderAbs = abs($remainder);

        $result = [];
        for ($i = 0; $i < $installments; $i++) {
            $extra = $i < $remainderAbs ? $remainderSign : 0;
            $result[] = new self($base + $extra, $this->currency);
        }

        return $result;
    }

    public function formatBrl(): string
    {
        $sign = $this->cents < 0 ? '-' : '';
        $absCents = abs($this->cents);
        $reais = intdiv($absCents, 100);
        $centavos = $absCents % 100;
        $reaisFormatted = number_format($reais, 0, '', '.');

        return sprintf('%sR$ %s,%02d', $sign, $reaisFormatted, $centavos);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Não é possível operar valores em moedas diferentes: {$this->currency} vs {$other->currency}."
            );
        }
    }

    private static function assertCurrency(string $currency): void
    {
        if (!preg_match('/^[A-Za-z]{3}$/', $currency)) {
            throw new InvalidArgumentException("Código de moeda inválido: \"{$currency}\".");
        }
    }
}
