<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class BankController extends Controller
{
    public function index(): View
    {
        $userId = Auth::id();

        $accounts = DB::table('accounts')
            ->join('institutions', 'institutions.id', '=', 'accounts.institution_id')
            ->where('accounts.user_id', $userId)
            ->where('accounts.type', '!=', 'credit_card')
            ->whereNull('accounts.archived_at')
            ->select('accounts.*', 'institutions.name as institution_name')
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

        return view('banks.index', ['accounts' => $accounts]);
    }
}
