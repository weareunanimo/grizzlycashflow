<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Os filtros da revisão tratam três origens diferentes: conta bancária e cartão
 * de benefícios (que vivem em `transactions`) e cartão de crédito (que vive em
 * `card_purchases`). Selecionar um não pode deixar os outros passarem inteiros —
 * era o bug de "Banco XP e Visa Black devolverem a mesma coisa".
 */
final class ReviewFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $bankId;

    private int $otherBankId;

    private int $voucherAccountId;

    private int $cardId;

    private int $otherCardId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        (new CategorySeeder)->run($this->user->id);

        $institutionId = DB::table('institutions')->insertGetId([
            'user_id' => $this->user->id,
            'name' => 'Banco Teste',
            'kind' => 'bank',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->bankId = $this->account($institutionId, 'Banco XP', 'checking');
        $this->otherBankId = $this->account($institutionId, 'Banco Outro', 'checking');
        $this->voucherAccountId = $this->account($institutionId, 'Caju Alimentacao', 'voucher');

        $this->cardId = $this->creditCard($institutionId, 'Visa Black XP');
        $this->otherCardId = $this->creditCard($institutionId, 'Master Outro');

        $this->transaction($this->bankId, 'LANCAMENTO DO BANCO XP');
        $this->transaction($this->otherBankId, 'LANCAMENTO DO BANCO OUTRO');
        $this->transaction($this->voucherAccountId, 'LANCAMENTO DO CAJU');
        $this->purchase($this->cardId, 'COMPRA NO VISA BLACK');
        $this->purchase($this->otherCardId, 'COMPRA NO MASTER');
    }

    public function test_without_filters_it_shows_everything(): void
    {
        $this->actingAs($this->user)->get('/review')
            ->assertOk()
            ->assertSee('LANCAMENTO DO BANCO XP')
            ->assertSee('LANCAMENTO DO BANCO OUTRO')
            ->assertSee('LANCAMENTO DO CAJU')
            ->assertSee('COMPRA NO VISA BLACK')
            ->assertSee('COMPRA NO MASTER');
    }

    /** O bug relatado: selecionar a conta deixava passar todas as compras de cartão. */
    public function test_selecting_one_bank_account_excludes_cards_entirely(): void
    {
        $this->actingAs($this->user)->get('/review?accounts[]='.$this->bankId)
            ->assertOk()
            ->assertSee('LANCAMENTO DO BANCO XP')
            ->assertDontSee('LANCAMENTO DO BANCO OUTRO')
            ->assertDontSee('LANCAMENTO DO CAJU')
            ->assertDontSee('COMPRA NO VISA BLACK')
            ->assertDontSee('COMPRA NO MASTER');
    }

    /** E o espelho: selecionar o cartão deixava passar todo o extrato. */
    public function test_selecting_one_credit_card_excludes_bank_entries_entirely(): void
    {
        $this->actingAs($this->user)->get('/review?cards[]=c'.$this->cardId)
            ->assertOk()
            ->assertSee('COMPRA NO VISA BLACK')
            ->assertDontSee('COMPRA NO MASTER')
            ->assertDontSee('LANCAMENTO DO BANCO XP')
            ->assertDontSee('LANCAMENTO DO BANCO OUTRO')
            ->assertDontSee('LANCAMENTO DO CAJU');
    }

    public function test_a_bank_account_and_a_credit_card_return_different_results(): void
    {
        $bank = $this->actingAs($this->user)->get('/review?accounts[]='.$this->bankId)->getContent();
        $card = $this->actingAs($this->user)->get('/review?cards[]=c'.$this->cardId)->getContent();

        $this->assertNotSame($bank, $card, 'conta e cartão não podem devolver o mesmo resultado');
        $this->assertStringContainsString('LANCAMENTO DO BANCO XP', $bank);
        $this->assertStringNotContainsString('LANCAMENTO DO BANCO XP', $card);
        $this->assertStringContainsString('COMPRA NO VISA BLACK', $card);
        $this->assertStringNotContainsString('COMPRA NO VISA BLACK', $bank);
    }

    /** Cartão de benefícios vive em `transactions`, mas é filtrado como cartão. */
    public function test_selecting_a_benefit_card_shows_only_its_entries(): void
    {
        $this->actingAs($this->user)->get('/review?cards[]=v'.$this->voucherAccountId)
            ->assertOk()
            ->assertSee('LANCAMENTO DO CAJU')
            ->assertDontSee('LANCAMENTO DO BANCO XP')
            ->assertDontSee('COMPRA NO VISA BLACK');
    }

    public function test_selecting_a_bank_and_a_card_together_shows_the_union(): void
    {
        $this->actingAs($this->user)
            ->get('/review?accounts[]='.$this->bankId.'&cards[]=c'.$this->cardId)
            ->assertOk()
            ->assertSee('LANCAMENTO DO BANCO XP')
            ->assertSee('COMPRA NO VISA BLACK')
            ->assertDontSee('LANCAMENTO DO BANCO OUTRO')
            ->assertDontSee('COMPRA NO MASTER')
            ->assertDontSee('LANCAMENTO DO CAJU');
    }

    public function test_selecting_a_benefit_card_and_a_credit_card_shows_both(): void
    {
        $this->actingAs($this->user)
            ->get('/review?cards[]=v'.$this->voucherAccountId.'&cards[]=c'.$this->cardId)
            ->assertOk()
            ->assertSee('LANCAMENTO DO CAJU')
            ->assertSee('COMPRA NO VISA BLACK')
            ->assertDontSee('LANCAMENTO DO BANCO XP');
    }

    public function test_it_ignores_ids_that_belong_to_another_user(): void
    {
        $other = User::factory()->create();
        (new CategorySeeder)->run($other->id);
        $foreignInstitution = DB::table('institutions')->insertGetId([
            'user_id' => $other->id, 'name' => 'Alheio', 'kind' => 'bank',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreignAccount = DB::table('accounts')->insertGetId([
            'user_id' => $other->id, 'institution_id' => $foreignInstitution,
            'name' => 'Conta Alheia', 'type' => 'checking',
            'opening_balance_cents' => 0, 'opening_balance_date' => '2026-01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('transactions')->insert([
            'user_id' => $other->id, 'account_id' => $foreignAccount,
            'direction' => 'out', 'amount_cents' => 100, 'currency' => 'BRL',
            'occurred_on' => '2026-08-01', 'cash_effect_on' => '2026-08-01',
            'description' => 'SEGREDO ALHEIO', 'raw_description' => 'SEGREDO ALHEIO',
            'payment_method' => 'pix', 'status' => 'cleared', 'source' => 'csv',
            'needs_review' => true, 'fingerprint' => hash('sha256', 'alheio'),
            'fingerprint_loose' => hash('sha256', 'alheio2'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Id de outro usuário é descartado: nunca vaza o dado alheio. Sobrando
        // nenhum filtro válido, a tela volta a mostrar tudo o que é seu — mais
        // previsível do que uma lista vazia depois de apagar uma conta.
        $this->actingAs($this->user)->get('/review?accounts[]='.$foreignAccount)
            ->assertOk()
            ->assertDontSee('SEGREDO ALHEIO')
            ->assertSee('LANCAMENTO DO BANCO XP');
    }

    public function test_the_filter_groups_are_accounts_and_cards(): void
    {
        $response = $this->actingAs($this->user)->get('/review')->assertOk();

        $response->assertSee('Contas');
        $response->assertSee('Cartões de crédito e de benefícios');
        $response->assertDontSee('Contas e benefícios');
        // o cartão de benefícios aparece no grupo de cartões
        $response->assertSee('value="v'.$this->voucherAccountId.'"', escape: false);
        $response->assertSee('value="c'.$this->cardId.'"', escape: false);
    }

    public function test_garbage_filter_values_do_not_break_the_page(): void
    {
        foreach (['cards[]=abc', 'cards[]=c', 'cards[]=v', 'cards[]=', 'accounts[]=abc', 'cards=naoarray'] as $query) {
            $this->actingAs($this->user)->get('/review?'.$query)->assertOk();
        }
    }

    private function account(int $institutionId, string $name, string $type): int
    {
        return DB::table('accounts')->insertGetId([
            'user_id' => $this->user->id,
            'institution_id' => $institutionId,
            'name' => $name,
            'type' => $type,
            'opening_balance_cents' => 0,
            'opening_balance_date' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function creditCard(int $institutionId, string $name): int
    {
        $accountId = $this->account($institutionId, $name, 'credit_card');

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

    private function transaction(int $accountId, string $description): void
    {
        static $n = 0;
        $n++;

        DB::table('transactions')->insert([
            'user_id' => $this->user->id,
            'account_id' => $accountId,
            'direction' => 'out',
            'amount_cents' => 1000 + $n,
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

    private function purchase(int $cardId, string $description): void
    {
        static $n = 0;
        $n++;

        DB::table('card_purchases')->insert([
            'user_id' => $this->user->id,
            'credit_card_id' => $cardId,
            'description' => $description,
            'raw_description' => $description,
            'purchase_date' => '2026-08-01',
            'installments_total' => 1,
            'installment_amount_cents' => 2000 + $n,
            'total_amount_cents' => 2000 + $n,
            'first_reference_month' => '2026-08-01',
            'currency' => 'BRL',
            'status' => 'active',
            'group_key' => hash('sha256', 'g'.$n.$description),
            'detection_confidence' => 1.000,
            'needs_review' => true,
            'source' => 'csv',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
