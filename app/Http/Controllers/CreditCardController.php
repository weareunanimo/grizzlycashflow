<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\CategoryAssignment;
use App\Support\CategoryTree;
use Grizzly\Domain\Classification\MerchantDisplayName;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class CreditCardController extends Controller
{
    public function index(Request $request): View
    {
        $userId = Auth::id();

        $cards = $this->creditCards($userId)->concat($this->benefitCards($userId))->values();

        // A chave carrega o tipo ('c12' = cartão de crédito, 'v34' = benefícios).
        // Um `?card=12` numérico continua valendo como cartão de crédito, para não
        // quebrar os links antigos (redirect da importação de fatura, dashboard).
        $requestedKey = (string) $request->query('card', '');
        if ($requestedKey !== '' && ctype_digit($requestedKey)) {
            $requestedKey = 'c'.$requestedKey;
        }

        $selected = $cards->firstWhere('key', $requestedKey) ?? $cards->first();

        $tab = in_array($request->query('tab'), ['projecao', 'parcelamentos'], true) ? $request->query('tab') : 'compras';

        $purchases = null;
        $projection = null;
        $installmentPlans = null;
        $rows = null;

        if ($selected && $selected->card_type === 'voucher') {
            $rows = DB::table('transactions')
                ->leftJoin('categories', 'categories.id', '=', 'transactions.category_id')
                ->where('transactions.account_id', $selected->account_id)
                ->where('transactions.user_id', $userId)
                ->whereNull('transactions.deleted_at')
                ->orderByDesc('transactions.occurred_on')
                ->orderByDesc('transactions.id')
                ->select('transactions.*', 'categories.name as category_name')
                ->paginate(50, pageName: 'page')
                ->withQueryString();
        }

        if ($selected && $selected->card_type === 'credit') {
            $id = (int) $selected->id;

            $currentInstallment = DB::table('card_installments')
                ->select('purchase_id', DB::raw('MAX(number) as current_number'))
                ->where('credit_card_id', $id)
                ->where('status', 'billed')
                ->groupBy('purchase_id');

            $purchases = DB::table('card_purchases')
                ->leftJoinSub($currentInstallment, 'ci', fn ($j) => $j->on('ci.purchase_id', '=', 'card_purchases.id'))
                ->leftJoin('categories', 'categories.id', '=', 'card_purchases.category_id')
                ->where('card_purchases.credit_card_id', $id)
                ->where('card_purchases.user_id', $userId)
                ->whereNull('card_purchases.deleted_at')
                ->orderByDesc('card_purchases.purchase_date')
                ->select('card_purchases.*', 'categories.name as category_name', 'ci.current_number')
                ->paginate(50, pageName: 'page')
                ->withQueryString()
                ->through(function ($purchase) {
                    $purchase->display_name = MerchantDisplayName::forRawDescription($purchase->description);

                    return $purchase;
                });

            $currentMonth = now()->format('Y-m-01');

            $projection = DB::table('card_installments')
                ->where('credit_card_id', $id)
                ->where('user_id', $userId)
                ->whereIn('status', ['projected', 'billed'])
                ->where('reference_month', '>=', $currentMonth)
                ->groupBy('reference_month')
                ->orderBy('reference_month')
                ->select('reference_month', DB::raw('SUM(amount_cents) as total_cents'), DB::raw('COUNT(*) as items'))
                ->limit(12)
                ->get();

            $installmentStats = DB::table('card_installments')
                ->select(
                    'purchase_id',
                    DB::raw("SUM(CASE WHEN status = 'billed' THEN 1 ELSE 0 END) as paid_count"),
                    DB::raw("SUM(CASE WHEN status = 'projected' THEN 1 ELSE 0 END) as remaining_count"),
                )
                ->where('credit_card_id', $id)
                ->groupBy('purchase_id');

            $installmentPlans = DB::table('card_purchases')
                ->leftJoinSub($installmentStats, 'stats', fn ($j) => $j->on('stats.purchase_id', '=', 'card_purchases.id'))
                ->where('card_purchases.credit_card_id', $id)
                ->where('card_purchases.user_id', $userId)
                ->where('card_purchases.installments_total', '>', 1)
                ->whereNull('card_purchases.deleted_at')
                ->orderByDesc('card_purchases.purchase_date')
                ->select('card_purchases.*', 'stats.paid_count', 'stats.remaining_count')
                ->get()
                ->map(function ($purchase) {
                    $purchase->display_name = MerchantDisplayName::forRawDescription($purchase->description);

                    return $purchase;
                });
        }

        return view('cards.index', [
            'cards' => $cards,
            'selected' => $selected,
            'tab' => $tab,
            'purchases' => $purchases,
            'projection' => $projection,
            'installmentPlans' => $installmentPlans,
            'rows' => $rows,
            'categories' => CategoryTree::options($userId),
        ]);
    }

    /** @return Collection<int,object> */
    private function creditCards(int $userId): Collection
    {
        return DB::table('credit_cards')
            ->join('accounts', 'accounts.id', '=', 'credit_cards.account_id')
            ->where('credit_cards.user_id', $userId)
            ->whereNull('accounts.archived_at')
            ->select('credit_cards.*', 'accounts.name as account_name')
            ->orderBy('credit_cards.id')
            ->get()
            ->map(function ($card) use ($userId) {
                $card->card_type = 'credit';
                $card->key = 'c'.$card->id;
                $card->open_total_cents = (int) DB::table('card_installments')
                    ->where('credit_card_id', $card->id)
                    ->where('user_id', $userId)
                    ->whereIn('status', ['projected', 'billed'])
                    ->sum('amount_cents');

                return $card;
            });
    }

    /**
     * Cartão de benefícios (accounts.type = 'voucher'): não tem fatura nem parcelas,
     * então não existe linha em `credit_cards` — o que importa é o saldo disponível.
     *
     * @return Collection<int,object>
     */
    private function benefitCards(int $userId): Collection
    {
        return DB::table('accounts')
            ->where('user_id', $userId)
            ->where('type', 'voucher')
            ->whereNull('archived_at')
            ->orderBy('id')
            ->get()
            ->map(function ($account) use ($userId) {
                $sums = DB::table('transactions')
                    ->where('account_id', $account->id)
                    ->where('user_id', $userId)
                    ->whereNull('deleted_at')
                    ->selectRaw("SUM(CASE WHEN direction = 'in' THEN amount_cents ELSE 0 END) as total_in")
                    ->selectRaw("SUM(CASE WHEN direction = 'out' THEN amount_cents ELSE 0 END) as total_out")
                    ->first();

                return (object) [
                    'id' => $account->id,
                    'account_id' => $account->id,
                    'account_name' => $account->name,
                    'card_type' => 'voucher',
                    'key' => 'v'.$account->id,
                    'balance_cents' => (int) ($sums->total_in ?? 0) - (int) ($sums->total_out ?? 0),
                ];
            });
    }

    /**
     * O nome visível de um cartão (de crédito ou de benefícios) mora em
     * `accounts.name` nos dois casos — só o caminho até a conta muda.
     */
    public function update(Request $request, string $key): RedirectResponse
    {
        $userId = Auth::id();
        $validated = $request->validate(['name' => ['required', 'string', 'max:120']]);

        $accountId = $this->accountIdForKey($userId, $key);

        DB::table('accounts')
            ->where('id', $accountId)
            ->where('user_id', $userId)
            ->update(['name' => $validated['name'], 'updated_at' => now()]);

        return redirect()
            ->route('cards.index', ['card' => $key])
            ->with('status', "Cartão renomeado para \"{$validated['name']}\".");
    }

    private function accountIdForKey(int $userId, string $key): int
    {
        if (str_starts_with($key, 'v')) {
            $accountId = DB::table('accounts')
                ->where('id', (int) substr($key, 1))
                ->where('user_id', $userId)
                ->where('type', 'voucher')
                ->value('id');
        } else {
            $accountId = DB::table('credit_cards')
                ->where('id', (int) ltrim($key, 'c'))
                ->where('user_id', $userId)
                ->value('account_id');
        }

        if ($accountId === null) {
            abort(404);
        }

        return (int) $accountId;
    }

    /** Troca a categoria de uma compra da fatura, direto na lista. */
    public function updatePurchaseCategory(Request $request, int $id): RedirectResponse
    {
        $userId = (int) Auth::id();
        $validated = $request->validate(['category_id' => ['required', 'integer']]);
        $categoryId = (int) $validated['category_id'];

        if (! CategoryAssignment::belongsToUser($userId, $categoryId)) {
            abort(404);
        }

        $exists = DB::table('card_purchases')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->exists();

        if (! $exists) {
            abort(404);
        }

        CategoryAssignment::apply('card', $userId, [$id], $categoryId);

        $name = DB::table('categories')->where('id', $categoryId)->value('name');

        return back()->with('status', "Categoria alterada para \"{$name}\".");
    }

    public function destroy(string $key): RedirectResponse
    {
        $userId = Auth::id();

        if (str_starts_with($key, 'v')) {
            return $this->destroyBenefitCard($userId, (int) substr($key, 1));
        }

        $id = (int) ltrim($key, 'c');

        $card = DB::table('credit_cards')
            ->join('accounts', 'accounts.id', '=', 'credit_cards.account_id')
            ->where('credit_cards.id', $id)
            ->where('credit_cards.user_id', $userId)
            ->select('credit_cards.*', 'accounts.name as account_name')
            ->first();

        if (! $card) {
            abort(404);
        }

        DB::transaction(function () use ($id, $userId, $card): void {
            DB::table('transactions')->where('credit_card_id', $id)->where('user_id', $userId)->update(['credit_card_id' => null]);
            DB::table('card_installments')->where('credit_card_id', $id)->where('user_id', $userId)->delete();
            DB::table('card_purchases')->where('credit_card_id', $id)->where('user_id', $userId)->delete();
            DB::table('credit_cards')->where('id', $id)->where('user_id', $userId)->delete();
            $this->deleteAccountAndTransactions($userId, (int) $card->account_id);
        });

        return redirect()->route('cards.index')->with('status', "\"{$card->account_name}\" e todos os lançamentos dele foram apagados.");
    }

    private function destroyBenefitCard(int $userId, int $accountId): RedirectResponse
    {
        $account = DB::table('accounts')
            ->where('id', $accountId)
            ->where('user_id', $userId)
            ->where('type', 'voucher')
            ->first();

        if (! $account) {
            abort(404);
        }

        DB::transaction(fn () => $this->deleteAccountAndTransactions($userId, $accountId));

        return redirect()->route('cards.index')->with('status', "\"{$account->name}\" e todos os lançamentos dele foram apagados.");
    }

    private function deleteAccountAndTransactions(int $userId, int $accountId): void
    {
        DB::table('transaction_tags')->whereIn('transaction_id', function ($q) use ($accountId, $userId): void {
            $q->select('id')->from('transactions')->where('account_id', $accountId)->where('user_id', $userId);
        })->delete();

        DB::table('credit_cards')->where('payment_account_id', $accountId)->where('user_id', $userId)->update(['payment_account_id' => null]);
        DB::table('transactions')->where('account_id', $accountId)->where('user_id', $userId)->delete();
        DB::table('accounts')->where('id', $accountId)->where('user_id', $userId)->delete();
    }
}
