<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Classification;

use Grizzly\Domain\Classification\DescriptionCleaner;
use PHPUnit\Framework\TestCase;

final class DescriptionCleanerTest extends TestCase
{
    public function test_strips_pix_enviado_prefix(): void
    {
        self::assertSame('Joana Haas Junqueira', DescriptionCleaner::forDisplay('Pix enviado para Joana Haas Junqueira'));
    }

    public function test_strips_pix_recebido_prefix(): void
    {
        self::assertSame('Fernando de Paula', DescriptionCleaner::forDisplay('Pix recebido de Fernando de Paula'));
    }

    public function test_strips_pagamento_para_prefix(): void
    {
        self::assertSame('CELESC DISTRIBUICAO S.A', DescriptionCleaner::forDisplay('Pagamento para CELESC DISTRIBUICAO S.A'));
    }

    public function test_leaves_unrelated_descriptions_untouched(): void
    {
        self::assertSame('PAGAMENTO DE FATURA', DescriptionCleaner::forDisplay('PAGAMENTO DE FATURA'));
        self::assertSame('Rendimento automático', DescriptionCleaner::forDisplay('Rendimento automático'));
        self::assertSame('Salário recebido', DescriptionCleaner::forDisplay('Salário recebido'));
    }
}
