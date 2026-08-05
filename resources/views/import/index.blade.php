@extends('layouts.app')

@section('title', 'Importar — ' . config('app.name'))

@section('content')
    <h1 class="text-2xl font-semibold mb-1">Importar</h1>
    <p class="text-sm text-[var(--text-dim)] mb-8">Escolha o que você vai subir: o extrato da conta bancária ou o documento de um cartão.</p>

    <div class="inline-flex gap-[1px] mb-6 text-sm bg-[var(--surface-1)] border border-[var(--border)] rounded-lg p-1">
        <button type="button" id="tab-conta"
            onclick="document.getElementById('form-conta').classList.remove('hidden'); document.getElementById('form-fatura').classList.add('hidden'); document.getElementById('tab-conta').classList.add('bg-[var(--surface-2)]','text-[var(--text)]','font-medium'); document.getElementById('tab-conta').classList.remove('text-[var(--text-dim)]'); document.getElementById('tab-fatura').classList.remove('bg-[var(--surface-2)]','text-[var(--text)]','font-medium'); document.getElementById('tab-fatura').classList.add('text-[var(--text-dim)]');"
            class="px-4 py-2 rounded-md transition-colors bg-[var(--surface-2)] text-[var(--text)] font-medium">
            Extrato da conta
        </button>
        <button type="button" id="tab-fatura"
            onclick="document.getElementById('form-fatura').classList.remove('hidden'); document.getElementById('form-conta').classList.add('hidden'); document.getElementById('tab-fatura').classList.add('bg-[var(--surface-2)]','text-[var(--text)]','font-medium'); document.getElementById('tab-fatura').classList.remove('text-[var(--text-dim)]'); document.getElementById('tab-conta').classList.remove('bg-[var(--surface-2)]','text-[var(--text)]','font-medium'); document.getElementById('tab-conta').classList.add('text-[var(--text-dim)]');"
            class="px-4 py-2 rounded-md transition-colors text-[var(--text-dim)]">
            Fatura ou extrato do cartão
        </button>
    </div>

    <div id="form-conta" class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl p-6 max-w-lg">
        <p class="text-sm text-[var(--text-dim)] mb-4">CSV exportado do app do banco (extrato da conta).</p>
        <form method="POST" action="{{ route('import.conta.preview') }}" enctype="multipart/form-data" class="space-y-4">
            @csrf
            <div>
                <label for="account_id" class="block text-sm text-[var(--text-dim)] mb-1">Conta</label>
                <div class="relative">
                    <select id="account_id" name="account_id" required
                        class="w-full appearance-none rounded-md bg-[var(--surface-2)] border border-[var(--border)] pl-3 pr-9 py-2 text-sm text-[var(--text)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]">
                        @forelse ($accounts as $account)
                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                        @empty
                            <option value="" disabled selected>Nenhuma conta cadastrada</option>
                        @endforelse
                    </select>
                    <svg xmlns="http://www.w3.org/2000/svg" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--text)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                    </svg>
                </div>
                @if ($accounts->isEmpty())
                    <p class="text-xs text-[var(--text-mute)] mt-1"><a href="{{ route('accounts.create') }}" class="text-[var(--accent)] underline">Cadastre uma conta primeiro</a>.</p>
                @endif
            </div>
            <div>
                <label for="file-conta" class="block text-sm text-[var(--text-dim)] mb-1">Arquivo CSV</label>
                <label for="file-conta"
                    class="flex flex-col items-center justify-center gap-2 rounded-md border-2 border-dashed border-[var(--text-mute)] px-2 py-8 text-center cursor-pointer hover:border-[var(--accent)] transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-[var(--text)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L7 9m5-5l5 5" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3" />
                    </svg>
                    <span class="text-sm text-[var(--text-dim)]" data-file-label>Clique para escolher o arquivo CSV</span>
                    <span class="text-xs text-[var(--text-mute)]">ou arraste e solte aqui</span>
                </label>
                <input type="file" id="file-conta" name="file" accept=".csv" required class="sr-only"
                    onchange="this.previousElementSibling.querySelector('[data-file-label]').textContent = this.files[0]?.name ?? 'Clique para escolher o arquivo CSV'">
            </div>
            <button type="submit"
                class="w-full rounded-md bg-[var(--accent)] text-[var(--bg)] font-semibold py-2 text-sm hover:opacity-90 transition-opacity">
                Ver prévia
            </button>
        </form>
    </div>

    <div id="form-fatura" class="hidden bg-[var(--surface-1)] border border-[var(--border)] rounded-xl p-6 max-w-lg">
        <p class="text-sm text-[var(--text-dim)] mb-4">
            CSV exportado do app do cartão. Cartão de crédito manda a fatura; cartão de benefícios manda o extrato.
        </p>
        <form method="POST" action="{{ route('import.cartao.preview') }}" enctype="multipart/form-data" class="space-y-4">
            @csrf
            <div>
                <label for="card_key" class="block text-sm text-[var(--text-dim)] mb-1">Cartão</label>
                <div class="relative">
                    <select id="card_key" name="card_key" required
                        onchange="grizzlyCardChanged(this)"
                        class="w-full appearance-none rounded-md bg-[var(--surface-2)] border border-[var(--border)] pl-3 pr-9 py-2 text-sm text-[var(--text)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]">
                        @forelse ($cards as $card)
                            <option value="{{ $card->key }}" data-needs-month="{{ $card->needs_month ? '1' : '0' }}">{{ $card->name }}</option>
                        @empty
                            <option value="" disabled selected>Nenhum cartão cadastrado</option>
                        @endforelse
                    </select>
                    <svg xmlns="http://www.w3.org/2000/svg" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--text)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                    </svg>
                </div>
                @if ($cards->isEmpty())
                    <p class="text-xs text-[var(--text-mute)] mt-1"><a href="{{ route('accounts.create') }}" class="text-[var(--accent)] underline">Cadastre um cartão primeiro</a>.</p>
                @endif
            </div>
            <div>
                <label for="file-fatura" class="block text-sm text-[var(--text-dim)] mb-1">Arquivo CSV</label>
                <label for="file-fatura"
                    class="flex flex-col items-center justify-center gap-2 rounded-md border-2 border-dashed border-[var(--text-mute)] px-2 py-8 text-center cursor-pointer hover:border-[var(--accent)] transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-[var(--text)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L7 9m5-5l5 5" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3" />
                    </svg>
                    <span class="text-sm text-[var(--text-dim)]" data-file-label>Clique para escolher o arquivo CSV</span>
                    <span class="text-xs text-[var(--text-mute)]">ou arraste e solte aqui</span>
                </label>
                <input type="file" id="file-fatura" name="file" accept=".csv" required class="sr-only"
                    onchange="
                        this.previousElementSibling.querySelector('[data-file-label]').textContent = this.files[0]?.name ?? 'Clique para escolher o arquivo CSV';
                        const m = (this.files[0]?.name ?? '').match(/(\d{4})(\d{2})\d{2}/);
                        if (m) document.getElementById('reference_month').value = m[1] + '-' + m[2];
                    ">
            </div>
            {{-- Só a fatura do cartão de crédito tem mês de referência. O extrato do
                 cartão de benefícios não, porque não existe fatura. --}}
            <div id="campo-mes">
                <label for="reference_month" class="block text-sm text-[var(--text-dim)] mb-1">Mês desta fatura</label>
                <input type="month" id="reference_month" name="reference_month" value="{{ $defaultMonth }}"
                    class="w-full rounded-md bg-[var(--surface-2)] border border-[var(--border)] px-3 py-2 text-sm text-[var(--text)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]">
                <p class="text-xs text-[var(--text-mute)] mt-1">Preenchido automaticamente pelo nome do arquivo (ex: Fatura20260901 = setembro/2026) — confira antes de continuar.</p>
            </div>
            <button type="submit"
                class="w-full rounded-md bg-[var(--accent)] text-[var(--bg)] font-semibold py-2 text-sm hover:opacity-90 transition-opacity">
                Ver prévia
            </button>
        </form>
    </div>

    <script>
        // Cartão de benefícios não tem fatura: esconde o mês de referência.
        function grizzlyCardChanged(select) {
            var option = select.options[select.selectedIndex];
            var needsMonth = !option || option.dataset.needsMonth !== '0';
            document.getElementById('campo-mes').classList.toggle('hidden', !needsMonth);
        }

        grizzlyCardChanged(document.getElementById('card_key'));
    </script>
@endsection
