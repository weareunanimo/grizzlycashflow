<?php

declare(strict_types=1);

namespace Grizzly\Support;

use DateTimeImmutable;

/**
 * O domínio nunca chama date()/time()/new DateTime() diretamente — sempre via esta interface.
 * Isso torna qualquer regra que dependa de "hoje" (fechamento de fatura, atraso de recorrência,
 * expiração de sessão) determinística e testável com FrozenClock.
 */
interface Clock
{
    public function now(): DateTimeImmutable;

    public function today(): DateTimeImmutable;
}
