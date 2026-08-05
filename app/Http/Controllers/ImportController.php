<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Import\ContaImportController;
use App\Http\Controllers\Import\FaturaImportController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Entrada única de importação. São dois documentos possíveis: extrato da conta
 * bancária e documento de cartão.
 *
 * "Documento de cartão" cobre os dois tipos: o cartão de crédito manda fatura,
 * o cartão de benefícios manda extrato (não tem fatura). Para o usuário é uma
 * escolha só — quem decide qual parser roda é o tipo do cartão escolhido, não
 * uma aba diferente (docs/13).
 */
final class ImportController extends Controller
{
    public function index(): View
    {
        $userId = Auth::id();

        $accounts = DB::table('accounts')
            ->where('user_id', $userId)
            ->whereNotIn('type', ['credit_card', 'voucher'])
            ->whereNull('archived_at')
            ->orderBy('id')
            ->get(['id', 'name']);

        return view('import.index', [
            'accounts' => $accounts,
            'cards' => $this->cardOptions($userId),
            'defaultMonth' => now()->format('Y-m'),
        ]);
    }

    /**
     * Um formulário, dois destinos: fatura (crédito) ou extrato (benefícios).
     * A chave do cartão carrega o tipo, então a decisão não depende de JavaScript.
     */
    public function previewCard(
        Request $request,
        ContaImportController $conta,
        FaturaImportController $fatura,
    ): View|RedirectResponse {
        $userId = Auth::id();

        $request->validate(['card_key' => ['required', 'string', 'max:20']]);
        $key = (string) $request->input('card_key');

        if (str_starts_with($key, 'v')) {
            $accountId = DB::table('accounts')
                ->where('id', (int) substr($key, 1))
                ->where('user_id', $userId)
                ->where('type', 'voucher')
                ->value('id');

            if ($accountId === null) {
                abort(404);
            }

            $request->merge(['account_id' => (int) $accountId]);

            return $conta->preview($request);
        }

        $creditCardId = DB::table('credit_cards')
            ->where('id', (int) ltrim($key, 'c'))
            ->where('user_id', $userId)
            ->value('id');

        if ($creditCardId === null) {
            abort(404);
        }

        $request->merge(['credit_card_id' => (int) $creditCardId]);

        return $fatura->preview($request);
    }

    /**
     * Cartão de crédito e de benefícios na mesma lista, com a chave dizendo o tipo
     * (c<credit_card_id> / v<account_id>) e se pede o mês da fatura.
     *
     * @return Collection<int,object>
     */
    private function cardOptions(int $userId): Collection
    {
        $credit = DB::table('credit_cards')
            ->join('accounts', 'accounts.id', '=', 'credit_cards.account_id')
            ->where('credit_cards.user_id', $userId)
            ->whereNull('accounts.archived_at')
            ->orderBy('credit_cards.id')
            ->get(['credit_cards.id', 'accounts.name as account_name'])
            ->map(fn ($card) => (object) [
                'key' => 'c'.$card->id,
                'name' => (string) $card->account_name,
                'needs_month' => true,
            ]);

        $vouchers = DB::table('accounts')
            ->where('user_id', $userId)
            ->where('type', 'voucher')
            ->whereNull('archived_at')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn ($account) => (object) [
                'key' => 'v'.$account->id,
                'name' => (string) $account->name.' (benefícios)',
                'needs_month' => false,
            ]);

        return $credit->concat($vouchers)->values();
    }
}
