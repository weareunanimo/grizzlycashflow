<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\MerchantRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reproduz o caminho real de produção: sobe os CSVs de verdade (extrato + fatura)
 * pelo pipeline de importação e só então abre a fila de revisão. É o cenário que
 * o usuário tem na mão — dados vindos do parser, não fixtures sintéticas.
 */
final class ReviewAfterRealImportTest extends TestCase
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
        (new MerchantRuleSeeder)->run($this->user->id);

        $institutionId = DB::table('institutions')->insertGetId([
            'user_id' => $this->user->id,
            'name' => 'Banco XP S.A.',
            'kind' => 'bank',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->accountId = DB::table('accounts')->insertGetId([
            'user_id' => $this->user->id,
            'institution_id' => $institutionId,
            'name' => 'Conta XP',
            'type' => 'checking',
            'opening_balance_cents' => 0,
            'opening_balance_date' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cardAccountId = DB::table('accounts')->insertGetId([
            'user_id' => $this->user->id,
            'institution_id' => $institutionId,
            'name' => 'Visa Black XP',
            'type' => 'credit_card',
            'opening_balance_cents' => 0,
            'opening_balance_date' => '2026-01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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

    public function test_review_opens_after_importing_the_real_statement_and_invoice(): void
    {
        $this->importConta();
        $this->importFatura();

        $pendingBank = DB::table('transactions')->where('needs_review', true)->count();
        $pendingCard = DB::table('card_purchases')->where('needs_review', true)->count();

        // Se nada ficou pendente, o teste não estaria exercitando a fila de fato.
        $this->assertGreaterThan(0, $pendingBank + $pendingCard, 'esperava itens pendentes de revisão após a importação');

        $this->actingAs($this->user)->get('/review')->assertOk();
        $this->actingAs($this->user)->get('/review?type=bank')->assertOk();
        $this->actingAs($this->user)->get('/review?type=card')->assertOk();
        $this->actingAs($this->user)->get('/review?accounts[]='.$this->accountId.'&cards[]='.$this->cardId)->assertOk();

        // Percorre todas as páginas da fila — um único lançamento ruim em qualquer
        // página derrubaria a tela inteira em produção.
        $perPage = 20;
        $pages = (int) ceil(($pendingBank + $pendingCard) / $perPage);
        for ($page = 1; $page <= max(1, $pages); $page++) {
            $this->actingAs($this->user)->get('/review?page='.$page)->assertOk();
        }
    }

    public function test_bancos_and_cartoes_open_after_importing_real_data(): void
    {
        $this->importConta();
        $this->importFatura();

        $this->actingAs($this->user)->get('/bancos')->assertOk();
        $this->actingAs($this->user)->get('/bancos?account='.$this->accountId)->assertOk();
        $this->actingAs($this->user)->get('/cartoes')->assertOk();

        foreach (['compras', 'projecao', 'parcelamentos'] as $tab) {
            $this->actingAs($this->user)->get('/cartoes?card='.$this->cardId.'&tab='.$tab)->assertOk();
        }

        $this->actingAs($this->user)->get('/fechamento')->assertOk();
        $this->actingAs($this->user)->get('/dashboard')->assertOk();
    }

    public function test_confirming_every_pending_item_from_the_queue_works(): void
    {
        $this->importConta();
        $this->importFatura();

        $categoryId = (int) DB::table('categories')
            ->where('user_id', $this->user->id)
            ->where('name', 'Compras')
            ->value('id');

        $bank = DB::table('transactions')->where('needs_review', true)->pluck('id');
        $card = DB::table('card_purchases')->where('needs_review', true)->pluck('id');

        foreach ($bank as $id) {
            $this->actingAs($this->user)
                ->post('/review/bank/'.$id, ['category_id' => $categoryId])
                ->assertRedirect();
        }

        foreach ($card as $id) {
            $this->actingAs($this->user)
                ->post('/review/card/'.$id, ['category_id' => $categoryId])
                ->assertRedirect();
        }

        $this->assertSame(0, DB::table('transactions')->where('needs_review', true)->count());
        $this->assertSame(0, DB::table('card_purchases')->where('needs_review', true)->count());

        $this->actingAs($this->user)->get('/review')->assertOk();
    }

    private function importConta(): void
    {
        $path = base_path('tests/Fixtures/csv/xp_conta_2026-06-05_a_2026-08-04.csv');

        $this->actingAs($this->user)->post('/import/conta/preview', [
            'account_id' => $this->accountId,
            'file' => new UploadedFile($path, 'extrato.csv', 'text/csv', null, true),
        ])->assertOk();

        $this->actingAs($this->user)->post('/import/conta/commit')->assertRedirect();
    }

    private function importFatura(): void
    {
        $path = base_path('tests/Fixtures/csv/xp_visa_black_fatura_2026-09_aberta.csv');

        $this->actingAs($this->user)->post('/import/fatura/preview', [
            'credit_card_id' => $this->cardId,
            'reference_month' => '2026-09',
            'file' => new UploadedFile($path, 'Fatura20260901.csv', 'text/csv', null, true),
        ])->assertOk();

        $this->actingAs($this->user)->post('/import/fatura/commit')->assertRedirect();
    }
}
