<?php

declare(strict_types=1);

namespace Grizzly\Domain\Identity;

use DateTimeImmutable;

/**
 * Backoff progressivo contra força bruta (docs/11-seguranca.md#12).
 *
 * Regra de negócio pura: recebe a contagem de falhas recentes e o horário da última falha,
 * devolve até quando o login deve ficar bloqueado (ou null se liberado). A camada de
 * infraestrutura é quem conta as falhas em `login_attempts` — esta classe não sabe que um
 * banco de dados existe.
 */
final class LoginLockoutPolicy
{
    private const TIERS = [
        // [falhas mínimas, bloqueio em segundos]
        [20, 3600],
        [10, 900],
        [5, 60],
    ];

    public function blockedUntil(int $recentFailures, DateTimeImmutable $lastFailureAt): ?DateTimeImmutable
    {
        foreach (self::TIERS as [$threshold, $seconds]) {
            if ($recentFailures >= $threshold) {
                return $lastFailureAt->modify("+{$seconds} seconds");
            }
        }

        return null;
    }

    public function isBlocked(int $recentFailures, DateTimeImmutable $lastFailureAt, DateTimeImmutable $now): bool
    {
        $blockedUntil = $this->blockedUntil($recentFailures, $lastFailureAt);

        return $blockedUntil !== null && $now < $blockedUntil;
    }
}
