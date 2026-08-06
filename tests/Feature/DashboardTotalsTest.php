<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * O topo de cada bloco da Home responde "quanto tenho / quanto devo agora"
 * (saldo da conta, fatura em aberto do cartão) e os totais do histórico ficam
 * nos cards de baixo. São números diferentes e não podem ser confundidos: o
 * total em aberto de um cartão inclui parcelas de faturas futuras.
 */
final class DashboardTotalsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $institutionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        (new CategorySeeder)->run($this->user->id);

        $this->institutionId = DB::table('institutions')->insertGetId([
            'user_id' => $this->user->id,
            'name' => 'Banco XP',
            'kind' => 'bank',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_account_shows_the_balance_on_top_and_the_totals_below(): void
    {
        $accountId = $this->account('Conta XP', 'checking', openingCents: 100_00);
        $this->transaction($accountId, 'SALARIO', 500_00, 'in');
        $this->transaction($accountId, 'MERCADO', 200_00, 'out');

        $response = $this->actingAs($this->user)->get('/dashboard')->assertOk();

        // saldo = 100 + 500 - 200
        $response->assertSee('Saldo disponível');
        $response->assertSee('R$ 400,00');

        $response->assertSee('Entradas');
        $response->assertSee('R$ 500,00');
        $response->assertSee('Saídas');
        $response->assertSee('R$ 200,00');
    }

    public function test_a_negative_balance_is_shown(): void
    {
        $accountId = $this->account('Conta XP', 'checking', openingCents: 0);
        $this->transaction($accountId, 'MERCADO', 50_00, 'out');

        $this->actingAs($this->user)->get('/dashboard')
            ->assertOk()
            ->assertSee('R$ -50,00');
    }

    /**
     * Fatura em aberto é só a próxima competência; o total em aberto soma
     * também as parcelas que caem depois.
     */
    public function test_the_card_separates_the_open_invoice_from_the_total(): void
    {
        $cardId = $this->creditCard('Visa Black');

        $thisMonth = now()->format('Y-m-01');
        $nextMonth = now()->addMonthNoOverflow()->format('Y-m-01');

        $purchaseId = $this->purchase($cardId, 'NOTEBOOK', 300_00, installments: 3);
        $this->installment($cardId, $purchaseId, 1, 300_00, $thisMonth);
        $this->installment($cardId, $purchaseId, 2, 300_00, $nextMonth);
        $this->installment($cardId, $purchaseId, 3, 300_00, now()->addMonthsNoOverflow(2)->format('Y-m-01'));

        $response = $this->actingAs($this->user)->get('/dashboard')->assertOk();

        $response->assertSee('Fatura em aberto');
        $response->assertSee('R$ 300,00');
        $response->assertSee('Total em aberto');
        $response->assertSee('R$ 900,00');
    }

    public function test_a_card_without_installments_shows_zero(): void
    {
        $this->creditCard('Visa Black');

        $this->actingAs($this->user)->get('/dashboard')
            ->assertOk()
            ->assertSee('Fatura em aberto')
            ->assertSee('R$ 0,00');
    }

    /** Parcela já vencida não conta como fatura em aberto. */
    public function test_past_installments_are_left_out_of_the_open_invoice(): void
    {
        $cardId = $this->creditCard('Visa Black');
        $purchaseId = $this->purchase($cardId, 'ANTIGA', 70_00, installments: 1);
        $this->installment($cardId, $purchaseId, 1, 70_00, now()->subMonthsNoOverflow(3)->format('Y-m-01'));

        $this->actingAs($this->user)->get('/dashboard')
            ->assertOk()
            ->assertSee('Fatura em aberto')
            ->assertSee('R$ 0,00');
    }

    public function test_the_review_page_no_longer_offers_inline_category_creation(): void
    {
        $accountId = $this->account('Conta XP', 'checking');
        $this->transaction($accountId, 'ALGO NOVO', 10_00, 'out');

        $this->actingAs($this->user)->get('/review')
            ->assertOk()
            ->assertDontSee('new_category', escape: false)
            ->assertDontSee('Criar uma nova')
            ->assertSee(route('categories.index'), escape: false);
    }

    public function test_the_review_endpoint_rejects_a_new_category_field(): void
    {
        $accountId = $this->account('Conta XP', 'checking');
        $id = $this->transaction($accountId, 'ALGO NOVO', 10_00, 'out');

        // Sem category_id, mesmo mandando o campo antigo, precisa falhar.
        $this->actingAs($this->user)
            ->post('/review/bank/'.$id, ['new_category' => 'Academia'])
            ->assertSessionHasErrors('category_id');

        $this->assertDatabaseMissing('categories', ['name' => 'Academia']);
    }

    public function test_the_bank_tab_carries_the_edit_and_delete_actions(): void
    {
        $accountId = $this->account('Conta XP', 'checking');

        $response = $this->actingAs($this->user)->get('/bancos')->assertOk();

        $html = $response->getContent();
        $tabs = substr($html, (int) strpos($html, 'rounded-lg p-1 w-fit'), 6000);

        $this->assertStringContainsString('Editar nome da conta', $tabs);
        $this->assertStringContainsString('Excluir conta', $tabs);
        $this->assertStringContainsString(route('accounts.create'), $tabs, 'o + Adicionar fica junto das abas');
        $this->assertStringContainsString(route('banks.destroy', $accountId), $tabs);
    }

    public function test_the_card_tab_carries_the_edit_and_delete_actions(): void
    {
        $cardId = $this->creditCard('Visa Black');

        $response = $this->actingAs($this->user)->get('/cartoes')->assertOk();

        $html = $response->getContent();
        $tabs = substr($html, (int) strpos($html, 'rounded-lg p-1 w-fit'), 6000);

        $this->assertStringContainsString('Editar nome do cartão', $tabs);
        $this->assertStringContainsString('Excluir cartão', $tabs);
        $this->assertStringContainsString(route('accounts.create'), $tabs);
        $this->assertStringContainsString(route('cards.destroy', 'c'.$cardId), $tabs);
    }

    /** Aba inativa é só link: as ações pertencem ao item aberto. */
    public function test_an_inactive_tab_has_no_actions(): void
    {
        $this->account('Conta Um', 'checking');
        $this->account('Conta Dois', 'checking');

        $response = $this->actingAs($this->user)->get('/bancos')->assertOk();

        // aria-label aparece uma vez por aba ativa; title repete o texto, por isso o seletor exato
        $this->assertSame(1, substr_count($response->getContent(), 'aria-label="Editar nome da conta"'));
        $this->assertSame(1, substr_count($response->getContent(), 'aria-label="Excluir conta"'));
    }

    private function account(string $name, string $type, int $openingCents = 0): int
    {
        return DB::table('accounts')->insertGetId([
            'user_id' => $this->user->id,
            'institution_id' => $this->institutionId,
            'name' => $name,
            'type' => $type,
            'opening_balance_cents' => $openingCents,
            'opening_balance_date' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function creditCard(string $name): int
    {
        $accountId = $this->account($name, 'credit_card');

        return DB::table('credit_cards')->insertGetId([
            'user_id' => $this->user->id,
            'account_id' => $accountId,
            'brand' => 'visa',
            'closing_day' => 18,
            'due_day' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function transaction(int $accountId, string $description, int $cents, string $direction): int
    {
        static $n = 0;
        $n++;

        return DB::table('transactions')->insertGetId([
            'user_id' => $this->user->id,
            'account_id' => $accountId,
            'direction' => $direction,
            'amount_cents' => $cents,
            'currency' => 'BRL',
            'occurred_on' => '2026-08-01',
            'cash_effect_on' => '2026-08-01',
            'description' => $description,
            'raw_description' => $description,
            'payment_method' => 'pix',
            'status' => 'cleared',
            'source' => 'csv',
            'needs_review' => true,
            'fingerprint' => hash('sha256', 'f'.$n.$description),
            'fingerprint_loose' => hash('sha256', 'l'.$n.$description),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function purchase(int $cardId, string $description, int $cents, int $installments): int
    {
        return DB::table('card_purchases')->insertGetId([
            'user_id' => $this->user->id,
            'credit_card_id' => $cardId,
            'description' => $description,
            'raw_description' => $description,
            'purchase_date' => now()->format('Y-m-d'),
            'installments_total' => $installments,
            'installment_amount_cents' => $cents,
            'total_amount_cents' => $cents * $installments,
            'first_reference_month' => now()->format('Y-m-01'),
            'currency' => 'BRL',
            'status' => 'active',
            'group_key' => hash('sha256', 'g'.$description),
            'detection_confidence' => 1.000,
            'needs_review' => false,
            'category_id' => (int) DB::table('categories')->where('user_id', $this->user->id)->where('name', 'Compras')->value('id'),
            'source' => 'csv',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function installment(int $cardId, int $purchaseId, int $number, int $cents, string $month): void
    {
        DB::table('card_installments')->insert([
            'user_id' => $this->user->id,
            'purchase_id' => $purchaseId,
            'credit_card_id' => $cardId,
            'number' => $number,
            'installments_total' => 3,
            'amount_cents' => $cents,
            'reference_month' => $month,
            'status' => 'projected',
            'is_reconstructed' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
