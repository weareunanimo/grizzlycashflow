<?php

declare(strict_types=1);

namespace Grizzly\Support;

use DateTimeImmutable;

/**
 * Relógio congelado para testes — a única forma de o domínio ter comportamento determinístico
 * quando a regra depende de "hoje" (ex.: fatura fechou? recorrência está em atraso?).
 */
final class FrozenClock implements Clock
{
    private readonly DateTimeImmutable $frozenAt;

    public function __construct(DateTimeImmutable|string $at = 'now')
    {
        $this->frozenAt = $at instanceof DateTimeImmutable ? $at : new DateTimeImmutable($at);
    }

    public function now(): DateTimeImmutable
    {
        return $this->frozenAt;
    }

    public function today(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->frozenAt->format('Y-m-d'));
    }
}
