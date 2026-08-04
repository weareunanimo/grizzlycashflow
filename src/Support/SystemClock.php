<?php

declare(strict_types=1);

namespace Grizzly\Support;

use DateTimeImmutable;

/** Implementação real, usada em produção (ligada via Infrastructure, injetada no domínio). */
final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }

    public function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today');
    }
}
