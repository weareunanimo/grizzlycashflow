<?php

declare(strict_types=1);

namespace Grizzly\Domain\Classification;

/**
 * Remove o prefixo repetitivo do lançamento bancário para leitura mais limpa
 * (docs/13 §1.2) — "Pix enviado para Fulano" vira só "Fulano". Puramente
 * cosmético: a descrição original (`raw_description`) nunca é alterada.
 */
final class DescriptionCleaner
{
    private const PREFIXES = [
        'Pix enviado para ',
        'Pix recebido de ',
        'Pagamento para ',
        'TED recebida de ',
        'TED enviada para ',
    ];

    public static function forDisplay(string $description): string
    {
        foreach (self::PREFIXES as $prefix) {
            if (stripos($description, $prefix) === 0) {
                $rest = trim(substr($description, strlen($prefix)));

                return $rest !== '' ? $rest : $description;
            }
        }

        return $description;
    }
}
