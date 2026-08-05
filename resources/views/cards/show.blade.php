@extends('layouts.app')

@section('title', $card->account_name . ' — ' . config('app.name'))

@section('content')
    <h1 class="text-2xl font-semibold mb-1">{{ $card->account_name }}</h1>
    <p class="text-sm text-[var(--text-dim)] mb-6">
        {{ ucfirst((string) $card->brand) }} · fecha dia {{ $card->closing_day }}, vence dia {{ $card->due_day }}
    </p>

    <div class="overflow-x-auto mb-6 -mx-4 px-4 sm:mx-0 sm:px-0">
        <div class="inline-flex gap-[1px] text-sm bg-[var(--surface-1)] border border-[var(--border)] rounded-lg p-1">
            @foreach (['compras' => 'Compras', 'projecao' => 'Projeção de faturas', 'parcelamentos' => 'Parcelamentos'] as $key => $label)
                <a href="{{ route('cards.show', ['id' => $card->id, 'tab' => $key]) }}"
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
                            <th class="px-4 sm:px-5 py-3">Data</th>
                            <th class="px-4 sm:px-5 py-3">Estabelecimento</th>
                            <th class="px-4 sm:px-5 py-3">Categoria</th>
                            <th class="px-4 sm:px-5 py-3">Parcelas</th>
                            <th class="px-4 sm:px-5 py-3">Valor/parcela</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border)]">
                        @forelse ($purchases as $purchase)
                            <tr>
                                <td class="px-4 sm:px-5 py-2.5 whitespace-nowrap">
                                    {{ $purchase->purchase_date ? \Illuminate\Support\Carbon::parse($purchase->purchase_date)->format('d/m/Y') : '—' }}
                                </td>
                                <td class="px-4 sm:px-5 py-2.5 whitespace-nowrap">{{ $purchase->display_name }}</td>
                                <td class="px-4 sm:px-5 py-2.5 text-[var(--text-dim)]">
                                    {{ $purchase->category_name ?? '—' }}
                                    @if ($purchase->needs_review)
                                        <span class="ml-1 text-xs text-[var(--warn)]" title="Nenhuma regra reconheceu essa compra com confiança — categoria ainda não definida.">revisar</span>
                                    @endif
                                </td>
                                <td class="px-4 sm:px-5 py-2.5 text-[var(--text-dim)]">
                                    {{ $purchase->current_number ?? 1 }}/{{ $purchase->installments_total }}x
                                </td>
                                <td class="px-4 sm:px-5 py-2.5 font-mono {{ $purchase->installment_amount_cents < 0 ? 'text-[var(--in)]' : 'text-[var(--out)]' }}">
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
                            <th class="px-4 sm:px-5 py-3">Mês</th>
                            <th class="px-4 sm:px-5 py-3">Itens</th>
                            <th class="px-4 sm:px-5 py-3">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border)]">
                        @foreach ($projection as $month)
                            <tr>
                                <td class="px-4 sm:px-5 py-2.5">{{ \Illuminate\Support\Carbon::parse($month->reference_month)->format('m/Y') }}</td>
                                <td class="px-4 sm:px-5 py-2.5 text-[var(--text-dim)]">{{ $month->items }}</td>
                                <td class="px-4 sm:px-5 py-2.5 font-mono text-[var(--out)]">
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
                            <th class="px-4 sm:px-5 py-3">Compra</th>
                            <th class="px-4 sm:px-5 py-3">Valor/parcela</th>
                            <th class="px-4 sm:px-5 py-3">Pagas</th>
                            <th class="px-4 sm:px-5 py-3">Faltam</th>
                            <th class="px-4 sm:px-5 py-3">Progresso</th>
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
                                <td class="px-4 sm:px-5 py-2.5 whitespace-nowrap">{{ $purchase->display_name }}</td>
                                <td class="px-4 sm:px-5 py-2.5 font-mono text-[var(--text-dim)]">R$ {{ number_format($purchase->installment_amount_cents / 100, 2, ',', '.') }}</td>
                                <td class="px-4 sm:px-5 py-2.5 text-[var(--in)]">{{ $paid }}/{{ $purchase->installments_total }}</td>
                                <td class="px-4 sm:px-5 py-2.5 text-[var(--out)]">{{ $remaining }}</td>
                                <td class="px-4 sm:px-5 py-2.5">
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
@endsection
