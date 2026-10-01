<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A página "Recorrentes" junta conta e cartão: o plano de celular pago por boleto
 * e o streaming na fatura aparecem lado a lado, e compra parcelada não é serviço.
 */
final class RecurringPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $institutionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->institutionId = DB::table('institutions')->insertGetId([
            'user_id' => $this->user->id,
            'name' => 'Banco XP',
            'kind' => 'bank',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_services_from_the_account_and_the_card_are_listed(): void
    {
        $accountId = $this->account('Conta XP', 'checking');
        $cardId = $this->creditCard('Visa Black');

        foreach ([3, 2, 1] as $monthsAgo) {
            $date = now()->subMonthsNoOverflow($monthsAgo)->format('Y-m-10');
            $this->transaction($accountId, 'Pagamento para TELEFONICA BRASIL S.A', 59_99, $date);
            $this->purchase($cardId, 'DM*SPOTIFY', 23_90, $date, installments: 1);
            $this->purchase($cardId, 'SHOPEE *LOJA', 87_05, $date, installments: 12);
        }

        $this->actingAs($this->user)->get('/recorrentes')
            ->assertOk()
            ->assertSee('Vivo')
            ->assertSee('Celular / telefone')
            ->assertSee('Spotify')
            ->assertSee('Streaming')
            ->assertDontSee('Shopee')
            ->assertSee('R$ 83,89'); // 59,99 + 23,90 por mês
    }

    public function test_the_minimum_number_of_months_can_be_changed(): void
    {
        $cardId = $this->creditCard('Visa Black');

        foreach ([2, 1] as $monthsAgo) {
            $this->purchase($cardId, 'NETFLIX.COM', 44_90, now()->subMonthsNoOverflow($monthsAgo)->format('Y-m-10'), installments: 1);
        }

        $this->actingAs($this->user)->get('/recorrentes')->assertOk()->assertDontSee('Netflix');
        $this->actingAs($this->user)->get('/recorrentes?meses=2')->assertOk()->assertSee('Netflix');
    }

    private function account(string $name, string $type): int
    {
        return DB::table('accounts')->insertGetId([
            'user_id' => $this->user->id,
            'institution_id' => $this->institutionId,
            'name' => $name,
            'type' => $type,
            'opening_balance_cents' => 0,
            'opening_balance_date' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function creditCard(string $name): int
    {
        return DB::table('credit_cards')->insertGetId([
            'user_id' => $this->user->id,
            'account_id' => $this->account($name, 'credit_card'),
            'brand' => 'visa',
            'closing_day' => 18,
            'due_day' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function transaction(int $accountId, string $description, int $cents, string $date): void
    {
        $id = uniqid('', true);

        DB::table('transactions')->insert([
            'user_id' => $this->user->id,
            'account_id' => $accountId,
            'direction' => 'out',
            'amount_cents' => $cents,
            'currency' => 'BRL',
            'occurred_on' => $date,
            'cash_effect_on' => $date,
            'description' => $description,
            'raw_description' => $description,
            'payment_method' => 'boleto',
            'status' => 'cleared',
            'source' => 'csv',
            'fingerprint' => hash('sha256', 'f'.$id),
            'fingerprint_loose' => hash('sha256', 'l'.$id),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function purchase(int $cardId, string $description, int $cents, string $date, int $installments): void
    {
        DB::table('card_purchases')->insert([
            'user_id' => $this->user->id,
            'credit_card_id' => $cardId,
            'description' => $description,
            'raw_description' => $description,
            'purchase_date' => $date,
            'installments_total' => $installments,
            'installment_amount_cents' => $cents,
            'total_amount_cents' => $cents * $installments,
            'first_reference_month' => substr($date, 0, 7).'-01',
            'currency' => 'BRL',
            'status' => 'active',
            'group_key' => hash('sha256', uniqid('g', true)),
            'detection_confidence' => 1.000,
            'needs_review' => false,
            'source' => 'csv',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
