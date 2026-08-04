<?php

declare(strict_types=1);

namespace Grizzly\Support;

use InvalidArgumentException;

/**
 * Gerador de ULID (Universally Unique Lexicographically Sortable Identifier), em PHP puro.
 *
 * Usado para IDs que precisam ser ordenáveis por tempo de criação sem vazar um contador
 * sequencial (nomes de arquivo em storage/, request_id, tokens de sessão hasheados).
 *
 * Formato: 48 bits de timestamp (ms) + 80 bits de aleatoriedade, codificados em Crockford Base32
 * (26 caracteres, maiúsculos, sem I/L/O/U para evitar confusão visual).
 */
final class Ulid
{
    private const ENCODING = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    private const TIME_LEN = 10;
    private const RANDOM_LEN = 16;

    private function __construct(private readonly string $value)
    {
    }

    public static function generate(?Clock $clock = null): self
    {
        $clock = $clock ?? new SystemClock();
        $timestampMs = (int) ($clock->now()->format('Uv'));

        return new self(self::encodeTime($timestampMs) . self::encodeRandom());
    }

    public static function fromString(string $value): self
    {
        $value = strtoupper($value);

        if (!preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $value)) {
            throw new InvalidArgumentException("ULID inválido: \"{$value}\".");
        }

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function encodeTime(int $timestampMs): string
    {
        $encoded = '';
        for ($i = self::TIME_LEN - 1; $i >= 0; $i--) {
            $mod = $timestampMs % 32;
            $encoded = self::ENCODING[$mod] . $encoded;
            $timestampMs = intdiv($timestampMs, 32);
        }

        return $encoded;
    }

    private static function encodeRandom(): string
    {
        $bytes = random_bytes(10); // 80 bits
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        for ($i = 0; $i < self::RANDOM_LEN; $i++) {
            $chunk = substr($bits, $i * 5, 5);
            $encoded .= self::ENCODING[bindec($chunk)];
        }

        return $encoded;
    }
}
