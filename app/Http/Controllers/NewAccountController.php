<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Cadastro manual de conta/cartão novo (ex.: um cartão novo que o usuário
 * passa a usar) — antes disso só existiam as contas semeadas no onboarding.
 */
final class NewAccountController extends Controller
{
    public function create(): View
    {
        return view('accounts.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $userId = Auth::id();

        // `voucher` = cartão de benefícios (alimentação/refeição/mobilidade): funciona
        // por saldo, não gera fatura — então não pede limite, fechamento nem vencimento.
        $validated = $request->validate([
            'kind' => ['required', 'in:checking,credit_card,voucher'],
            'institution_name' => ['required', 'string', 'max:120'],
            'account_name' => ['required', 'string', 'max:120'],
            'brand' => ['nullable', 'string', 'max:20'],
            'closing_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'due_day' => ['required_if:kind,credit_card', 'nullable', 'integer', 'min:1', 'max:31'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
        ]);

        $institutionId = DB::table('institutions')
            ->where('user_id', $userId)
            ->where('name', $validated['institution_name'])
            ->value('id');

        if (! $institutionId) {
            $institutionId = DB::table('institutions')->insertGetId([
                'user_id' => $userId,
                'name' => $validated['institution_name'],
                'kind' => in_array($validated['kind'], ['credit_card', 'voucher'], true) ? 'card_issuer' : 'bank',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $accountId = DB::table('accounts')->insertGetId([
            'user_id' => $userId,
            'institution_id' => $institutionId,
            'name' => $validated['account_name'],
            'type' => $validated['kind'],
            'currency' => 'BRL',
            'opening_balance_cents' => 0,
            'opening_balance_date' => now()->format('Y-m-d'),
            'include_in_networth' => true,
            'include_in_cashflow' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($validated['kind'] === 'credit_card') {
            DB::table('credit_cards')->insert([
                'user_id' => $userId,
                'account_id' => $accountId,
                'brand' => $validated['brand'] ?? null,
                'credit_limit_cents' => isset($validated['credit_limit']) ? (int) round($validated['credit_limit'] * 100) : null,
                'closing_day' => $validated['closing_day'] ?? null,
                'due_day' => $validated['due_day'],
                'due_day_rule' => 'next_business_day',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $destination = match ($validated['kind']) {
            'credit_card', 'voucher' => route('cards.index'),
            default => route('banks.index'),
        };

        return redirect()->to($destination)->with('status', "\"{$validated['account_name']}\" cadastrado. Já dá para importar extrato/fatura.");
    }
}
