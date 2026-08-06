<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\CategoryAssignment;
use App\Support\CategoryTree;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class BankController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): View
    {
        $userId = Auth::id();

        $accounts = DB::table('accounts')
            ->join('institutions', 'institutions.id', '=', 'accounts.institution_id')
            ->where('accounts.user_id', $userId)
            // cartão de crédito e cartão de benefícios vivem na tela Cartões.
            ->whereNotIn('accounts.type', ['credit_card', 'voucher'])
            ->whereNull('accounts.archived_at')
            ->select('accounts.*', 'institutions.name as institution_name')
            ->orderBy('accounts.id')
            ->get()
            ->map(function ($account) use ($userId) {
                $sums = DB::table('transactions')
                    ->where('account_id', $account->id)
                    ->where('user_id', $userId)
                    ->whereNull('deleted_at')
                    ->selectRaw("SUM(CASE WHEN direction = 'in' THEN amount_cents ELSE 0 END) as total_in")
                    ->selectRaw("SUM(CASE WHEN direction = 'out' THEN amount_cents ELSE 0 END) as total_out")
                    ->first();

                $account->total_in_cents = (int) ($sums->total_in ?? 0);
                $account->total_out_cents = (int) ($sums->total_out ?? 0);

                return $account;
            });

        $selectedId = (int) $request->query('account', $accounts->first()->id ?? 0);
        $selected = $accounts->firstWhere('id', $selectedId) ?? $accounts->first();

        $rows = null;

        if ($selected) {
            $merged = $this->mergedRows((int) $selected->id, $userId);
            $page = (int) $request->query('page', 1);
            $slice = $merged->forPage($page, self::PER_PAGE)->values();

            $rows = new LengthAwarePaginator(
                $slice,
                $merged->count(),
                self::PER_PAGE,
                $page,
                ['path' => $request->url(), 'query' => $request->query()],
            );
        }

        return view('banks.index', [
            'accounts' => $accounts,
            'selected' => $selected,
            'rows' => $rows,
            'categories' => CategoryTree::options($userId),
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $userId = Auth::id();
        $validated = $request->validate(['name' => ['required', 'string', 'max:120']]);

        $updated = DB::table('accounts')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->whereNotIn('type', ['credit_card', 'voucher'])
            ->update(['name' => $validated['name'], 'updated_at' => now()]);

        if ($updated === 0) {
            abort(404);
        }

        return redirect()
            ->route('banks.index', ['account' => $id])
            ->with('status', "Conta renomeada para \"{$validated['name']}\".");
    }

    /** Troca a categoria de um lançamento sem passar pela fila de revisão. */
    public function updateTransactionCategory(Request $request, int $id): RedirectResponse
    {
        $userId = (int) Auth::id();
        $validated = $request->validate(['category_id' => ['required', 'integer']]);
        $categoryId = (int) $validated['category_id'];

        if (! CategoryAssignment::belongsToUser($userId, $categoryId)) {
            abort(404);
        }

        $exists = DB::table('transactions')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->exists();

        if (! $exists) {
            abort(404);
        }

        CategoryAssignment::apply('bank', $userId, [$id], $categoryId);

        $name = DB::table('categories')->where('id', $categoryId)->value('name');

        // back() devolve à mesma aba, filtro e página em que o usuário estava.
        return back()->with('status', "Categoria alterada para \"{$name}\".");
    }

    public function destroy(int $id): RedirectResponse
    {
        $userId = Auth::id();

        $account = DB::table('accounts')->where('id', $id)->where('user_id', $userId)->first();

        if (! $account) {
            abort(404);
        }

        DB::transaction(function () use ($id, $userId): void {
            DB::table('transaction_tags')->whereIn('transaction_id', function ($q) use ($id, $userId): void {
                $q->select('id')->from('transactions')->where('account_id', $id)->where('user_id', $userId);
            })->delete();

            DB::table('credit_cards')->where('payment_account_id', $id)->where('user_id', $userId)->update(['payment_account_id' => null]);
            DB::table('transactions')->where('account_id', $id)->where('user_id', $userId)->delete();
            DB::table('accounts')->where('id', $id)->where('user_id', $userId)->delete();
        });

        return redirect()->route('banks.index')->with('status', "\"{$account->name}\" e todos os lançamentos dela foram apagados.");
    }

    /**
     * Junta lançamentos normais com os "Rendimento automático" agrupados por mês
     * (ADR-0020) — os 42 lançamentos de centavos de rendimento viram uma linha
     * expansível por mês em vez de poluir o extrato inteiro.
     */
    private function mergedRows(int $accountId, int $userId): Collection
    {
        $normal = DB::table('transactions')
            ->leftJoin('categories', 'categories.id', '=', 'transactions.category_id')
            ->where('transactions.account_id', $accountId)
            ->where('transactions.user_id', $userId)
            ->whereNull('transactions.deleted_at')
            ->where('transactions.description', 'not like', 'Rendimento automático%')
            ->select('transactions.*', 'categories.name as category_name')
            ->get()
            ->map(fn ($tx) => (object) [
                'type' => 'tx',
                'sort_key' => $tx->occurred_on.'-'.str_pad((string) $tx->id, 12, '0', STR_PAD_LEFT),
                'data' => $tx,
            ]);

        $rendimentoRows = DB::table('transactions')
            ->where('account_id', $accountId)
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->where('description', 'like', 'Rendimento automático%')
            ->orderByDesc('occurred_on')
            ->get();

        $rendimentoGroups = $rendimentoRows
            ->groupBy(fn ($tx) => substr($tx->occurred_on, 0, 7))
            ->map(function ($items, $month) {
                $lastDate = $items->max('occurred_on');

                return (object) [
                    'type' => 'rendimento_group',
                    'sort_key' => $lastDate.'-999999999999',
                    'data' => (object) [
                        'month' => $month,
                        'count' => $items->count(),
                        'total_cents' => $items->sum('amount_cents'),
                        'items' => $items->sortByDesc('occurred_on')->values(),
                    ],
                ];
            })
            ->values();

        return $normal->concat($rendimentoGroups)->sortByDesc('sort_key')->values();
    }
}
