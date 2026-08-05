<?php

declare(strict_types=1);

namespace Grizzly\Support;

/**
 * Utilitários de texto genéricos e puros — sem regra de negócio.
 *
 * Regras específicas de domínio (ruído de gateway de pagamento, sufixo de razão social, etc.)
 * vivem em `Domain\Classification\MerchantNormalizer`, que usa esta classe como base.
 */
final class Str
{
    private function __construct() {}

    /** Minúsculas, sem acento, espaços colapsados, aparado. */
    public static function normalize(string $value): string
    {
        $value = self::stripAccents($value);
        $value = mb_strtolower($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    public static function stripAccents(string $value): string
    {
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $transliterated !== false ? $transliterated : $value;
    }

    public static function slug(string $value): string
    {
        $normalized = self::normalize($value);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $normalized) ?? $normalized;

        return trim($slug, '-');
    }

    /**
     * Similaridade por trigramas de caractere (coeficiente de Dice), 0.0 a 1.0.
     *
     * Base do scoring de duplicidade (Reconciler) e da detecção de merchant conhecido.
     * Strings menores que 3 caracteres caem para comparação exata (0.0 ou 1.0) — não há
     * trigrama possível.
     */
    public static function trigramSimilarity(string $a, string $b): float
    {
        $a = self::normalize($a);
        $b = self::normalize($b);

        if ($a === $b) {
            return 1.0;
        }

        $trigramsA = self::trigrams($a);
        $trigramsB = self::trigrams($b);

        if ($trigramsA === [] || $trigramsB === []) {
            return 0.0;
        }

        $intersection = 0;
        $bCounts = array_count_values($trigramsB);
        foreach (array_count_values($trigramsA) as $trigram => $countA) {
            if (isset($bCounts[$trigram])) {
                $intersection += min($countA, $bCounts[$trigram]);
            }
        }

        return (2.0 * $intersection) / (count($trigramsA) + count($trigramsB));
    }

    /** @return list<string> */
    private static function trigrams(string $value): array
    {
        $padded = '  '.$value.' '; // padding ajuda a pesar início/fim da string
        $length = mb_strlen($padded);

        if ($length < 3) {
            return [$padded];
        }

        $trigrams = [];
        for ($i = 0; $i <= $length - 3; $i++) {
            $trigrams[] = mb_substr($padded, $i, 3);
        }

        return $trigrams;
    }
}
