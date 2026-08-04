<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Classification;

use Grizzly\Domain\Classification\MerchantDisplayName;
use PHPUnit\Framework\TestCase;

final class MerchantDisplayNameTest extends TestCase
{
    public function test_mercadolivre_variants_show_friendly_brand(): void
    {
        self::assertSame('Mercado Livre', MerchantDisplayName::forRawDescription('MP*MERCADOLIVRE'));
        self::assertSame('Mercado Livre', MerchantDisplayName::forRawDescription('MERCADOLIVRE*MERCADOLIVRE'));
    }

    public function test_ifood_shows_brand_with_restaurant_detail(): void
    {
        self::assertSame(
            'iFood - Santa Larica Pastelar',
            MerchantDisplayName::forRawDescription('IFD*SANTA LARICA PASTELAR'),
        );
    }

    public function test_ifood_without_useful_remainder_shows_only_brand(): void
    {
        self::assertSame('iFood', MerchantDisplayName::forRawDescription('IFD*BR'));
    }

    public function test_shopee_shows_just_the_brand(): void
    {
        self::assertSame('Shopee', MerchantDisplayName::forRawDescription('SHOPEE *RCDISTRIBUIDOR'));
    }

    public function test_known_key_without_gateway_prefix(): void
    {
        self::assertSame('Temu', MerchantDisplayName::forRawDescription('TEMU.COM'));
        self::assertSame('LATAM Airlines', MerchantDisplayName::forRawDescription('LATAM AIR*ITTVVJ'));
    }

    public function test_unknown_merchant_falls_back_to_title_case(): void
    {
        self::assertSame('Tonitoys', MerchantDisplayName::forRawDescription('TONITOYS'));
    }
}
