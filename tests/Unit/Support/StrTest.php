<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Grizzly\Support\Str;
use PHPUnit\Framework\TestCase;

final class StrTest extends TestCase
{
    public function test_normalize_removes_accents_and_lowercases(): void
    {
        self::assertSame('mercado angeloni', Str::normalize('MERCADO ANGELONI'));
        self::assertSame('sao paulo', Str::normalize('São Paulo'));
        self::assertSame('nao', Str::normalize('Ñao'));
    }

    public function test_normalize_collapses_whitespace(): void
    {
        self::assertSame('hering', Str::normalize('  HERING   '));
        // Str::normalize é genérico (case/acento/espaço); remover ruído de gateway de
        // pagamento ("VINDI *") é regra de negócio e vive em Domain\Classification\MerchantNormalizer.
        self::assertSame('vindi *coala', Str::normalize('VINDI  *COALA'));
    }

    public function test_slug(): void
    {
        self::assertSame('mercado-angeloni', Str::slug('Mercado Angeloni'));
        self::assertSame('combustivel', Str::slug('Combustível'));
    }

    public function test_trigram_similarity_identical_strings(): void
    {
        self::assertSame(1.0, Str::trigramSimilarity('Angeloni', 'angeloni'));
    }

    public function test_trigram_similarity_similar_strings_high_score(): void
    {
        $score = Str::trigramSimilarity('Mercado Angeloni', 'Angeloni Mercado 442');
        self::assertGreaterThan(0.5, $score);
    }

    public function test_trigram_similarity_different_strings_low_score(): void
    {
        $score = Str::trigramSimilarity('Shell Box', 'Netflix');
        self::assertLessThan(0.3, $score);
    }

    public function test_trigram_similarity_is_symmetric(): void
    {
        $a = Str::trigramSimilarity('Uber Trip', 'Uber *Trip');
        $b = Str::trigramSimilarity('Uber *Trip', 'Uber Trip');
        self::assertEqualsWithDelta($a, $b, 0.0001);
    }

    public function test_trigram_similarity_bounds(): void
    {
        $score = Str::trigramSimilarity('abc', 'xyz');
        self::assertGreaterThanOrEqual(0.0, $score);
        self::assertLessThanOrEqual(1.0, $score);
    }
}
