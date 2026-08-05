<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class AccountController extends Controller
{
    private const PER_PAGE = 50;

    public function show(int $id, Request $request): View
    {
        $userId = Auth::id();

        $account = DB::table('accounts')
            ->join('institutions', 'institutions.id', '=', 'accounts.institution_id')
            ->where('accounts.id', $id)
            ->where('accounts.user_id', $userId)
            ->select('accounts.*', 'institutions.name as institution_name')
            ->firstOrFail();

        $rows = $this->mergedRows($id, $userId);

        $page = (int) $request->query('page', 1);
        $slice = $rows->forPage($page, self::PER_PAGE)->values();

        $transactions = new LengthAwarePaginator(
            $slice,
            $rows->count(),
            self::PER_PAGE,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('accounts.show', ['account' => $account, 'rows' => $transactions]);
    }

    /**
     * Junta lançamentos normais com os "Rendimento automático" agrupados por mês
     * (ADR-0020) — os 42 lançamentos de centavos de rendimento viram uma linha
     * expansível por mês em vez de poluir o extrato inteiro.
     */
    private function mergedRows(int $accountId, int $userId): \Illuminate\Support\Collection
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
                'sort_key' => $tx->occurred_on . '-' . str_pad((string) $tx->id, 12, '0', STR_PAD_LEFT),
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
                    'sort_key' => $lastDate . '-999999999999',
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
