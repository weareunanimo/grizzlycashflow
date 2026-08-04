@extends('layouts.app')

@section('title', $card->account_name . ' — ' . config('app.name'))

@section('content')
    <h1 class="text-2xl font-semibold mb-1">{{ $card->account_name }}</h1>
    <p class="text-sm text-[var(--text-dim)] mb-8">
        {{ ucfirst((string) $card->brand) }} · fecha dia {{ $card->closing_day }}, vence dia {{ $card->due_day }}
    </p>

    <section class="mb-8">
        <h2 class="text-sm font-semibold text-[var(--text-dim)] uppercase tracking-wider mb-3">Projeção de parcelas futuras</h2>
        <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl overflow-hidden">
            @if ($projection->isEmpty())
                <p class="px-5 py-6 text-sm text-[var(--text-dim)]">
                    Nenhuma parcela projetada ainda. <a href="{{ route('import.fatura.show') }}" class="text-[var(--accent)] underline">Importar fatura</a>
                </p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-[var(--border)] text-left text-xs uppercase tracking-wider text-[var(--text-mute)]">
                            <th class="px-4 py-3">Mês</th>
                            <th class="px-4 py-3">Itens</th>
                            <th class="px-4 py-3 text-right">Total</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border)]">
                        @foreach ($projection as $month)
                            <tr>
                                <td class="px-4 py-2">{{ \Illuminate\Support\Carbon::parse($month->reference_month)->translatedFormat('M/Y') }}</td>
                                <td class="px-4 py-2 text-[var(--text-dim)]">{{ $month->items }}</td>
                                <td class="px-4 py-2 text-right font-mono text-[var(--out)]">
                                    R$ {{ number_format($month->total_cents / 100, 2, ',', '.') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </section>

    <section>
        <h2 class="text-sm font-semibold text-[var(--text-dim)] uppercase tracking-wider mb-3">Compras</h2>
        <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl overflow-hidden mb-4">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-[var(--border)] text-left text-xs uppercase tracking-wider text-[var(--text-mute)]">
                            <th class="px-4 py-3">Data</th>
                            <th class="px-4 py-3">Estabelecimento</th>
                            <th class="px-4 py-3">Categoria</th>
                            <th class="px-4 py-3">Parcelas</th>
                            <th class="px-4 py-3 text-right">Valor/parcela</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--border)]">
                        @forelse ($purchases as $purchase)
                            <tr>
                                <td class="px-4 py-2 whitespace-nowrap">
                                    {{ $purchase->purchase_date ? \Illuminate\Support\Carbon::parse($purchase->purchase_date)->format('d/m/Y') : '—' }}
                                </td>
                                <td class="px-4 py-2">{{ $purchase->description }}</td>
                                <td class="px-4 py-2 text-[var(--text-dim)]">{{ $purchase->category_name ?? '—' }}</td>
                                <td class="px-4 py-2 text-[var(--text-dim)]">{{ $purchase->installments_total }}x</td>
                                <td class="px-4 py-2 text-right font-mono {{ $purchase->installment_amount_cents < 0 ? 'text-[var(--in)]' : 'text-[var(--out)]' }}">
                                    R$ {{ number_format($purchase->installment_amount_cents / 100, 2, ',', '.') }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-8 text-center text-[var(--text-dim)]">
                                    Nenhuma compra ainda. <a href="{{ route('import.fatura.show') }}" class="text-[var(--accent)] underline">Importar fatura</a>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        {{ $purchases->links() }}
    </section>
@endsection
