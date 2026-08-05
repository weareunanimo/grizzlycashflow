<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\RendimentoCategorizer;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sugestão de categoria na revisão: descrição idêntica, mesmo estabelecimento
 * (ignorando prefixo de gateway), regra ativa — e a criação de categoria nova
 * durante a própria revisão.
 */
final class ReviewSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $accountId;

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

        $this->accountId = DB::table('accounts')->insertGetId([
            'user_id' => $this->user->id,
            'institution_id' => $institutionId,
            'name' => 'Conta Corrente',
            'type' => 'checking',
            'opening_balance_cents' => 0,
            'opening_balance_date' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_it_suggests_from_an_identical_description(): void
    {
        $padaria = $this->categoryId('Padaria');
        $this->makeTransaction('PADARIA CENTRAL', categoryId: $padaria);
        $this->makeTransaction('PADARIA CENTRAL');

        $this->actingAs($this->user)->get('/review')
            ->assertOk()
            ->assertSee('Sugestão')
            ->assertSee('Padaria')
            ->assertSee('mesma descrição')
            ->assertSee('Aprovar');
    }

    /** "MP*MERCADOLIVRE 4471" e "MP*MERCADOLIVRE" são o mesmo estabelecimento. */
    public function test_it_suggests_from_the_same_merchant_with_a_different_suffix(): void
    {
        $compras = $this->categoryId('Compras');
        $this->makeTransaction('MP*MERCADOLIVRE', categoryId: $compras);
        $this->makeTransaction('MP*MERCADOLIVRE 4471');

        $this->actingAs($this->user)->get('/review')
            ->assertOk()
            ->assertSee('Sugestão')
            ->assertSee('mesmo estabelecimento');
    }

    public function test_it_suggests_from_an_active_rule(): void
    {
        $farmacia = $this->categoryId('Farmácia');

        DB::table('rules')->insert([
            'user_id' => $this->user->id,
            'name' => 'Drogaria',
            'priority' => 1,
            'is_active' => true,
            'stop_on_match' => true,
            'conditions' => json_encode(['any' => [['field' => 'raw_description', 'op' => 'contains_ci', 'value' => 'DROGARIA']]]),
            'actions' => json_encode(['set_category_id' => $farmacia]),
            'applies_to' => 'all',
            'created_from' => 'manual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->makeTransaction('DROGARIA SAO PAULO 33');

        $this->actingAs($this->user)->get('/review')
            ->assertOk()
            ->assertSee('Sugestão')
            ->assertSee('regra de categorização');
    }

    public function test_it_says_there_is_no_suggestion_when_nothing_matches(): void
    {
        $this->makeTransaction('ESTABELECIMENTO INEDITO XYZ');

        $this->actingAs($this->user)->get('/review')
            ->assertOk()
            ->assertSee('Sem sugestão')
            ->assertSee('Confirmar');
    }

    public function test_it_creates_a_new_category_during_review(): void
    {
        $id = $this->makeTransaction('SMART FIT ACADEMIA');

        $this->actingAs($this->user)
            ->post('/review/bank/'.$id, ['new_category' => 'Academia'])
            ->assertRedirect();

        $categoryId = DB::table('categories')
            ->where('user_id', $this->user->id)
            ->where('name', 'Academia')
            ->value('id');

        $this->assertNotNull($categoryId, 'esperava a categoria nova criada');
        $this->assertSame((int) $categoryId, (int) DB::table('transactions')->where('id', $id)->value('category_id'));
        $this->assertFalse((bool) DB::table('transactions')->where('id', $id)->value('needs_review'));
    }

    public function test_creating_a_category_that_already_exists_reuses_it(): void
    {
        $before = DB::table('categories')->where('user_id', $this->user->id)->count();
        $id = $this->makeTransaction('DROGARIA X');

        // "farmacia" e "Farmácia" viram o mesmo slug.
        $this->actingAs($this->user)
            ->post('/review/bank/'.$id, ['new_category' => 'farmacia'])
            ->assertRedirect();

        $this->assertSame($before, DB::table('categories')->where('user_id', $this->user->id)->count());
        $this->assertSame(
            $this->categoryId('Farmácia'),
            (int) DB::table('transactions')->where('id', $id)->value('category_id')
        );
    }

    public function test_it_requires_a_category_or_a_new_one(): void
    {
        $id = $this->makeTransaction('QUALQUER COISA');

        $this->actingAs($this->user)
            ->post('/review/bank/'.$id, [])
            ->assertSessionHasErrors();

        $this->assertTrue((bool) DB::table('transactions')->where('id', $id)->value('needs_review'));
    }

    public function test_it_refuses_a_category_from_another_user(): void
    {
        $other = User::factory()->create();
        (new CategorySeeder)->run($other->id);
        $foreign = (int) DB::table('categories')->where('user_id', $other->id)->value('id');

        $id = $this->makeTransaction('QUALQUER COISA');

        $this->actingAs($this->user)->post('/review/bank/'.$id, ['category_id' => $foreign])->assertNotFound();
        $this->assertTrue((bool) DB::table('transactions')->where('id', $id)->value('needs_review'));
    }

    public function test_the_review_page_no_longer_has_type_tabs(): void
    {
        $this->makeTransaction('QUALQUER COISA');

        $response = $this->actingAs($this->user)->get('/review')->assertOk();

        $response->assertDontSee(route('review.index', ['type' => 'bank']), escape: false);
        $response->assertDontSee('>Todos<', escape: false);
    }

    public function test_rendimento_automatico_is_backfilled_into_rendimentos(): void
    {
        $a = $this->makeTransaction('Rendimento automático');
        $b = $this->makeTransaction('Rendimento automático RDB');

        $result = RendimentoCategorizer::backfill($this->user->id);

        $expected = $this->categoryId('Rendimentos');
        $this->assertSame(2, $result['updated']);

        foreach ([$a, $b] as $id) {
            $row = DB::table('transactions')->where('id', $id)->first();
            $this->assertSame($expected, (int) $row->category_id);
            $this->assertFalse((bool) $row->needs_review);
        }
    }

    public function test_the_rendimento_backfill_is_idempotent(): void
    {
        $this->makeTransaction('Rendimento automático');

        $this->assertSame(1, RendimentoCategorizer::backfill($this->user->id)['updated']);
        $this->assertSame(0, RendimentoCategorizer::backfill($this->user->id)['updated']);
    }

    public function test_the_backfill_leaves_other_transactions_alone(): void
    {
        $other = $this->makeTransaction('PADARIA CENTRAL');

        RendimentoCategorizer::backfill($this->user->id);

        $this->assertNull(DB::table('transactions')->where('id', $other)->value('category_id'));
    }

    /** A própria tela conserta o histórico — o hosting não tem shell. */
    public function test_opening_the_review_page_classifies_pending_rendimentos(): void
    {
        $id = $this->makeTransaction('Rendimento automático');
        $other = $this->makeTransaction('PADARIA CENTRAL');

        $this->actingAs($this->user)->get('/review')
            ->assertOk()
            ->assertDontSee('Rendimento automático')
            ->assertSee('PADARIA CENTRAL');

        $this->assertSame(
            $this->categoryId('Rendimentos'),
            (int) DB::table('transactions')->where('id', $id)->value('category_id')
        );
        $this->assertTrue((bool) DB::table('transactions')->where('id', $other)->value('needs_review'));
    }

    public function test_the_artisan_command_classifies_rendimentos(): void
    {
        $id = $this->makeTransaction('Rendimento automático');

        $this->artisan('grizzly:classificar-rendimentos')->assertSuccessful();

        $this->assertSame(
            $this->categoryId('Rendimentos'),
            (int) DB::table('transactions')->where('id', $id)->value('category_id')
        );
    }

    private function makeTransaction(string $description, ?int $categoryId = null): int
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
            'needs_review' => $categoryId === null,
            'fingerprint' => hash('sha256', 'f'.$n.$description),
            'fingerprint_loose' => hash('sha256', 'l'.$n.$description),
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
