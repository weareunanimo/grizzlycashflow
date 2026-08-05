<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cadastro/exibição/exclusão de contas e cartões, incluindo o cartão de
 * benefícios (accounts.type = 'voucher'), que funciona por saldo e não gera
 * fatura — por isso não tem linha em `credit_cards`.
 */
final class CardsAndBanksPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        (new CategorySeeder)->run($this->user->id);
    }

    public function test_it_creates_a_checking_account(): void
    {
        $this->actingAs($this->user)->post('/accounts', [
            'kind' => 'checking',
            'institution_name' => 'Banco NASA S.A.',
            'account_name' => 'NASA',
        ])->assertRedirect(route('banks.index'));

        $this->assertDatabaseHas('accounts', ['name' => 'NASA', 'type' => 'checking']);
        $this->assertDatabaseHas('institutions', ['name' => 'Banco NASA S.A.', 'kind' => 'bank']);
    }

    public function test_it_creates_a_credit_card_with_invoice_fields(): void
    {
        $this->actingAs($this->user)->post('/accounts', [
            'kind' => 'credit_card',
            'institution_name' => 'Banco WTF S.A.',
            'account_name' => 'WTF',
            'brand' => 'visa',
            'closing_day' => 18,
            'due_day' => 1,
            'credit_limit' => 5000,
        ])->assertRedirect(route('cards.index'));

        $accountId = DB::table('accounts')->where('name', 'WTF')->value('id');
        $this->assertDatabaseHas('accounts', ['id' => $accountId, 'type' => 'credit_card']);
        $this->assertDatabaseHas('credit_cards', [
            'account_id' => $accountId,
            'closing_day' => 18,
            'due_day' => 1,
            'credit_limit_cents' => 500000,
        ]);
    }

    /** Benefícios: sem limite, sem fechamento, sem vencimento e sem fatura. */
    public function test_it_creates_a_benefit_card_without_invoice_fields(): void
    {
        $this->actingAs($this->user)->post('/accounts', [
            'kind' => 'voucher',
            'institution_name' => 'Caju',
            'account_name' => 'Caju Alimentação',
        ])->assertRedirect(route('cards.index'));

        $accountId = DB::table('accounts')->where('name', 'Caju Alimentação')->value('id');

        $this->assertDatabaseHas('accounts', ['id' => $accountId, 'type' => 'voucher']);
        $this->assertDatabaseMissing('credit_cards', ['account_id' => $accountId]);
    }

    public function test_a_benefit_card_does_not_require_a_due_day(): void
    {
        $this->actingAs($this->user)->post('/accounts', [
            'kind' => 'voucher',
            'institution_name' => 'Caju',
            'account_name' => 'Caju Refeição',
        ])->assertSessionHasNoErrors();
    }

    public function test_a_credit_card_still_requires_a_due_day(): void
    {
        $this->actingAs($this->user)->post('/accounts', [
            'kind' => 'credit_card',
            'institution_name' => 'Banco WTF S.A.',
            'account_name' => 'WTF',
        ])->assertSessionHasErrors('due_day');
    }

    public function test_a_benefit_card_shows_under_cards_and_not_under_banks(): void
    {
        $accountId = $this->makeVoucher('Caju Alimentação');
        $this->makeTransaction($accountId, 'RESTAURANTE DO ZE');

        $this->actingAs($this->user)->get('/bancos')
            ->assertOk()
            ->assertDontSee('Caju Alimentação');

        $this->actingAs($this->user)->get('/cartoes')
            ->assertOk()
            ->assertSee('Caju Alimentação')
            ->assertSee('RESTAURANTE DO ZE')
            ->assertSee('Cartão de benefícios');
    }

    public function test_a_benefit_card_shows_the_review_dot_for_uncategorized_items(): void
    {
        $accountId = $this->makeVoucher('Caju Alimentação');
        $this->makeTransaction($accountId, 'RESTAURANTE DO ZE');

        $this->actingAs($this->user)->get('/cartoes?card=v'.$accountId)
            ->assertOk()
            ->assertSee('Precisa de revisão', escape: false)
            ->assertDontSee('>revisar<', escape: false);
    }

    public function test_a_credit_card_purchase_shows_the_review_dot(): void
    {
        $cardId = $this->makeCreditCard('Visa Black');
        $this->makePurchase($cardId);

        $this->actingAs($this->user)->get('/cartoes?card=c'.$cardId)
            ->assertOk()
            ->assertSee('Precisa de revisão', escape: false);
    }

    public function test_a_bank_transaction_shows_the_review_dot(): void
    {
        $accountId = $this->makeAccount('Conta Corrente', 'checking');
        $this->makeTransaction($accountId, 'PADARIA');

        $this->actingAs($this->user)->get('/bancos?account='.$accountId)
            ->assertOk()
            ->assertSee('Precisa de revisão', escape: false)
            ->assertDontSee('>revisar<', escape: false);
    }

    public function test_the_dashboard_shows_a_clickable_card_count(): void
    {
        $this->makeCreditCard('Visa Black');
        $this->makeVoucher('Caju Alimentação');

        $this->actingAs($this->user)->get('/dashboard')
            ->assertOk()
            ->assertSee('Cartões')
            ->assertSee(route('cards.index'), escape: false)
            ->assertSee(route('banks.index'), escape: false)
            ->assertDontSee($this->user->email);
    }

    public function test_a_benefit_card_has_no_invoice_tabs(): void
    {
        $accountId = $this->makeVoucher('Caju Alimentação');

        $this->actingAs($this->user)->get('/cartoes?card=v'.$accountId)
            ->assertOk()
            ->assertDontSee('Projeção de faturas');
    }

    public function test_a_numeric_card_param_still_selects_a_credit_card(): void
    {
        $cardId = $this->makeCreditCard('Visa Black');

        $this->actingAs($this->user)->get('/cartoes?card='.$cardId)
            ->assertOk()
            ->assertSee('Projeção de faturas');
    }

    public function test_it_deletes_a_benefit_card_and_its_transactions(): void
    {
        $accountId = $this->makeVoucher('Caju Alimentação');
        $this->makeTransaction($accountId, 'RESTAURANTE DO ZE');

        $this->actingAs($this->user)
            ->delete('/cartoes/v'.$accountId)
            ->assertRedirect(route('cards.index'));

        $this->assertDatabaseMissing('accounts', ['id' => $accountId]);
        $this->assertSame(0, DB::table('transactions')->where('account_id', $accountId)->count());
    }

    public function test_it_deletes_a_credit_card_and_its_purchases(): void
    {
        $cardId = $this->makeCreditCard('Visa Black');
        $accountId = (int) DB::table('credit_cards')->where('id', $cardId)->value('account_id');
        $this->makePurchase($cardId);

        $this->actingAs($this->user)
            ->delete('/cartoes/c'.$cardId)
            ->assertRedirect(route('cards.index'));

        $this->assertDatabaseMissing('credit_cards', ['id' => $cardId]);
        $this->assertDatabaseMissing('accounts', ['id' => $accountId]);
        $this->assertSame(0, DB::table('card_purchases')->where('credit_card_id', $cardId)->count());
    }

    public function test_it_deletes_a_bank_account_and_its_transactions(): void
    {
        $accountId = $this->makeAccount('Conta Corrente', 'checking');
        $this->makeTransaction($accountId, 'PADARIA');

        $this->actingAs($this->user)
            ->delete('/bancos/'.$accountId)
            ->assertRedirect(route('banks.index'));

        $this->assertDatabaseMissing('accounts', ['id' => $accountId]);
        $this->assertSame(0, DB::table('transactions')->where('account_id', $accountId)->count());
    }

    public function test_it_renames_a_bank_account(): void
    {
        $accountId = $this->makeAccount('Conta Antiga', 'checking');

        $this->actingAs($this->user)
            ->patch('/bancos/'.$accountId, ['name' => 'Conta Nova'])
            ->assertRedirect(route('banks.index', ['account' => $accountId]));

        $this->assertDatabaseHas('accounts', ['id' => $accountId, 'name' => 'Conta Nova']);
    }

    public function test_it_renames_a_credit_card(): void
    {
        $cardId = $this->makeCreditCard('Visa Antigo');
        $accountId = (int) DB::table('credit_cards')->where('id', $cardId)->value('account_id');

        $this->actingAs($this->user)
            ->patch('/cartoes/c'.$cardId, ['name' => 'Visa Novo'])
            ->assertRedirect(route('cards.index', ['card' => 'c'.$cardId]));

        $this->assertDatabaseHas('accounts', ['id' => $accountId, 'name' => 'Visa Novo']);
    }

    public function test_it_renames_a_benefit_card(): void
    {
        $accountId = $this->makeVoucher('Caju Antigo');

        $this->actingAs($this->user)
            ->patch('/cartoes/v'.$accountId, ['name' => 'Caju Novo'])
            ->assertRedirect(route('cards.index', ['card' => 'v'.$accountId]));

        $this->assertDatabaseHas('accounts', ['id' => $accountId, 'name' => 'Caju Novo']);
    }

    public function test_renaming_requires_a_name(): void
    {
        $accountId = $this->makeAccount('Conta Corrente', 'checking');

        $this->actingAs($this->user)
            ->patch('/bancos/'.$accountId, ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseHas('accounts', ['id' => $accountId, 'name' => 'Conta Corrente']);
    }

    public function test_it_cannot_rename_another_users_account(): void
    {
        $other = User::factory()->create();
        $accountId = $this->makeAccount('Conta Corrente', 'checking');

        $this->actingAs($other)->patch('/bancos/'.$accountId, ['name' => 'Invadida'])->assertNotFound();
        $this->assertDatabaseHas('accounts', ['id' => $accountId, 'name' => 'Conta Corrente']);
    }

    public function test_it_cannot_rename_another_users_card(): void
    {
        $other = User::factory()->create();
        $cardId = $this->makeCreditCard('Visa Black');

        $this->actingAs($other)->patch('/cartoes/c'.$cardId, ['name' => 'Invadido'])->assertNotFound();
    }

    /** Um cartão não pode ser renomeado pela rota de contas bancárias, nem o contrário. */
    public function test_the_bank_rename_route_refuses_a_card_account(): void
    {
        $cardId = $this->makeCreditCard('Visa Black');
        $accountId = (int) DB::table('credit_cards')->where('id', $cardId)->value('account_id');

        $this->actingAs($this->user)->patch('/bancos/'.$accountId, ['name' => 'Nao Deveria'])->assertNotFound();
        $this->assertDatabaseHas('accounts', ['id' => $accountId, 'name' => 'Visa Black']);
    }

    public function test_it_cannot_delete_another_users_account(): void
    {
        $other = User::factory()->create();
        $accountId = $this->makeAccount('Conta Corrente', 'checking');

        $this->actingAs($other)->delete('/bancos/'.$accountId)->assertNotFound();
        $this->assertDatabaseHas('accounts', ['id' => $accountId]);
    }

    public function test_the_import_page_offers_benefit_cards_in_the_statement_form(): void
    {
        $this->makeVoucher('Caju Alimentação');

        $this->actingAs($this->user)->get('/importar')
            ->assertOk()
            ->assertSee('Caju Alimentação');
    }

    private function institutionId(): int
    {
        $id = DB::table('institutions')->where('user_id', $this->user->id)->value('id');

        return $id ? (int) $id : (int) DB::table('institutions')->insertGetId([
            'user_id' => $this->user->id,
            'name' => 'Banco Teste',
            'kind' => 'bank',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeAccount(string $name, string $type): int
    {
        return DB::table('accounts')->insertGetId([
            'user_id' => $this->user->id,
            'institution_id' => $this->institutionId(),
            'name' => $name,
            'type' => $type,
            'opening_balance_cents' => 0,
            'opening_balance_date' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeVoucher(string $name): int
    {
        return $this->makeAccount($name, 'voucher');
    }

    private function makeCreditCard(string $name): int
    {
        $accountId = $this->makeAccount($name, 'credit_card');

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

    private function makeTransaction(int $accountId, string $description): int
    {
        static $n = 0;
        $n++;

        return DB::table('transactions')->insertGetId([
            'user_id' => $this->user->id,
            'account_id' => $accountId,
            'direction' => 'out',
            'amount_cents' => 2500,
            'currency' => 'BRL',
            'occurred_on' => '2026-08-01',
            'cash_effect_on' => '2026-08-01',
            'description' => $description,
            'raw_description' => $description,
            'payment_method' => 'debit_card',
            'status' => 'cleared',
            'source' => 'csv',
            'needs_review' => true,
            'fingerprint' => hash('sha256', 'f'.$n.$description),
            'fingerprint_loose' => hash('sha256', 'l'.$n.$description),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makePurchase(int $cardId): int
    {
        return DB::table('card_purchases')->insertGetId([
            'user_id' => $this->user->id,
            'credit_card_id' => $cardId,
            'description' => 'MP*MERCADOLIVRE',
            'purchase_date' => '2026-08-01',
            'installments_total' => 1,
            'installment_amount_cents' => 1000,
            'total_amount_cents' => 1000,
            'first_reference_month' => '2026-08-01',
            'currency' => 'BRL',
            'status' => 'active',
            'group_key' => hash('sha256', 'gk'.$cardId),
            'detection_confidence' => 1.000,
            'needs_review' => true,
            'source' => 'csv',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
