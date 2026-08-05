<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A fila de revisão precisa pegar todo lançamento sem categoria — inclusive os
 * importados antes de a flag `needs_review` passar a ser marcada, que ficaram
 * com categoria nula e flag desligada (o caso real em produção) — e precisa
 * agrupar por estabelecimento, revisando cada um uma vez só.
 */
final class ReviewQueueGroupingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $accountId;

    private int $cardId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        (new CategorySeeder)->run($this->user->id);

        $institutionId = DB::table('institutions')->insertGetId([
            'user_id' => $this->user->id,
            'name' => 'Banco XP',
            'kind' => 'bank',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->accountId = $this->account($institutionId, 'Conta XP', 'checking');

        $cardAccountId = $this->account($institutionId, 'Visa Black XP', 'credit_card');
        $this->cardId = DB::table('credit_cards')->insertGetId([
            'user_id' => $this->user->id,
            'account_id' => $cardAccountId,
            'brand' => 'visa',
            'closing_day' => 18,
            'due_day' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * O bug do print: compras com categoria nula mas needs_review = false não
     * apareciam na revisão, porque a fila olhava só a flag.
     */
    public function test_a_card_purchase_without_category_appears_even_if_the_flag_is_off(): void
    {
        $this->purchase('TONITOYS', needsReview: false, categoryId: null);

        $this->actingAs($this->user)->get('/review')
            ->assertOk()
            ->assertSee('Tonitoys');
    }

    public function test_a_bank_transaction_without_category_appears_even_if_the_flag_is_off(): void
    {
        $this->transaction('MAGIC GAMES', needsReview: false, categoryId: null);

        $this->actingAs($this->user)->get('/review')
            ->assertOk()
            ->assertSee('MAGIC GAMES');
    }

    public function test_an_item_that_already_has_a_category_stays_out_of_the_queue(): void
    {
        $this->purchase('ALIEXPRESS', needsReview: false, categoryId: $this->categoryId('Compras'));

        $this->actingAs($this->user)->get('/review')
            ->assertOk()
            ->assertDontSee('AliExpress');
    }

    /** Mesmo estabelecimento com sufixo diferente é um grupo só. */
    public function test_equivalent_items_are_shown_as_a_single_representative(): void
    {
        $this->purchase('MP*MERCADOLIVRE');
        $this->purchase('MP*MERCADOLIVRE 4471');
        $this->purchase('MP*MERCADOLIVRE 9902');

        $response = $this->actingAs($this->user)->get('/review')->assertOk();

        // um card só, e ele diz por quantos lançamentos responde
        $this->assertSame(1, substr_count($response->getContent(), 'name="category_id"'));
        $response->assertSee('3 lançamentos deste estabelecimento');
        $response->assertSee('1 estabelecimento a revisar');
    }

    public function test_different_establishments_are_not_grouped(): void
    {
        $this->purchase('IFD*SANTA LARICA PASTELAR');
        $this->purchase('IFD*AIRONI CLEITON MARTIN');

        $response = $this->actingAs($this->user)->get('/review')->assertOk();

        $this->assertSame(2, substr_count($response->getContent(), 'name="category_id"'));
        $response->assertSee('2 estabelecimentos a revisar');
    }

    public function test_a_group_spans_bank_and_card(): void
    {
        $this->transaction('MP*MERCADOLIVRE');
        $this->purchase('MP*MERCADOLIVRE 4471');

        $response = $this->actingAs($this->user)->get('/review')->assertOk();

        $this->assertSame(1, substr_count($response->getContent(), 'name="category_id"'));
        $response->assertSee('2 lançamentos deste estabelecimento');
    }

    /** Item 3: confirmar o representante categoriza o grupo inteiro. */
    public function test_confirming_the_representative_categorizes_the_whole_group(): void
    {
        $a = $this->purchase('MP*MERCADOLIVRE');
        $b = $this->purchase('MP*MERCADOLIVRE 4471');
        $c = $this->purchase('MP*MERCADOLIVRE 9902');
        $untouched = $this->purchase('TONITOYS');
        $categoryId = $this->categoryId('Compras');

        $this->actingAs($this->user)
            ->post('/review/card/'.$a, ['category_id' => $categoryId])
            ->assertRedirect();

        foreach ([$a, $b, $c] as $id) {
            $row = DB::table('card_purchases')->where('id', $id)->first();
            $this->assertSame($categoryId, (int) $row->category_id, "compra {$id} deveria ter sido categorizada");
            $this->assertFalse((bool) $row->needs_review);
        }

        $this->assertNull(DB::table('card_purchases')->where('id', $untouched)->value('category_id'));
    }

    public function test_confirming_replicates_across_bank_and_card(): void
    {
        $bankId = $this->transaction('MP*MERCADOLIVRE');
        $cardId = $this->purchase('MP*MERCADOLIVRE 4471');
        $categoryId = $this->categoryId('Compras');

        $this->actingAs($this->user)
            ->post('/review/bank/'.$bankId, ['category_id' => $categoryId])
            ->assertRedirect();

        $this->assertSame($categoryId, (int) DB::table('transactions')->where('id', $bankId)->value('category_id'));
        $this->assertSame($categoryId, (int) DB::table('card_purchases')->where('id', $cardId)->value('category_id'));
    }

    public function test_the_status_message_reports_how_many_were_categorized(): void
    {
        $a = $this->purchase('MP*MERCADOLIVRE');
        $this->purchase('MP*MERCADOLIVRE 4471');

        $this->actingAs($this->user)
            ->post('/review/card/'.$a, ['category_id' => $this->categoryId('Compras')])
            ->assertSessionHas('status', fn (string $status) => str_contains($status, '2 lançamentos'));
    }

    public function test_confirming_never_touches_another_users_items(): void
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
            'direction' => 'out', 'amount_cents' => 500, 'currency' => 'BRL',
            'occurred_on' => '2026-08-01', 'cash_effect_on' => '2026-08-01',
            'description' => 'MP*MERCADOLIVRE', 'raw_description' => 'MP*MERCADOLIVRE',
            'payment_method' => 'pix', 'status' => 'cleared', 'source' => 'csv',
            'needs_review' => true, 'fingerprint' => hash('sha256', 'alheio'),
            'fingerprint_loose' => hash('sha256', 'alheio2'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $mine = $this->transaction('MP*MERCADOLIVRE');

        $this->actingAs($this->user)
            ->post('/review/bank/'.$mine, ['category_id' => $this->categoryId('Compras')])
            ->assertRedirect();

        $this->assertNull(
            DB::table('transactions')->where('user_id', $other->id)->value('category_id'),
            'o lançamento do outro usuário não pode ser tocado'
        );
    }

    public function test_the_menu_badge_counts_groups_not_entries(): void
    {
        $this->purchase('MP*MERCADOLIVRE');
        $this->purchase('MP*MERCADOLIVRE 4471');
        $this->purchase('MP*MERCADOLIVRE 9902');
        $this->purchase('TONITOYS');

        // 4 lançamentos, 2 estabelecimentos
        $this->actingAs($this->user)->get('/dashboard')
            ->assertOk()
            ->assertSeeInOrder(['Revisar', '2']);
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

    private function transaction(string $description, bool $needsReview = true, ?int $categoryId = null): int
    {
        static $n = 0;
        $n++;

        return DB::table('transactions')->insertGetId([
            'user_id' => $this->user->id,
            'account_id' => $this->accountId,
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
            'category_id' => $categoryId,
            'needs_review' => $needsReview,
            'fingerprint' => hash('sha256', 'f'.$n.$description),
            'fingerprint_loose' => hash('sha256', 'l'.$n.$description),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function purchase(string $description, bool $needsReview = true, ?int $categoryId = null): int
    {
        static $n = 0;
        $n++;

        return DB::table('card_purchases')->insertGetId([
            'user_id' => $this->user->id,
            'credit_card_id' => $this->cardId,
            'description' => $description,
            'raw_description' => $description,
            'purchase_date' => '2026-08-02',
            'installments_total' => 1,
            'installment_amount_cents' => 2000 + $n,
            'total_amount_cents' => 2000 + $n,
            'first_reference_month' => '2026-08-01',
            'currency' => 'BRL',
            'status' => 'active',
            'group_key' => hash('sha256', 'g'.$n.$description),
            'detection_confidence' => 1.000,
            'category_id' => $categoryId,
            'needs_review' => $needsReview,
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
