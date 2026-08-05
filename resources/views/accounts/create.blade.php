@extends('layouts.app')

@section('title', 'Nova conta/cartão — ' . config('app.name'))

@section('content')
    <h1 class="text-2xl font-semibold mb-1">Adicionar conta ou cartão</h1>
    <p class="text-sm text-[var(--text-dim)] mb-8">Cadastre uma conta corrente ou um cartão de crédito novo para poder importar o extrato/fatura dele.</p>

    <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl p-6 max-w-lg">
        <form method="POST" action="{{ route('accounts.store') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm text-[var(--text-dim)] mb-1">Tipo</label>
                <div class="relative">
                    <select name="kind" id="kind" required onchange="grizzlyKindChanged(this.value)"
                        class="w-full appearance-none rounded-md bg-[var(--surface-2)] border border-[var(--border)] pl-3 pr-9 py-2 text-sm text-[var(--text)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]">
                        <option value="checking">Conta corrente</option>
                        <option value="credit_card">Cartão de crédito</option>
                        <option value="voucher">Cartão de benefícios (alimentação, refeição, mobilidade)</option>
                    </select>
                    <svg xmlns="http://www.w3.org/2000/svg" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--text)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                    </svg>
                </div>
            </div>

            <div>
                <label for="institution_name" class="block text-sm text-[var(--text-dim)] mb-1">Instituição</label>
                <input type="text" id="institution_name" name="institution_name" required placeholder="Ex.: Banco NASA S.A."
                    class="w-full rounded-md bg-[var(--surface-2)] border border-[var(--border)] px-3 py-2 text-sm text-[var(--text)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]">
            </div>

            <div>
                <label for="account_name" class="block text-sm text-[var(--text-dim)] mb-1">Nome (como quer ver na lista)</label>
                <input type="text" id="account_name" name="account_name" required placeholder="Ex.: NASA"
                    class="w-full rounded-md bg-[var(--surface-2)] border border-[var(--border)] px-3 py-2 text-sm text-[var(--text)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]">
            </div>

            <p id="voucher-hint" class="hidden text-xs text-[var(--text-mute)]">
                Cartão de benefícios funciona por saldo/crédito disponível e não gera fatura —
                por isso não pedimos limite, dia de fechamento nem vencimento. Os lançamentos
                dele entram pela importação de extrato.
            </p>

            <div id="card-fields" class="hidden space-y-4">
                <div>
                    <label for="brand" class="block text-sm text-[var(--text-dim)] mb-1">Bandeira</label>
                    <div class="relative">
                        <select name="brand" id="brand"
                            class="w-full appearance-none rounded-md bg-[var(--surface-2)] border border-[var(--border)] pl-3 pr-9 py-2 text-sm text-[var(--text)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]">
                            <option value="visa">Visa</option>
                            <option value="mastercard">Mastercard</option>
                            <option value="elo">Elo</option>
                            <option value="amex">Amex</option>
                            <option value="hipercard">Hipercard</option>
                            <option value="other">Outra</option>
                        </select>
                        <svg xmlns="http://www.w3.org/2000/svg" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--text)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                        </svg>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="closing_day" class="block text-sm text-[var(--text-dim)] mb-1">Dia de fechamento</label>
                        <input type="number" id="closing_day" name="closing_day" min="1" max="31" placeholder="18"
                            class="w-full rounded-md bg-[var(--surface-2)] border border-[var(--border)] px-3 py-2 text-sm text-[var(--text)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]">
                    </div>
                    <div>
                        <label for="due_day" class="block text-sm text-[var(--text-dim)] mb-1">Dia de vencimento</label>
                        <input type="number" id="due_day" name="due_day" min="1" max="31" placeholder="1"
                            class="w-full rounded-md bg-[var(--surface-2)] border border-[var(--border)] px-3 py-2 text-sm text-[var(--text)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]">
                    </div>
                </div>
                <div>
                    <label for="credit_limit" class="block text-sm text-[var(--text-dim)] mb-1">Limite (opcional)</label>
                    <input type="number" id="credit_limit" name="credit_limit" step="0.01" min="0" placeholder="5000.00"
                        class="w-full rounded-md bg-[var(--surface-2)] border border-[var(--border)] px-3 py-2 text-sm text-[var(--text)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]">
                </div>
            </div>

            <button type="submit"
                class="w-full rounded-md bg-[var(--accent)] text-[var(--bg)] font-semibold py-2 text-sm hover:opacity-90 transition-opacity">
                Adicionar
            </button>
        </form>
    </div>

    <script>
        // Só o cartão de crédito tem limite/fechamento/vencimento. Os exemplos de
        // preenchimento também mudam entre banco e cartão.
        function grizzlyKindChanged(kind) {
            document.getElementById('card-fields').classList.toggle('hidden', kind !== 'credit_card');
            document.getElementById('voucher-hint').classList.toggle('hidden', kind !== 'voucher');

            var isCard = kind === 'credit_card' || kind === 'voucher';
            document.getElementById('institution_name').placeholder = isCard ? 'Ex.: Banco WTF S.A.' : 'Ex.: Banco NASA S.A.';
            document.getElementById('account_name').placeholder = isCard ? 'Ex.: WTF' : 'Ex.: NASA';
        }

        grizzlyKindChanged(document.getElementById('kind').value);
    </script>
@endsection
