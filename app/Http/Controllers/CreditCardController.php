<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Grizzly\Domain\Classification\MerchantDisplayName;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class CreditCardController extends Controller
{
    public function index(Request $request): View
    {
        $userId = Auth::id();

        $cards = DB::table('credit_cards')
            ->join('accounts', 'accounts.id', '=', 'credit_cards.account_id')
            ->where('credit_cards.user_id', $userId)
            ->select('credit_cards.*', 'accounts.name as account_name')
            ->orderBy('credit_cards.id')
            ->get()
            ->map(function ($card) use ($userId) {
                $card->open_total_cents = (int) DB::table('card_installments')
                    ->where('credit_card_id', $card->id)
                    ->where('user_id', $userId)
                    ->whereIn('status', ['projected', 'billed'])
                    ->sum('amount_cents');

                return $card;
            });

        $selectedId = (int) $request->query('card', $cards->first()->id ?? 0);
        $selected = $cards->firstWhere('id', $selectedId) ?? $cards->first();

        $tab = in_array($request->query('tab'), ['projecao', 'parcelamentos'], true) ? $request->query('tab') : 'compras';

        $purchases = null;
        $projection = null;
        $installmentPlans = null;

        if ($selected) {
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
        ]);
    }

    public function destroy(int $id): RedirectResponse
    {
        $userId = Auth::id();

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
            DB::table('card_installments')->where('credit_card_id', $id)->where('user_id', $userId)->delete();
            DB::table('card_purchases')->where('credit_card_id', $id)->where('user_id', $userId)->delete();
            DB::table('credit_cards')->where('id', $id)->where('user_id', $userId)->delete();
            DB::table('accounts')->where('id', $card->account_id)->where('user_id', $userId)->delete();
        });

        return redirect()->route('cards.index')->with('status', "\"{$card->account_name}\" e todos os lançamentos dele foram apagados.");
    }
}
