<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Classification;

use Grizzly\Domain\Classification\MerchantNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MerchantNormalizerTest extends TestCase
{
    /** @return list<array{0:string,1:string}> */
    public static function gatewayPrefixCases(): array
    {
        return [
            ['MP*MERCADOLIVRE', 'MERCADOLIVRE'],
            ['MP *MERCADOLIVRE', 'MERCADOLIVRE'],
            ['MERCADOLIVRE*MERCADOLIVRE', 'MERCADOLIVRE'],
            ['GNT*TEMU', 'TEMU'],
            ['VINDI  *COALA', 'COALA'],
            ['BR1    *COLZANI MOVEIS', 'COLZANI MOVEIS'],
        ];
    }

    #[DataProvider('gatewayPrefixCases')]
    public function test_strips_gateway_prefix(string $raw, string $expected): void
    {
        self::assertSame($expected, MerchantNormalizer::key($raw));
    }

    public function test_strips_trailing_city_store_code(): void
    {
        self::assertSame('ANGELONI ELETRO', MerchantNormalizer::key('ANGELONI ELETRO 51'));
    }

    public function test_strips_company_suffix(): void
    {
        self::assertSame('BANCO XP', MerchantNormalizer::key('Banco XP S.A'));
    }

    public function test_two_variants_of_same_merchant_collapse_to_same_key(): void
    {
        self::assertSame(
            MerchantNormalizer::key('DM          *TEMU'),
            MerchantNormalizer::key('GNT*TEMU'),
        );
    }
}
