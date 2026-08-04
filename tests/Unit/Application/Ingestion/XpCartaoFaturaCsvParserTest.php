<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Ingestion;

use Grizzly\Application\Ingestion\XpCartaoFaturaCsvParser;
use PHPUnit\Framework\TestCase;

final class XpCartaoFaturaCsvParserTest extends TestCase
{
    private function fixture(): string
    {
        return file_get_contents(
            dirname(__DIR__, 3) . '/Fixtures/csv/xp_visa_black_fatura_2026-09_aberta.csv'
        );
    }

    public function test_parses_all_rows_from_real_fixture_despite_bom(): void
    {
        $rows = XpCartaoFaturaCsvParser::parse($this->fixture());

        self::assertCount(110, $rows);
        self::assertSame('2026-05-01', $rows[0]['purchase_date']);
    }

    public function test_installment_counts_match_reference(): void
    {
        $rows = XpCartaoFaturaCsvParser::parse($this->fixture());

        $parceled = 0;
        $cash = 0;
        $unparseable = 0;
        foreach ($rows as $row) {
            if ($row['installment_number'] === null) {
                $unparseable++;
            } elseif ($row['installment_total'] === 1) {
                $cash++;
            } elseif ($row['installment_total'] > 1) {
                $parceled++;
            }
        }

        // docs/13 §2.3: a linha "Pagamento de fatura" ("de 1", sem número) fica de fora
        // das duas contagens de propósito — não é uma compra parcelada nem à vista.
        self::assertSame(72, $parceled);
        self::assertSame(37, $cash);
        self::assertSame(1, $unparseable);
    }

    public function test_negative_rows_are_parsed_as_negative_money(): void
    {
        $rows = XpCartaoFaturaCsvParser::parse($this->fixture());

        $negatives = array_values(array_filter($rows, fn (array $r) => $r['amount']->isNegative()));

        self::assertCount(4, $negatives);
    }

    public function test_pagamento_de_fatura_row_is_flagged_and_has_no_installment(): void
    {
        $rows = XpCartaoFaturaCsvParser::parse($this->fixture());

        $payment = array_values(array_filter($rows, fn (array $r) => $r['is_payment']));

        self::assertCount(1, $payment);
        self::assertNull($payment[0]['installment_number']);
        self::assertNull($payment[0]['installment_total']);
        self::assertSame(-271053, $payment[0]['amount']->cents());
    }

    public function test_parse_installment_dash_means_one_of_one(): void
    {
        self::assertSame([1, 1], XpCartaoFaturaCsvParser::parseInstallment('-'));
    }

    public function test_parse_installment_n_of_total(): void
    {
        self::assertSame([4, 7], XpCartaoFaturaCsvParser::parseInstallment('4 de 7'));
    }

    public function test_parse_date_four_digit_year(): void
    {
        self::assertSame('2026-02-06', XpCartaoFaturaCsvParser::parseDate('06/02/2026'));
    }

    /**
     * Duas compras distintas no mesmo dia, mesmo estabelecimento normalizado, mesmo n/N,
     * mas valor de parcela diferente — precisam continuar distintas (docs/13 §2.6).
     */
    public function test_group_key_collision_pair_preserves_distinct_amounts(): void
    {
        $rows = XpCartaoFaturaCsvParser::parse($this->fixture());

        $matches = array_values(array_filter(
            $rows,
            fn (array $r) => $r['purchase_date'] === '2026-07-09' && str_contains($r['description'], 'MERCADOLIVRE'),
        ));

        self::assertCount(2, $matches);
        self::assertNotSame($matches[0]['amount']->cents(), $matches[1]['amount']->cents());
    }
}
