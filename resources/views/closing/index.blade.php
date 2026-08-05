@extends('layouts.app')

@section('title', 'Fechamento mensal — ' . config('app.name'))

@section('content')
    <h1 class="text-2xl font-semibold mb-1">Fechamento mensal por categoria</h1>
    <p class="text-sm text-[var(--text-dim)] mb-8">
        Soma dos gastos da conta com as parcelas do cartão (já cobradas e ainda projetadas), mês a mês.
    </p>

    <div class="space-y-3">
        @forelse ($months as $month => $data)
            <details @if ($loop->first) open @endif class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl overflow-hidden group/month">
                <summary class="list-none cursor-pointer px-4 sm:px-5 py-4 flex items-center justify-between hover:bg-[var(--surface-2)] transition-colors">
                    <span class="font-medium">
                        <span aria-hidden="true" class="inline-block transition-transform group-open/month:rotate-90 mr-1">▸</span>
                        {{ \Illuminate\Support\Carbon::parse($month . '-01')->translatedFormat('F/Y') }}
                    </span>
                    <span class="font-mono text-[var(--out)]">R$ {{ number_format($data['total'] / 100, 2, ',', '.') }}</span>
                </summary>
                <div class="border-t border-[var(--border)] divide-y divide-[var(--border)]">
                    @foreach ($data['categories'] as $categoryName => $cents)
                        <div class="flex items-center justify-between px-4 sm:px-5 py-2 text-sm">
                            <span class="text-[var(--text-dim)]">{{ $categoryName }}</span>
                            <span class="font-mono text-[var(--out)]">R$ {{ number_format($cents / 100, 2, ',', '.') }}</span>
                        </div>
                    @endforeach
                </div>
            </details>
        @empty
            <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl px-5 py-8 text-center text-sm text-[var(--text-dim)]">
                Nenhum gasto ainda. Importe um extrato ou fatura para ver o fechamento.
            </div>
        @endforelse
    </div>
@endsection
