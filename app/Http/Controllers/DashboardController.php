<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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
            ->select('accounts.*', 'institutions.name as institution_name')
            ->get();

        $creditCards = DB::table('credit_cards')
            ->join('accounts', 'accounts.id', '=', 'credit_cards.account_id')
            ->where('accounts.user_id', $user->id)
            ->select('credit_cards.*', 'accounts.name as account_name')
            ->get();

        return view('dashboard.index', [
            'user' => $user,
            'accounts' => $accounts,
            'creditCards' => $creditCards,
            'categoryCount' => DB::table('categories')->where('user_id', $user->id)->count(),
            'ruleCount' => DB::table('rules')->where('user_id', $user->id)->count(),
        ]);
    }
}
