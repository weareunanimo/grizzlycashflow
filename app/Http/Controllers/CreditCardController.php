<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class CreditCardController extends Controller
{
    public function show(int $id): View
    {
        $userId = Auth::id();

        $card = DB::table('credit_cards')
            ->join('accounts', 'accounts.id', '=', 'credit_cards.account_id')
            ->where('credit_cards.id', $id)
            ->where('credit_cards.user_id', $userId)
            ->select('credit_cards.*', 'accounts.name as account_name')
            ->firstOrFail();

        $purchases = DB::table('card_purchases')
            ->leftJoin('categories', 'categories.id', '=', 'card_purchases.category_id')
            ->where('card_purchases.credit_card_id', $id)
            ->where('card_purchases.user_id', $userId)
            ->whereNull('card_purchases.deleted_at')
            ->orderByDesc('card_purchases.purchase_date')
            ->select('card_purchases.*', 'categories.name as category_name')
            ->paginate(50);

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

        return view('cards.show', ['card' => $card, 'purchases' => $purchases, 'projection' => $projection]);
    }
}
