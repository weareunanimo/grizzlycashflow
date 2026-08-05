<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Grizzly\Domain\Classification\MerchantDisplayName;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function index(): View
    {
        $user = Auth::user();

        $accounts = DB::table('accounts')
            ->join('institutions', 'institutions.id', '=', 'accounts.institution_id')
            ->where('accounts.user_id', $user->id)
            ->whereNotIn('accounts.type', ['credit_card', 'voucher'])
            ->whereNull('accounts.archived_at')
            ->select('accounts.*', 'institutions.name as institution_name')
            ->get()
            ->map(function ($account) use ($user) {
                $sums = DB::table('transactions')
                    ->where('account_id', $account->id)
                    ->where('user_id', $user->id)
                    ->whereNull('deleted_at')
                    ->selectRaw("SUM(CASE WHEN direction = 'in' THEN amount_cents ELSE 0 END) as total_in")
                    ->selectRaw("SUM(CASE WHEN direction = 'out' THEN amount_cents ELSE 0 END) as total_out")
                    ->first();

                $account->total_in_cents = (int) ($sums->total_in ?? 0);
                $account->total_out_cents = (int) ($sums->total_out ?? 0);

                $account->recent = DB::table('transactions')
                    ->leftJoin('categories', 'categories.id', '=', 'transactions.category_id')
                    ->where('transactions.account_id', $account->id)
                    ->where('transactions.user_id', $user->id)
                    ->whereNull('transactions.deleted_at')
                    ->orderByDesc('transactions.occurred_on')
                    ->orderByDesc('transactions.id')
                    ->limit(5)
                    ->select('transactions.*', 'categories.name as category_name')
                    ->get();

                return $account;
            });

        $creditCards = DB::table('credit_cards')
            ->join('accounts', 'accounts.id', '=', 'credit_cards.account_id')
            ->where('credit_cards.user_id', $user->id)
            ->select('credit_cards.*', 'accounts.name as account_name')
            ->get()
            ->map(function ($card) use ($user) {
                $card->open_total_cents = (int) DB::table('card_installments')
                    ->where('credit_card_id', $card->id)
                    ->where('user_id', $user->id)
                    ->whereIn('status', ['projected', 'billed'])
                    ->sum('amount_cents');

                $card->recent = DB::table('card_purchases')
                    ->where('credit_card_id', $card->id)
                    ->where('user_id', $user->id)
                    ->whereNull('deleted_at')
                    ->orderByDesc('purchase_date')
                    ->limit(5)
                    ->get()
                    ->map(function ($purchase) {
                        $purchase->display_name = MerchantDisplayName::forRawDescription($purchase->description);

                        return $purchase;
                    });

                return $card;
            });

        // Cartão de benefícios não tem fatura: o que importa é o saldo e os últimos lançamentos.
        $benefitCards = DB::table('accounts')
            ->join('institutions', 'institutions.id', '=', 'accounts.institution_id')
            ->where('accounts.user_id', $user->id)
            ->where('accounts.type', 'voucher')
            ->whereNull('accounts.archived_at')
            ->select('accounts.*', 'institutions.name as institution_name')
            ->get()
            ->map(function ($account) use ($user) {
                $sums = DB::table('transactions')
                    ->where('account_id', $account->id)
                    ->where('user_id', $user->id)
                    ->whereNull('deleted_at')
                    ->selectRaw("SUM(CASE WHEN direction = 'in' THEN amount_cents ELSE 0 END) as total_in")
                    ->selectRaw("SUM(CASE WHEN direction = 'out' THEN amount_cents ELSE 0 END) as total_out")
                    ->first();

                $account->balance_cents = (int) ($sums->total_in ?? 0) - (int) ($sums->total_out ?? 0);

                $account->recent = DB::table('transactions')
                    ->where('account_id', $account->id)
                    ->where('user_id', $user->id)
                    ->whereNull('deleted_at')
                    ->orderByDesc('occurred_on')
                    ->orderByDesc('id')
                    ->limit(5)
                    ->get();

                return $account;
            });

        return view('dashboard.index', [
            'user' => $user,
            'accounts' => $accounts,
            'creditCards' => $creditCards,
            'benefitCards' => $benefitCards,
            'categoryCount' => DB::table('categories')->where('user_id', $user->id)->count(),
            'ruleCount' => DB::table('rules')->where('user_id', $user->id)->count(),
        ]);
    }
}
