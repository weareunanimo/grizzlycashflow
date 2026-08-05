<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Fechamento mensal por categoria, somando gastos da conta (transactions,
 * saída, fora de transferência) com as parcelas do cartão (card_installments,
 * já faturadas ou ainda projetadas) — a visão "quanto eu gastei em cada
 * categoria, mês a mês" que une os dois canais.
 */
final class ClosingController extends Controller
{
    public function index(): View
    {
        $userId = Auth::id();
        $currentMonth = now()->format('Y-m');

        $bank = DB::table('transactions')
            ->leftJoin('categories', 'categories.id', '=', 'transactions.category_id')
            ->where('transactions.user_id', $userId)
            ->where('transactions.direction', 'out')
            ->where('transactions.excluded_from_analytics', false)
            ->whereNull('transactions.deleted_at')
            ->where('transactions.occurred_on', '<=', $currentMonth . '-31')
            ->selectRaw('substr(transactions.occurred_on, 1, 7) as month')
            ->addSelect('transactions.category_id')
            ->addSelect(DB::raw("COALESCE(categories.name, 'Sem categoria') as category_name"))
            ->selectRaw('SUM(transactions.amount_cents) as total_cents')
            ->groupBy('month', 'transactions.category_id', 'categories.name')
            ->get();

        // Só até o mês atual — meses futuros são projeção e já vivem em
        // "Projeção de faturas" dentro do cartão, não no fechamento (que é o
        // que já fechou/está fechando).
        $card = DB::table('card_installments')
            ->join('card_purchases', 'card_purchases.id', '=', 'card_installments.purchase_id')
            ->leftJoin('categories', 'categories.id', '=', 'card_purchases.category_id')
            ->where('card_installments.user_id', $userId)
            ->where('card_installments.reference_month', '<=', $currentMonth . '-31')
            ->selectRaw('substr(card_installments.reference_month, 1, 7) as month')
            ->addSelect('card_purchases.category_id')
            ->addSelect(DB::raw("COALESCE(categories.name, 'Sem categoria') as category_name"))
            ->selectRaw('SUM(card_installments.amount_cents) as total_cents')
            ->groupBy('month', 'card_purchases.category_id', 'categories.name')
            ->get();

        $months = [];
        foreach ($bank->concat($card) as $row) {
            $months[$row->month]['categories'][$row->category_name] = ($months[$row->month]['categories'][$row->category_name] ?? 0) + (int) $row->total_cents;
            $months[$row->month]['total'] = ($months[$row->month]['total'] ?? 0) + (int) $row->total_cents;
        }

        krsort($months);

        foreach ($months as &$month) {
            arsort($month['categories']);
        }
        unset($month);

        return view('closing.index', ['months' => $months]);
    }
}
