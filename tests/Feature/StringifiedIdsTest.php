<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * O hosting compartilhado de produção devolve as colunas numéricas como STRING
 * (PDO::ATTR_STRINGIFY_FETCHES ligado), diferente do ambiente local, que devolve
 * int. Isso derrubou a tela Revisar com "Argument #4 ($excludeId) must be of type
 * int, string given" — um erro que só aparece nesse modo.
 *
 * Estes testes ligam o mesmo comportamento no PDO para que qualquer código que
 * dependa do tipo nativo do banco quebre aqui, e não na mão do usuário.
 */
final class StringifiedIdsTest extends TestCase
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

        $cardAccountId = DB::table('accounts')->insertGetId([
            'user_id' => $this->user->id,
            'institution_id' => $institutionId,
            'name' => 'Visa Black',
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

        $this->makeTransaction('PADARIA DO BAIRRO');
        $this->makeTransaction('padaria do bairro');
        $this->makePurchase('MP*MERCADOLIVRE');
        $this->makePurchase('Rendimento automático');

        // Só depois de inserir: a partir daqui todo SELECT devolve string,
        // exatamente como no servidor do usuário.
        DB::connection()->getPdo()->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
    }

    public function test_review_opens_when_the_database_returns_ids_as_strings(): void
    {
        $this->actingAs($this->user)->get('/review')->assertOk();
    }

    public function test_review_filters_open_when_ids_come_back_as_strings(): void
    {
        foreach (['?type=bank', '?type=card', '?accounts[]='.$this->accountId, '?cards[]='.$this->cardId] as $query) {
            $this->actingAs($this->user)->get('/review'.$query)->assertOk();
        }
    }

    public function test_confirming_an_item_works_when_ids_come_back_as_strings(): void
    {
        $id = DB::table('transactions')->where('needs_review', true)->value('id');
        $categoryId = DB::table('categories')->where('user_id', $this->user->id)->where('name', 'Padaria')->value('id');

        $this->actingAs($this->user)
            ->post('/review/bank/'.$id, ['category_id' => $categoryId])
            ->assertRedirect();
    }

    public function test_the_other_pages_open_when_ids_come_back_as_strings(): void
    {
        foreach (['/dashboard', '/bancos', '/cartoes', '/cartoes?tab=projecao', '/cartoes?tab=parcelamentos', '/fechamento', '/importar', '/accounts/new'] as $path) {
            $this->actingAs($this->user)->get($path)->assertOk();
        }
    }

    /**
     * A importação também lê o banco (regras ativas, categoria por nome, dedup) —
     * `categoryIdByName(): ?int` estourava TypeError nesse modo.
     */
    public function test_importing_real_csvs_works_when_ids_come_back_as_strings(): void
    {
        $this->actingAs($this->user)->post('/import/conta/preview', [
            'account_id' => $this->accountId,
            'file' => new UploadedFile(base_path('tests/Fixtures/csv/xp_conta_2026-06-05_a_2026-08-04.csv'), 'extrato.csv', 'text/csv', null, true),
        ])->assertOk();

        $this->actingAs($this->user)->post('/import/conta/commit')->assertRedirect();

        $this->actingAs($this->user)->post('/import/fatura/preview', [
            'credit_card_id' => $this->cardId,
            'reference_month' => '2026-09',
            'file' => new UploadedFile(base_path('tests/Fixtures/csv/xp_visa_black_fatura_2026-09_aberta.csv'), 'Fatura20260901.csv', 'text/csv', null, true),
        ])->assertOk();

        $this->actingAs($this->user)->post('/import/fatura/commit')->assertRedirect();

        $this->actingAs($this->user)->get('/review')->assertOk();
        $this->actingAs($this->user)->get('/bancos')->assertOk();
        $this->actingAs($this->user)->get('/cartoes')->assertOk();
    }

    public function test_deleting_works_when_ids_come_back_as_strings(): void
    {
        $this->actingAs($this->user)->delete('/cartoes/c'.$this->cardId)->assertRedirect();
        $this->actingAs($this->user)->delete('/bancos/'.$this->accountId)->assertRedirect();
    }

    private function makeTransaction(string $description): void
    {
        static $n = 0;
        $n++;
        $date = sprintf('2026-08-%02d', ($n % 28) + 1);

        DB::table('transactions')->insert([
            'user_id' => $this->user->id,
            'account_id' => $this->accountId,
            'direction' => 'out',
            'amount_cents' => 1500,
            'currency' => 'BRL',
            'occurred_on' => $date,
            'cash_effect_on' => $date,
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

    private function makePurchase(string $description): void
    {
        static $n = 0;
        $n++;

        DB::table('card_purchases')->insert([
            'user_id' => $this->user->id,
            'credit_card_id' => $this->cardId,
            'description' => $description,
            'raw_description' => $description,
            'purchase_date' => '2026-08-01',
            'installments_total' => 3,
            'installment_amount_cents' => 2500,
            'total_amount_cents' => 7500,
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
