@extends('layouts.app')

@section('title', $account->name . ' — ' . config('app.name'))

@section('content')
    <h1 class="text-2xl font-semibold mb-1">{{ $account->name }}</h1>
    <p class="text-sm text-[var(--text-dim)] mb-8">{{ $account->institution_name }} · {{ $account->type }}</p>

    <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl overflow-hidden mb-4">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-[var(--border)] text-left text-xs uppercase tracking-wider text-[var(--text-mute)]">
                        <th class="px-4 py-3">Data</th>
                        <th class="px-4 py-3">Descrição</th>
                        <th class="px-4 py-3">Categoria</th>
                        <th class="px-4 py-3 text-right">Valor</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border)]">
                    @forelse ($transactions as $tx)
                        <tr>
                            <td class="px-4 py-2.5 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($tx->occurred_on)->format('d/m/Y') }}</td>
                            <td class="px-4 py-2.5">
                                {{ $tx->description }}
                                @if ($tx->needs_review)
                                    <span class="ml-1 text-xs text-[var(--warn)]">revisar</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-[var(--text-dim)]">{{ $tx->category_name ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-right font-mono {{ $tx->direction === 'out' ? 'text-[var(--out)]' : 'text-[var(--in)]' }}">
                                {{ $tx->direction === 'out' ? '-' : '' }}R$ {{ number_format($tx->amount_cents / 100, 2, ',', '.') }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center text-[var(--text-dim)]">
                                Nenhum lançamento ainda. <a href="{{ route('import.conta.show') }}" class="text-[var(--accent)] underline">Importar extrato</a>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{ $transactions->links() }}
@endsection
