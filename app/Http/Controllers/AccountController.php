<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class AccountController extends Controller
{
    public function show(int $id): View
    {
        $userId = Auth::id();

        $account = DB::table('accounts')
            ->join('institutions', 'institutions.id', '=', 'accounts.institution_id')
            ->where('accounts.id', $id)
            ->where('accounts.user_id', $userId)
            ->select('accounts.*', 'institutions.name as institution_name')
            ->firstOrFail();

        $transactions = DB::table('transactions')
            ->leftJoin('categories', 'categories.id', '=', 'transactions.category_id')
            ->where('transactions.account_id', $id)
            ->where('transactions.user_id', $userId)
            ->whereNull('transactions.deleted_at')
            ->orderByDesc('transactions.occurred_on')
            ->orderByDesc('transactions.id')
            ->select('transactions.*', 'categories.name as category_name')
            ->paginate(50);

        return view('accounts.show', ['account' => $account, 'transactions' => $transactions]);
    }
}
