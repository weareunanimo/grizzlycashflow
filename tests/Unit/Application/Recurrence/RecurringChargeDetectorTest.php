<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Recurrence;

use Grizzly\Application\Recurrence\RecurringChargeDetector;
use PHPUnit\Framework\TestCase;

final class RecurringChargeDetectorTest extends TestCase
{
    public function test_a_streaming_charged_three_months_in_a_row_is_found(): void
    {
        $found = RecurringChargeDetector::detect([
            $this->card('2026-05-28', 'DM*SPOTIFY', 21_90),
            $this->card('2026-06-28', 'DM*SPOTIFY', 21_90),
            $this->card('2026-07-28', 'DM*SPOTIFY', 23_90),
        ]);

        self::assertCount(1, $found);
        self::assertSame('Spotify', $found[0]['name']);
        self::assertSame('streaming', $found[0]['kind']);
        self::assertSame(['2026-05', '2026-06', '2026-07'], $found[0]['months']);
        self::assertSame(23_90, $found[0]['last_month_cents']);
        self::assertTrue($found[0]['active']);
    }

    public function test_two_months_are_not_enough_by_default(): void
    {
        $charges = [
            $this->card('2026-06-28', 'NETFLIX.COM', 44_90),
            $this->card('2026-07-28', 'NETFLIX.COM', 44_90),
        ];

        self::assertSame([], RecurringChargeDetector::detect($charges));
        self::assertCount(1, RecurringChargeDetector::detect($charges, minMonths: 2));
    }

    public function test_a_gap_breaks_the_streak(): void
    {
        self::assertSame([], RecurringChargeDetector::detect([
            $this->card('2026-04-10', 'NETFLIX.COM', 44_90),
            $this->card('2026-05-10', 'NETFLIX.COM', 44_90),
            $this->card('2026-07-10', 'NETFLIX.COM', 44_90),
        ]));
    }

    /** Conta de luz varia todo mês e mesmo assim é recorrente. */
    public function test_a_known_utility_paid_from_the_account_is_found_even_with_varying_amounts(): void
    {
        $found = RecurringChargeDetector::detect([
            $this->bank('2026-06-01', 'Pagamento para CELESC DISTRIBUICAO S.A', 210_15),
            $this->bank('2026-07-01', 'Pagamento para CELESC DISTRIBUICAO S.A', 429_84),
            $this->bank('2026-08-03', 'Pagamento para CELESC DISTRIBUICAO S.A', 88_27),
        ]);

        self::assertCount(1, $found);
        self::assertSame('utilidades', $found[0]['kind']);
        self::assertSame(['conta'], $found[0]['channels']);
    }

    public function test_phone_and_internet_are_told_apart(): void
    {
        $charges = [];
        foreach (['2026-05', '2026-06', '2026-07'] as $month) {
            $charges[] = $this->bank($month.'-15', 'Pagamento para TELEFONICA BRASIL S.A', 59_99);
            $charges[] = $this->bank($month.'-20', 'Pagamento para UNIFIQUE TELECOMUNICACOES', 119_90);
        }

        $kinds = array_column(RecurringChargeDetector::detect($charges), 'kind', 'name');

        self::assertSame('telefonia', $kinds['Vivo']);
        self::assertContains('internet', $kinds);
    }

    /** "DL*GOOGLE WORKSP" e "DL*GOOGLE GOOGLE" são o mesmo fornecedor. */
    public function test_description_variants_of_the_same_service_are_grouped(): void
    {
        $found = RecurringChargeDetector::detect([
            $this->card('2026-05-01', 'DL*GOOGLE WORKSP', 39_20),
            $this->card('2026-06-30', 'DL*GOOGLE GOOGLE', 72_98),
            $this->card('2026-07-01', 'DL*GOOGLE WORKSP', 39_20),
        ]);

        self::assertCount(1, $found);
        self::assertSame(3, $found[0]['charges']);
    }

    public function test_an_unknown_merchant_needs_a_stable_amount(): void
    {
        $school = [];
        $market = [];
        foreach (['2026-05', '2026-06', '2026-07'] as $i => $month) {
            $school[] = $this->bank($month.'-10', 'Pagamento para ESCOLA RIACHO DOCE LTDA', 1_419_00);
            $market[] = $this->card($month.'-12', 'ANGELONI 51', [180_00, 920_00, 45_00][$i]);
        }

        $found = RecurringChargeDetector::detect([...$school, ...$market]);

        self::assertCount(1, $found);
        self::assertSame('Escola Riacho Doce', $found[0]['name']);
        self::assertSame('outros', $found[0]['kind']);
        self::assertFalse($found[0]['known']);
    }

    public function test_card_invoice_payments_and_refunds_are_ignored(): void
    {
        $charges = [];
        foreach (['2026-05', '2026-06', '2026-07'] as $month) {
            $charges[] = $this->bank($month.'-05', 'PAGAMENTO DE FATURA', 2_000_00);
            $charges[] = $this->card($month.'-05', 'NETFLIX.COM', -44_90);
        }

        self::assertSame([], RecurringChargeDetector::detect($charges));
    }

    public function test_a_service_without_recent_charges_is_flagged_inactive(): void
    {
        $found = RecurringChargeDetector::detect([
            $this->card('2026-01-10', 'NETFLIX.COM', 44_90),
            $this->card('2026-02-10', 'NETFLIX.COM', 44_90),
            $this->card('2026-03-10', 'NETFLIX.COM', 44_90),
            $this->card('2026-07-10', 'DM*SPOTIFY', 23_90),
        ]);

        self::assertCount(1, $found);
        self::assertFalse($found[0]['active']);
    }

    /** @return array{date:string,description:string,amount_cents:int,channel:string} */
    private function card(string $date, string $description, int $cents): array
    {
        return ['date' => $date, 'description' => $description, 'amount_cents' => $cents, 'channel' => 'cartao'];
    }

    /** @return array{date:string,description:string,amount_cents:int,channel:string} */
    private function bank(string $date, string $description, int $cents): array
    {
        return ['date' => $date, 'description' => $description, 'amount_cents' => $cents, 'channel' => 'conta'];
    }
}
