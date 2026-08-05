<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A tela Revisar já quebrou duas vezes em produção por diferenças entre SQLite
 * (dev) e MySQL (produção). Estes testes cobrem os cenários reais da fila —
 * banco, cartão, filtros, data nula, descrição longa — para que qualquer
 * regressão apareça aqui antes de virar "Server Error" na mão do usuário.
 */
final class ReviewPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $accountId;

    private int $secondAccountId;

    private int $cardId;

    private int $secondCardId;

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

        $this->accountId = $this->makeAccount($institutionId, 'Conta Um', 'checking');
        $this->secondAccountId = $this->makeAccount($institutionId, 'Conta Dois', 'checking');

        $this->cardId = $this->makeCard($institutionId, 'Cartão Um');
        $this->secondCardId = $this->makeCard($institutionId, 'Cartão Dois');
    }

    public function test_it_opens_with_nothing_pending(): void
    {
        $this->actingAs($this->user)->get('/review')->assertOk();
    }

    public function test_it_opens_with_only_bank_items_pending(): void
    {
        $this->makeTransaction($this->accountId, 'Padaria do Bairro');

        $this->actingAs($this->user)->get('/review')
            ->assertOk()
            ->assertSee('Padaria do Bairro');
    }

    public function test_it_opens_with_only_card_items_pending(): void
    {
        $this->makePurchase($this->cardId, 'MP*MERCADOLIVRE');

        $this->actingAs($this->user)->get('/review')->assertOk();
    }

    public function test_it_opens_with_both_kinds_pending(): void
    {
        $this->makeTransaction($this->accountId, 'Padaria do Bairro');
        $this->makePurchase($this->cardId, 'MP*MERCADOLIVRE');

        $this->actingAs($this->user)->get('/review')->assertOk();
    }

    /**
     * `card_purchases.purchase_date` é nullable — uma fatura sem data de compra
     * não pode derrubar a fila inteira.
     */
    public function test_it_opens_when_a_card_purchase_has_no_purchase_date(): void
    {
        $this->makePurchase($this->cardId, 'Compra sem data', purchaseDate: null);

        $this->actingAs($this->user)->get('/review')->assertOk();
    }

    public function test_it_opens_for_every_type_filter(): void
    {
        $this->makeTransaction($this->accountId, 'Padaria do Bairro');
        $this->makePurchase($this->cardId, 'MP*MERCADOLIVRE');

        foreach (['all', 'bank', 'card', 'lixo'] as $type) {
            $this->actingAs($this->user)->get('/review?type='.$type)->assertOk();
        }
    }

    public function test_it_filters_by_selected_accounts(): void
    {
        $this->makeTransaction($this->accountId, 'Compra na Conta Um');
        $this->makeTransaction($this->secondAccountId, 'Compra na Conta Dois');

        $this->actingAs($this->user)
            ->get('/review?accounts[]='.$this->accountId)
            ->assertOk()
            ->assertSee('Compra na Conta Um')
            ->assertDontSee('Compra na Conta Dois');
    }

    public function test_it_filters_by_selected_cards(): void
    {
        $this->makePurchase($this->cardId, 'COMPRA CARTAO UM');
        $this->makePurchase($this->secondCardId, 'COMPRA CARTAO DOIS');

        $this->actingAs($this->user)
            ->get('/review?cards[]=c'.$this->cardId)
            ->assertOk()
            ->assertSee('Compra Cartao Um')
            ->assertDontSee('Compra Cartao Dois');
    }

    public function test_it_filters_by_accounts_and_cards_together(): void
    {
        $this->makeTransaction($this->accountId, 'Compra na Conta Um');
        $this->makePurchase($this->cardId, 'COMPRA CARTAO UM');

        $this->actingAs($this->user)
            ->get('/review?accounts[]='.$this->accountId.'&cards[]=c'.$this->cardId)
            ->assertOk()
            ->assertSee('Compra na Conta Um')
            ->assertSee('Compra Cartao Um');
    }

    public function test_it_survives_garbage_filter_values(): void
    {
        $this->makeTransaction($this->accountId, 'Padaria do Bairro');

        foreach (['accounts=nao-e-array', 'accounts[]=abc', 'cards[]=999999', 'accounts[x]=1', 'page=abc'] as $query) {
            $this->actingAs($this->user)->get('/review?'.$query)->assertOk();
        }
    }

    public function test_it_paginates_past_the_first_page(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->makeTransaction($this->accountId, 'Lançamento '.$i);
        }

        $this->actingAs($this->user)->get('/review?page=2')->assertOk();
        $this->actingAs($this->user)->get('/review?page=99')->assertOk();
    }

    public function test_confirming_a_bank_item_categorizes_and_replicates_it(): void
    {
        $first = $this->makeTransaction($this->accountId, 'Padaria do Bairro');
        $second = $this->makeTransaction($this->accountId, 'padaria do bairro');
        $categoryId = $this->categoryId('Padaria');

        $this->actingAs($this->user)
            ->post('/review/bank/'.$first, ['category_id' => $categoryId])
            ->assertRedirect();

        foreach ([$first, $second] as $id) {
            $row = DB::table('transactions')->where('id', $id)->first();
            $this->assertSame($categoryId, (int) $row->category_id);
            $this->assertFalse((bool) $row->needs_review);
        }

        $this->assertDatabaseHas('rules', ['user_id' => $this->user->id, 'created_from' => 'manual']);
    }

    public function test_confirming_a_card_item_categorizes_and_replicates_it(): void
    {
        $first = $this->makePurchase($this->cardId, 'MP*MERCADOLIVRE');
        $second = $this->makePurchase($this->cardId, 'mp*mercadolivre');
        $categoryId = $this->categoryId('Compras');

        $this->actingAs($this->user)
            ->post('/review/card/'.$first, ['category_id' => $categoryId])
            ->assertRedirect();

        foreach ([$first, $second] as $id) {
            $row = DB::table('card_purchases')->where('id', $id)->first();
            $this->assertSame($categoryId, (int) $row->category_id);
            $this->assertFalse((bool) $row->needs_review);
        }
    }

    /** `rules.name` tem 140 caracteres — descrição real de banco estoura fácil. */
    public function test_confirming_an_item_with_a_very_long_description_still_works(): void
    {
        $description = str_repeat('DESCRICAO MUITO LONGA DE VERDADE ', 7); // 231 chars: cabe em description(255), estoura rules.name(140)
        $id = $this->makeTransaction($this->accountId, $description);

        $this->actingAs($this->user)
            ->post('/review/bank/'.$id, ['category_id' => $this->categoryId('Compras')])
            ->assertRedirect();

        $name = DB::table('rules')->where('user_id', $this->user->id)->value('name');
        $this->assertLessThanOrEqual(140, mb_strlen((string) $name));
    }

    public function test_it_keeps_the_filters_after_confirming(): void
    {
        $id = $this->makeTransaction($this->accountId, 'Padaria do Bairro');

        $this->actingAs($this->user)
            ->post('/review/bank/'.$id.'?type=bank&accounts[]='.$this->accountId, ['category_id' => $this->categoryId('Padaria')])
            ->assertRedirect();
    }

    public function test_it_never_shows_another_users_pending_items(): void
    {
        $other = User::factory()->create();
        (new CategorySeeder)->run($other->id);
        $institutionId = DB::table('institutions')->where('user_id', $this->user->id)->value('id');

        DB::table('transactions')->insert($this->transactionRow($this->accountId, 'Segredo do Vizinho') + ['user_id' => $other->id]);

        $this->actingAs($this->user)->get('/review')
            ->assertOk()
            ->assertDontSee('Segredo do Vizinho');
    }

    private function makeAccount(int $institutionId, string $name, string $type): int
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

    private function makeCard(int $institutionId, string $name): int
    {
        $accountId = $this->makeAccount($institutionId, $name, 'credit_card');

        return DB::table('credit_cards')->insertGetId([
            'user_id' => $this->user->id,
            'account_id' => $accountId,
            'brand' => 'visa',
            'closing_day' => 10,
            'due_day' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function transactionRow(int $accountId, string $description): array
    {
        static $n = 0;
        $n++;

        return [
            'account_id' => $accountId,
            'direction' => 'out',
            'amount_cents' => 1234,
            'currency' => 'BRL',
            'occurred_on' => '2026-08-01',
            'cash_effect_on' => '2026-08-01',
            'description' => $description,
            'raw_description' => $description,
            'payment_method' => 'pix',
            'status' => 'cleared',
            'source' => 'csv',
            'needs_review' => true,
            'fingerprint' => hash('sha256', 'fp'.$n.$description),
            'fingerprint_loose' => hash('sha256', 'fpl'.$n.$description),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function makeTransaction(int $accountId, string $description): int
    {
        return DB::table('transactions')->insertGetId(
            $this->transactionRow($accountId, $description) + ['user_id' => $this->user->id]
        );
    }

    private function makePurchase(int $cardId, string $description, ?string $purchaseDate = '2026-08-01'): int
    {
        static $n = 0;
        $n++;

        return DB::table('card_purchases')->insertGetId([
            'user_id' => $this->user->id,
            'credit_card_id' => $cardId,
            'description' => $description,
            'raw_description' => $description,
            'purchase_date' => $purchaseDate,
            'installments_total' => 1,
            'installment_amount_cents' => 4321,
            'total_amount_cents' => 4321,
            'first_reference_month' => '2026-08-01',
            'currency' => 'BRL',
            'status' => 'active',
            'group_key' => hash('sha256', 'gk'.$n.$description),
            'detection_confidence' => 1.000,
            'needs_review' => true,
            'source' => 'csv',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function categoryId(string $name): int
    {
        return (int) DB::table('categories')
            ->where('user_id', $this->user->id)
            ->where('name', $name)
            ->value('id');
    }
}
