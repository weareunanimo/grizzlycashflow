<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Ingestion;

use Grizzly\Application\Ingestion\XpContaCsvParser;
use PHPUnit\Framework\TestCase;

final class XpContaCsvParserTest extends TestCase
{
    private function fixture(): string
    {
        return file_get_contents(
            dirname(__DIR__, 3).'/Fixtures/csv/xp_conta_2026-06-05_a_2026-08-04.csv'
        );
    }

    public function test_parses_all_rows_from_real_fixture(): void
    {
        $rows = XpContaCsvParser::parse($this->fixture());

        self::assertCount(110, $rows);
    }

    public function test_balance_chain_has_zero_breaks_on_real_fixture(): void
    {
        $rows = XpContaCsvParser::parse($this->fixture());

        self::assertSame([], XpContaCsvParser::validateBalanceChain($rows));
    }

    public function test_final_balance_matches_reference(): void
    {
        $rows = XpContaCsvParser::parse($this->fixture());

        self::assertSame(197996, $rows[0]['balance']->cents());
    }

    public function test_rendimento_rows_sum_matches_reference(): void
    {
        $rows = XpContaCsvParser::parse($this->fixture());

        $sum = 0;
        $count = 0;
        foreach ($rows as $row) {
            if (str_starts_with($row['description'], 'Rendimento automático')) {
                $sum += $row['amount']->cents();
                $count++;
            }
        }

        self::assertSame(42, $count);
        self::assertSame(1863, $sum);
    }

    public function test_parses_two_digit_year_date(): void
    {
        self::assertSame('2026-08-04', XpContaCsvParser::parseDate('04/08/26'));
    }

    public function test_negative_amount_before_prefix(): void
    {
        $rows = XpContaCsvParser::parse("Data;Hora;Descricao;Valor;Saldo\n03/08/26;08:08:55;Pagamento para BANCO XP S.A;-R$ 2.710,53;R$ 1.979,63\n");

        self::assertSame(-271053, $rows[0]['amount']->cents());
    }
}
