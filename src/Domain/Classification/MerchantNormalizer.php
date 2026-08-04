<?php

declare(strict_types=1);

namespace Grizzly\Domain\Classification;

use Grizzly\Support\Str;

/**
 * Reduz a descrição bruta de um lançamento a uma chave estável de estabelecimento
 * (docs/13 §2.5), removendo ruído que faria o mesmo estabelecimento parecer vários:
 * prefixo de gateway de pagamento ("MP*", "SHOPEE *", "GNT*"), código numérico de
 * loja/cidade no fim, e sufixos de razão social.
 */
final class MerchantNormalizer
{
    public static function key(string $rawDescription): string
    {
        $value = strtoupper(Str::normalize($rawDescription));

        // Prefixo de gateway: 2 a 14 letras/dígitos seguidos de "*" (com ou sem espaço).
        $value = preg_replace('/^[A-Z0-9]{2,14}\s*\*\s*/', '', $value) ?? $value;

        // Sufixo de razão social.
        $value = preg_replace('/\s+(S\.?A\.?|LTDA\.?|ME|EIRELI)$/', '', $value) ?? $value;

        // Código numérico de loja/cidade no fim ("ANGELONI ELETRO 51" -> "ANGELONI ELETRO").
        $value = preg_replace('/\s+\d+$/', '', $value) ?? $value;

        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }
}
