<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Entrada única de importação — o usuário escolhe extrato ou fatura aqui,
 * mas cada um continua submetendo para seu próprio fluxo de preview/commit
 * (Import\ContaImportController / Import\FaturaImportController), já que os
 * parsers e o formato dos dois são bem diferentes (docs/13).
 */
final class ImportController extends Controller
{
    public function index(): View
    {
        $userId = Auth::id();

        $accounts = DB::table('accounts')
            ->where('user_id', $userId)
            ->where('type', '!=', 'credit_card')
            ->whereNull('archived_at')
            ->get();

        $cards = DB::table('credit_cards')
            ->join('accounts', 'accounts.id', '=', 'credit_cards.account_id')
            ->where('credit_cards.user_id', $userId)
            ->select('credit_cards.id', 'accounts.name')
            ->get();

        return view('import.index', [
            'accounts' => $accounts,
            'cards' => $cards,
            'defaultMonth' => now()->format('Y-m'),
        ]);
    }
}
