<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Editar a categoria de um lançamento direto no extrato ou na fatura, sem
 * passar pela fila de revisão. Diferente da revisão, aqui a mudança vale só
 * para aquele lançamento: quem corrige uma linha específica não quer mexer em
 * todas as outras do mesmo estabelecimento.
 */
final class InlineCategoryEditTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $accountId;

    private int $voucherAccountId;

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
        $this->voucherAccountId = $this->account($institutionId, 'Caju', 'voucher');

        $cardAccountId = $this->account($institutionId, 'Visa Black', 'credit_card');
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

    public function test_the_statement_offers_a_category_picker_per_row(): void
    {
        $id = $this->transaction($this->accountId, 'PADARIA CENTRAL');

        $this->actingAs($this->user)->get('/bancos?account='.$this->accountId)
            ->assertOk()
            ->assertSee('Editar categoria')
            ->assertSee(route('transactions.category', $id), escape: false);
    }

    public function test_the_invoice_offers_a_category_picker_per_row(): void
    {
        $id = $this->purchase('MP*MERCADOLIVRE');

        $this->actingAs($this->user)->get('/cartoes?card=c'.$this->cardId)
            ->assertOk()
            ->assertSee('Editar categoria')
            ->assertSee(route('purchases.category', $id), escape: false);
    }

    public function test_the_benefit_card_offers_a_category_picker_per_row(): void
    {
        $id = $this->transaction($this->voucherAccountId, 'RESTAURANTE DO ZE');

        $this->actingAs($this->user)->get('/cartoes?card=v'.$this->voucherAccountId)
            ->assertOk()
            ->assertSee(route('transactions.category', $id), escape: false);
    }

    public function test_it_changes_the_category_of_a_transaction(): void
    {
        $id = $this->transaction($this->accountId, 'PADARIA CENTRAL');
        $padaria = $this->categoryId('Padaria');

        $this->actingAs($this->user)
            ->from('/bancos?account='.$this->accountId)
            ->patch(route('transactions.category', $id), ['category_id' => $padaria])
            ->assertRedirect('/bancos?account='.$this->accountId);

        $row = DB::table('transactions')->where('id', $id)->first();
        $this->assertSame($padaria, (int) $row->category_id);
        $this->assertFalse((bool) $row->needs_review);
        $this->assertSame('user', $row->category_source);
    }

    public function test_it_changes_the_category_of_a_purchase(): void
    {
        $id = $this->purchase('MP*MERCADOLIVRE');
        $compras = $this->categoryId('Compras');

        $this->actingAs($this->user)
            ->patch(route('purchases.category', $id), ['category_id' => $compras])
            ->assertRedirect();

        $row = DB::table('card_purchases')->where('id', $id)->first();
        $this->assertSame($compras, (int) $row->category_id);
        $this->assertFalse((bool) $row->needs_review);
    }

    /** Corrigir uma categoria já definida também precisa funcionar. */
    public function test_it_replaces_an_existing_category(): void
    {
        $id = $this->transaction($this->accountId, 'PADARIA CENTRAL', $this->categoryId('Mercado'));

        $this->actingAs($this->user)
            ->patch(route('transactions.category', $id), ['category_id' => $this->categoryId('Padaria')])
            ->assertRedirect();

        $this->assertSame(
            $this->categoryId('Padaria'),
            (int) DB::table('transactions')->where('id', $id)->value('category_id')
        );
    }

    /** Aqui é edição pontual: o resto do grupo não pode ser arrastado junto. */
    public function test_it_does_not_touch_other_entries_of_the_same_merchant(): void
    {
        $alvo = $this->purchase('MP*MERCADOLIVRE');
        $outro = $this->purchase('MP*MERCADOLIVRE 4471');

        $this->actingAs($this->user)
            ->patch(route('purchases.category', $alvo), ['category_id' => $this->categoryId('Compras')])
            ->assertRedirect();

        $this->assertNull(DB::table('card_purchases')->where('id', $outro)->value('category_id'));
    }

    public function test_it_requires_a_category(): void
    {
        $id = $this->transaction($this->accountId, 'PADARIA CENTRAL');

        $this->actingAs($this->user)
            ->patch(route('transactions.category', $id), [])
            ->assertSessionHasErrors('category_id');
    }

    public function test_it_refuses_a_category_from_another_user(): void
    {
        $other = User::factory()->create();
        (new CategorySeeder)->run($other->id);
        $foreign = (int) DB::table('categories')->where('user_id', $other->id)->value('id');

        $id = $this->transaction($this->accountId, 'PADARIA CENTRAL');

        $this->actingAs($this->user)
            ->patch(route('transactions.category', $id), ['category_id' => $foreign])
            ->assertNotFound();

        $this->assertNull(DB::table('transactions')->where('id', $id)->value('category_id'));
    }

    public function test_it_cannot_edit_another_users_transaction(): void
    {
        $other = User::factory()->create();
        $id = $this->transaction($this->accountId, 'PADARIA CENTRAL');

        $this->actingAs($other)
            ->patch(route('transactions.category', $id), ['category_id' => $this->categoryId('Padaria')])
            ->assertNotFound();

        $this->assertNull(DB::table('transactions')->where('id', $id)->value('category_id'));
    }

    public function test_it_cannot_edit_another_users_purchase(): void
    {
        $other = User::factory()->create();
        $id = $this->purchase('MP*MERCADOLIVRE');

        $this->actingAs($other)
            ->patch(route('purchases.category', $id), ['category_id' => $this->categoryId('Compras')])
            ->assertNotFound();
    }

    /** Categorizar pela lista tira o item da fila de revisão. */
    public function test_editing_inline_removes_the_item_from_the_review_queue(): void
    {
        $id = $this->transaction($this->accountId, 'PADARIA CENTRAL');

        $this->actingAs($this->user)->get('/review')->assertOk()->assertSee('PADARIA CENTRAL');

        $this->actingAs($this->user)
            ->patch(route('transactions.category', $id), ['category_id' => $this->categoryId('Padaria')]);

        $this->actingAs($this->user)->get('/review')->assertOk()->assertDontSee('PADARIA CENTRAL');
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

    private function transaction(int $accountId, string $description, ?int $categoryId = null): int
    {
        static $n = 0;
        $n++;

        return DB::table('transactions')->insertGetId([
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
            'category_id' => $categoryId,
            'needs_review' => $categoryId === null,
            'fingerprint' => hash('sha256', 'f'.$n.$description),
            'fingerprint_loose' => hash('sha256', 'l'.$n.$description),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function purchase(string $description): int
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
