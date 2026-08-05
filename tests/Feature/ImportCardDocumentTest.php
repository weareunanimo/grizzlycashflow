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
 * "Fatura ou extrato do cartão" é uma escolha só para o usuário: o cartão de
 * crédito manda fatura, o de benefícios manda extrato. O controller decide qual
 * parser roda pelo tipo do cartão, então a rota é a mesma para os dois.
 */
final class ImportCardDocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $bankAccountId;

    private int $voucherAccountId;

    private int $cardId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        (new CategorySeeder)->run($this->user->id);
        (new MerchantRuleSeeder)->run($this->user->id);

        $institutionId = DB::table('institutions')->insertGetId([
            'user_id' => $this->user->id,
            'name' => 'Banco XP',
            'kind' => 'bank',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->bankAccountId = $this->account($institutionId, 'Conta XP', 'checking');
        $this->voucherAccountId = $this->account($institutionId, 'Caju', 'voucher');

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

    public function test_the_card_section_lists_credit_and_benefit_cards(): void
    {
        $this->actingAs($this->user)->get('/importar')
            ->assertOk()
            ->assertSee('Fatura ou extrato do cartão')
            ->assertSee('Visa Black XP')
            ->assertSee('Caju (benefícios)')
            ->assertSee('value="c'.$this->cardId.'"', escape: false)
            ->assertSee('value="v'.$this->voucherAccountId.'"', escape: false);
    }

    /** A conta bancária não deve listar cartões, e vice-versa. */
    public function test_the_account_section_lists_only_bank_accounts(): void
    {
        $response = $this->actingAs($this->user)->get('/importar')->assertOk();

        $html = $response->getContent();
        $accountSelect = substr($html, (int) strpos($html, 'id="account_id"'), 600);

        $this->assertStringContainsString('Conta XP', $accountSelect);
        $this->assertStringNotContainsString('Caju', $accountSelect);
        $this->assertStringNotContainsString('Visa Black XP', $accountSelect);
    }

    public function test_it_previews_a_credit_card_invoice(): void
    {
        $this->actingAs($this->user)->post('/importar/cartao/preview', [
            'card_key' => 'c'.$this->cardId,
            'reference_month' => '2026-09',
            'file' => new UploadedFile(base_path('tests/Fixtures/csv/xp_visa_black_fatura_2026-09_aberta.csv'), 'Fatura20260901.csv', 'text/csv', null, true),
        ])->assertOk();

        $this->actingAs($this->user)->post('/import/fatura/commit')->assertRedirect();

        $this->assertGreaterThan(0, DB::table('card_purchases')->where('credit_card_id', $this->cardId)->count());
    }

    /** Benefícios entra pelo mesmo formulário, mas roda o parser de extrato. */
    public function test_it_previews_a_benefit_card_statement(): void
    {
        $this->actingAs($this->user)->post('/importar/cartao/preview', [
            'card_key' => 'v'.$this->voucherAccountId,
            'file' => new UploadedFile(base_path('tests/Fixtures/csv/xp_conta_2026-06-05_a_2026-08-04.csv'), 'extrato.csv', 'text/csv', null, true),
        ])->assertOk();

        $this->actingAs($this->user)->post('/import/conta/commit')
            ->assertRedirect(route('cards.index', ['card' => 'v'.$this->voucherAccountId]));

        $this->assertGreaterThan(0, DB::table('transactions')->where('account_id', $this->voucherAccountId)->count());
    }

    public function test_a_benefit_card_statement_does_not_require_a_reference_month(): void
    {
        $this->actingAs($this->user)->post('/importar/cartao/preview', [
            'card_key' => 'v'.$this->voucherAccountId,
            'file' => new UploadedFile(base_path('tests/Fixtures/csv/xp_conta_2026-06-05_a_2026-08-04.csv'), 'extrato.csv', 'text/csv', null, true),
        ])->assertOk()->assertSessionHasNoErrors();
    }

    public function test_a_credit_card_invoice_still_requires_a_reference_month(): void
    {
        $this->actingAs($this->user)->post('/importar/cartao/preview', [
            'card_key' => 'c'.$this->cardId,
            'file' => new UploadedFile(base_path('tests/Fixtures/csv/xp_visa_black_fatura_2026-09_aberta.csv'), 'Fatura20260901.csv', 'text/csv', null, true),
        ])->assertSessionHasErrors('reference_month');
    }

    public function test_it_refuses_a_card_from_another_user(): void
    {
        $other = User::factory()->create();
        $foreignInstitution = DB::table('institutions')->insertGetId([
            'user_id' => $other->id, 'name' => 'Alheio', 'kind' => 'bank',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreignAccount = DB::table('accounts')->insertGetId([
            'user_id' => $other->id, 'institution_id' => $foreignInstitution,
            'name' => 'Cartao Alheio', 'type' => 'credit_card',
            'opening_balance_cents' => 0, 'opening_balance_date' => '2026-01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreignCard = DB::table('credit_cards')->insertGetId([
            'user_id' => $other->id, 'account_id' => $foreignAccount,
            'brand' => 'visa', 'closing_day' => 10, 'due_day' => 20,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->user)->post('/importar/cartao/preview', [
            'card_key' => 'c'.$foreignCard,
            'reference_month' => '2026-09',
            'file' => new UploadedFile(base_path('tests/Fixtures/csv/xp_visa_black_fatura_2026-09_aberta.csv'), 'f.csv', 'text/csv', null, true),
        ])->assertNotFound();
    }

    public function test_it_refuses_a_bank_account_disguised_as_a_benefit_card(): void
    {
        // 'v' aponta para conta de benefícios; uma conta corrente não passa.
        $this->actingAs($this->user)->post('/importar/cartao/preview', [
            'card_key' => 'v'.$this->bankAccountId,
            'file' => new UploadedFile(base_path('tests/Fixtures/csv/xp_conta_2026-06-05_a_2026-08-04.csv'), 'e.csv', 'text/csv', null, true),
        ])->assertNotFound();
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
}
