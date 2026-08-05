@extends('layouts.app')

@section('title', 'Cartões — ' . config('app.name'))

@section('content')
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold">Cartões</h1>
        <a href="{{ route('accounts.create') }}" class="text-sm text-[var(--accent)] hover:opacity-80 transition-opacity">+ Adicionar</a>
    </div>

    @if ($cards->isEmpty())
        <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl px-5 py-8 text-center text-sm text-[var(--text-dim)]">
            Nenhum cartão cadastrado. <a href="{{ route('accounts.create') }}" class="text-[var(--accent)] underline">Adicionar</a>
        </div>
    @else
        <div class="flex flex-wrap gap-[1px] mb-6 text-sm bg-[var(--surface-1)] border border-[var(--border)] rounded-lg p-1 w-fit max-w-full">
            @foreach ($cards as $card)
                <a href="{{ route('cards.index', ['card' => $card->key]) }}"
                    class="px-4 py-2 rounded-md transition-colors whitespace-nowrap {{ $selected && $selected->key === $card->key ? 'bg-[var(--surface-2)] text-[var(--text)] font-medium' : 'text-[var(--text-dim)]' }}">
                    {{ $card->account_name }}
                </a>
            @endforeach
        </div>

        @if ($selected)
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-6">
                <div>
                    @if ($selected->card_type === 'voucher')
                        <p class="text-sm text-[var(--text-dim)]">Cartão de benefícios · funciona por saldo, sem fatura</p>
                        <p class="text-sm mt-1">
                            <span class="text-xs text-[var(--text-mute)] uppercase tracking-wider mr-1">Saldo</span>
                            <span class="font-mono {{ $selected->balance_cents < 0 ? 'text-[var(--out)]' : 'text-[var(--in)]' }}">R$ {{ number_format($selected->balance_cents / 100, 2, ',', '.') }}</span>
                        </p>
                    @else
                        <p class="text-sm text-[var(--text-dim)]">
                            {{ ucfirst((string) $selected->brand) }} · fecha dia {{ $selected->closing_day }}, vence dia {{ $selected->due_day }}
                        </p>
                        <p class="text-sm mt-1">
                            <span class="text-xs text-[var(--text-mute)] uppercase tracking-wider mr-1">Total em aberto</span>
                            <span class="font-mono text-[var(--out)]">R$ {{ number_format($selected->open_total_cents / 100, 2, ',', '.') }}</span>
                        </p>
                    @endif
                </div>
                <div class="flex items-center gap-4 shrink-0">
                    <details class="relative">
                        <summary class="list-none cursor-pointer text-sm text-[var(--accent)] hover:opacity-80 transition-opacity">Renomear</summary>
                        <form method="POST" action="{{ route('cards.update', $selected->key) }}"
                            class="absolute right-0 z-10 mt-2 w-72 bg-[var(--surface-1)] border border-[var(--border)] rounded-xl p-4 flex flex-col gap-2">
                            @csrf
                            @method('PATCH')
                            <label for="card-name" class="text-xs text-[var(--text-dim)]">Nome do cartão</label>
                            <input type="text" id="card-name" name="name" value="{{ $selected->account_name }}" required maxlength="120"
                                class="w-full rounded-md bg-[var(--surface-2)] border border-[var(--border)] px-3 py-2 text-sm text-[var(--text)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]">
                            <button type="submit"
                                class="rounded-md bg-[var(--accent)] text-[var(--bg)] font-semibold py-2 text-sm hover:opacity-90 transition-opacity">
                                Salvar
                            </button>
                        </form>
                    </details>
                    <form method="POST" action="{{ route('cards.destroy', $selected->key) }}"
                        onsubmit="return confirm('Tem certeza que deseja excluir o cartão \'{{ $selected->account_name }}\'? Todos os dados relacionados a ele serão apagados permanentemente. Essa ação não pode ser desfeita.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-sm text-[var(--critical)] hover:opacity-80 transition-opacity">Excluir cartão</button>
                    </form>
                </div>
            </div>

            @error('name')
                <p class="text-sm text-[var(--critical)] mb-4">{{ $message }}</p>
            @enderror

            @if ($selected->card_type === 'voucher')
                <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl overflow-hidden mb-4">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[640px] text-sm">
                            <thead>
                                <tr class="border-b border-[var(--border)] text-left text-xs uppercase tracking-wider text-[var(--text-mute)]">
                                    <th>Data</th>
                                    <th>Descrição</th>
                                    <th>Categoria</th>
                                    <th>Valor</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--border)]">
                                @forelse ($rows as $tx)
                                    <tr>
                                        <td class="whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($tx->occurred_on)->format('d/m/Y') }}</td>
                                        <td class="whitespace-nowrap">
                                            {{ \Grizzly\Domain\Classification\DescriptionCleaner::forDisplay($tx->description) }}
                                            @if ($tx->needs_review)
                                                @include('partials.review-dot')
                                            @endif
                                        </td>
                                        <td class="text-[var(--text-dim)]">{{ $tx->category_name ?? '—' }}</td>
                                        <td class="font-mono {{ $tx->direction === 'out' ? 'text-[var(--out)]' : 'text-[var(--in)]' }}">
                                            {{ $tx->direction === 'out' ? '-' : '' }}R$ {{ number_format($tx->amount_cents / 100, 2, ',', '.') }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="px-4 py-8 text-center text-[var(--text-dim)]">
                                            Nenhum lançamento ainda. <a href="{{ route('import.index') }}" class="text-[var(--accent)] underline">Importar extrato</a>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                {{ $rows->links() }}
            @else

            <div class="overflow-x-auto mb-6 -mx-4 px-4 sm:mx-0 sm:px-0">
                <div class="inline-flex gap-[1px] text-sm bg-[var(--surface-1)] border border-[var(--border)] rounded-lg p-1">
                    @foreach (['compras' => 'Compras', 'projecao' => 'Projeção de faturas', 'parcelamentos' => 'Parcelamentos'] as $key => $label)
                        <a href="{{ route('cards.index', ['card' => $selected->key, 'tab' => $key]) }}"
                            class="whitespace-nowrap px-4 py-2 rounded-md transition-colors {{ $tab === $key ? 'bg-[var(--surface-2)] text-[var(--text)] font-medium' : 'text-[var(--text-dim)] hover:text-[var(--text)]' }}">
                            {{ $label }}
                        </a>
                    @endforeach
                </div>
            </div>

            @if ($tab === 'compras')
                <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl overflow-hidden mb-4">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[640px] text-sm">
                            <thead>
                                <tr class="border-b border-[var(--border)] text-left text-xs uppercase tracking-wider text-[var(--text-mute)]">
                                    <th>Data</th>
                                    <th>Estabelecimento</th>
                                    <th>Categoria</th>
                                    <th>Parcelas</th>
                                    <th>Valor/parcela</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--border)]">
                                @forelse ($purchases as $purchase)
                                    <tr>
                                        <td class="whitespace-nowrap">
                                            {{ $purchase->purchase_date ? \Illuminate\Support\Carbon::parse($purchase->purchase_date)->format('d/m/Y') : '—' }}
                                        </td>
                                        <td class="whitespace-nowrap">{{ $purchase->display_name }}</td>
                                        <td class="text-[var(--text-dim)]">
                                            {{ $purchase->category_name ?? '—' }}
                                            @if ($purchase->needs_review)
                                                @include('partials.review-dot')
                                            @endif
                                        </td>
                                        <td class="text-[var(--text-dim)]">
                                            {{ $purchase->current_number ?? 1 }}/{{ $purchase->installments_total }}x
                                        </td>
                                        <td class="font-mono {{ $purchase->installment_amount_cents < 0 ? 'text-[var(--in)]' : 'text-[var(--out)]' }}">
                                            R$ {{ number_format($purchase->installment_amount_cents / 100, 2, ',', '.') }}
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-4 py-8 text-center text-[var(--text-dim)]">
                                            Nenhuma compra ainda. <a href="{{ route('import.index') }}" class="text-[var(--accent)] underline">Importar fatura</a>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                {{ $purchases->links() }}
            @endif

            @if ($tab === 'projecao')
                <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl overflow-hidden">
                    @if ($projection->isEmpty())
                        <p class="px-5 py-6 text-sm text-[var(--text-dim)]">
                            Nenhuma parcela projetada ainda. <a href="{{ route('import.index') }}" class="text-[var(--accent)] underline">Importar fatura</a>
                        </p>
                    @else
                        <table class="w-full min-w-[640px] text-sm">
                            <thead>
                                <tr class="border-b border-[var(--border)] text-left text-xs uppercase tracking-wider text-[var(--text-mute)]">
                                    <th>Mês</th>
                                    <th>Itens</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--border)]">
                                @foreach ($projection as $month)
                                    <tr>
                                        <td>{{ \Illuminate\Support\Carbon::parse($month->reference_month)->format('m/Y') }}</td>
                                        <td class="text-[var(--text-dim)]">{{ $month->items }}</td>
                                        <td class="font-mono text-[var(--out)]">
                                            R$ {{ number_format($month->total_cents / 100, 2, ',', '.') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            @endif

            @if ($tab === 'parcelamentos')
                <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[640px] text-sm">
                            <thead>
                                <tr class="border-b border-[var(--border)] text-left text-xs uppercase tracking-wider text-[var(--text-mute)]">
                                    <th>Compra</th>
                                    <th>Valor/parcela</th>
                                    <th>Pagas</th>
                                    <th>Faltam</th>
                                    <th>Progresso</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--border)]">
                                @forelse ($installmentPlans as $purchase)
                                    @php
                                        $paid = (int) ($purchase->paid_count ?? 0);
                                        $remaining = (int) ($purchase->remaining_count ?? ($purchase->installments_total - $paid));
                                        $pct = $purchase->installments_total > 0 ? round(($paid / $purchase->installments_total) * 100) : 0;
                                    @endphp
                                    <tr>
                                        <td class="whitespace-nowrap">{{ $purchase->display_name }}</td>
                                        <td class="font-mono text-[var(--text-dim)]">R$ {{ number_format($purchase->installment_amount_cents / 100, 2, ',', '.') }}</td>
                                        <td class="text-[var(--in)]">{{ $paid }}/{{ $purchase->installments_total }}</td>
                                        <td class="text-[var(--out)]">{{ $remaining }}</td>
                                        <td>
                                            <div class="w-32 h-1.5 rounded-full bg-[var(--surface-2)] overflow-hidden">
                                                <div class="h-full bg-[var(--accent)]" style="width: {{ $pct }}%"></div>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-4 py-8 text-center text-[var(--text-dim)]">Nenhum parcelamento em aberto.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
            @endif
        @endif
    @endif
@endsection
