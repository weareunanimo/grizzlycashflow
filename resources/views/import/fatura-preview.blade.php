@extends('layouts.app')

@section('title', 'Prévia da fatura — ' . config('app.name'))

@section('content')
    <h1 class="text-2xl font-semibold mb-1">Prévia — fatura {{ \Illuminate\Support\Carbon::parse($referenceMonth . '-01')->translatedFormat('F/Y') }}</h1>
    <p class="text-sm text-[var(--text-dim)] mb-6">Confira antes de confirmar. Nada é gravado até você clicar em "Confirmar importação".</p>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6 max-w-lg">
        <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl p-4">
            <p class="text-xs uppercase tracking-wider text-[var(--text-mute)] mb-1">Compras novas</p>
            <p class="text-xl font-semibold text-[var(--in)]">{{ $newPurchases }}</p>
        </div>
        <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl p-4">
            <p class="text-xs uppercase tracking-wider text-[var(--text-mute)] mb-1">Parcelas confirmadas</p>
            <p class="text-xl font-semibold text-[var(--text-dim)]">{{ $confirmed }}</p>
        </div>
        <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl p-4">
            <p class="text-xs uppercase tracking-wider text-[var(--text-mute)] mb-1">Ignorados</p>
            <p class="text-xl font-semibold text-[var(--text-mute)]">{{ $ignored }}</p>
        </div>
    </div>

    <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl overflow-hidden mb-6">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[640px] text-sm">
                <thead>
                    <tr class="border-b border-[var(--border)] text-left text-xs uppercase tracking-wider text-[var(--text-mute)]">
                        <th class="px-4 py-3">Compra</th>
                        <th class="px-4 py-3">Estabelecimento</th>
                        <th class="px-4 py-3">Valor</th>
                        <th class="px-4 py-3">Parcela</th>
                        <th class="px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border)]">
                    @foreach ($rows as $row)
                        <tr class="{{ $row['decision'] === 'ignorado' ? 'opacity-40' : '' }}">
                            <td class="px-4 py-[3px] whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($row['purchase_date'])->format('d/m/Y') }}</td>
                            <td class="px-4 py-[3px] whitespace-nowrap">{{ \Grizzly\Domain\Classification\MerchantDisplayName::forRawDescription($row['description']) }}</td>
                            <td class="px-4 py-[3px] font-mono {{ $row['amount']->isNegative() ? 'text-[var(--in)]' : 'text-[var(--out)]' }}">
                                {{ $row['amount']->formatBrl() }}
                            </td>
                            <td class="px-4 py-[3px] text-[var(--text-dim)]">
                                @if ($row['installment_number'])
                                    {{ $row['installment_number'] }}/{{ $row['installment_total'] }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-[3px] text-xs">
                                @if ($row['decision'] === 'nova_compra')
                                    <span class="text-[var(--in)]">nova compra</span>
                                @elseif ($row['decision'] === 'confirma_projecao')
                                    <span class="text-[var(--accent)]">confirma projeção</span>
                                @else
                                    <span class="text-[var(--text-mute)]">pagamento (via extrato)</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <form method="POST" action="{{ route('import.fatura.commit') }}">
        @csrf
        <button type="submit"
            class="rounded-md bg-[var(--accent)] text-[var(--bg)] font-semibold px-5 py-2 text-sm hover:opacity-90 transition-opacity">
            Confirmar importação
        </button>
        <a href="{{ route('import.index') }}" class="ml-3 text-sm text-[var(--text-dim)] hover:text-[var(--text)]">Cancelar</a>
    </form>
@endsection
