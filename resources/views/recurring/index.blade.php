@extends('layouts.app')

@section('title', 'Recorrentes — ' . config('app.name'))

@php
    $brl = fn (int $cents) => 'R$ ' . number_format($cents / 100, 2, ',', '.');
    $monthLabel = fn (string $ym) => \Illuminate\Support\Carbon::parse($ym . '-01')->translatedFormat('M/y');
@endphp

@section('content')
    <h1 class="text-2xl font-semibold mb-1">Cobranças recorrentes</h1>
    <p class="text-sm text-[var(--text-dim)] mb-6">
        Streaming, celular, internet, contas de consumo e assinaturas encontradas no extrato da conta e na fatura do cartão,
        cobradas em pelo menos {{ $minMonths }} meses seguidos.
    </p>

    <form method="GET" action="{{ route('recurring.index') }}" class="flex flex-wrap items-center gap-2 mb-8 text-sm">
        <label for="meses" class="text-[var(--text-dim)]">Repetiu em pelo menos</label>
        <select id="meses" name="meses" onchange="this.form.submit()"
            class="rounded-md bg-[var(--surface-2)] border border-[var(--border)] px-3 py-1.5 text-[var(--text)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]">
            @foreach ([2, 3, 4, 6, 12] as $option)
                <option value="{{ $option }}" @selected($option === $minMonths)>{{ $option }} meses seguidos</option>
            @endforeach
        </select>
        <noscript><button type="submit" class="rounded-md bg-[var(--accent)] text-[var(--bg)] font-semibold px-3 py-1.5">Filtrar</button></noscript>
    </form>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-8">
        <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl px-5 py-4">
            <p class="text-xs text-[var(--text-mute)] uppercase tracking-wider">Serviços ativos</p>
            <p class="text-2xl font-mono mt-1 text-[var(--out)]">{{ $brl($servicesMonthlyCents) }}<span class="text-sm text-[var(--text-dim)]">/mês</span></p>
            <p class="text-xs text-[var(--text-dim)] mt-1">{{ $servicesCount }} {{ $servicesCount === 1 ? 'serviço' : 'serviços' }} · média mensal</p>
        </div>
        <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl px-5 py-4">
            <p class="text-xs text-[var(--text-mute)] uppercase tracking-wider">Outras cobranças fixas</p>
            <p class="text-2xl font-mono mt-1 text-[var(--out)]">{{ $brl($othersMonthlyCents) }}<span class="text-sm text-[var(--text-dim)]">/mês</span></p>
            <p class="text-xs text-[var(--text-dim)] mt-1">mesmo valor todo mês (escola, financiamento, condomínio...)</p>
        </div>
    </div>

    @php
        $sections = [];
        foreach ($kindLabels as $kind => $label) {
            if (! empty($byKind[$kind])) {
                $sections[] = ['label' => $label, 'items' => $byKind[$kind]];
            }
        }
        if ($others !== []) {
            $sections[] = ['label' => $kindLabels['outros'], 'items' => $others];
        }
    @endphp

    <div class="space-y-6">
        @forelse ($sections as $section)
            <section>
                <h2 class="text-sm font-semibold text-[var(--text-dim)] uppercase tracking-wider mb-2">{{ $section['label'] }}</h2>
                <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl divide-y divide-[var(--border)]">
                    @foreach ($section['items'] as $item)
                        <div class="px-4 sm:px-5 py-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1 {{ $item['active'] ? '' : 'opacity-60' }}">
                            <div class="min-w-0">
                                <p class="font-medium truncate">
                                    {{ $item['name'] }}
                                    @unless ($item['active'])
                                        <span class="text-xs font-normal text-[var(--text-mute)]">· sem cobrança recente</span>
                                    @endunless
                                </p>
                                <p class="text-xs text-[var(--text-dim)]">
                                    {{ implode(' + ', array_map(fn ($c) => $c === 'cartao' ? 'Cartão' : 'Conta', $item['channels'])) }}
                                    · {{ count($item['months']) }} {{ count($item['months']) === 1 ? 'mês' : 'meses' }}
                                    ({{ $monthLabel($item['months'][0]) }} – {{ $monthLabel(end($item['months'])) }})
                                    · última em {{ \Illuminate\Support\Carbon::parse($item['last_date'])->format('d/m/Y') }}
                                </p>
                            </div>
                            <div class="sm:text-right shrink-0">
                                <p class="font-mono text-[var(--out)]">{{ $brl($item['avg_monthly_cents']) }}<span class="text-xs text-[var(--text-dim)]">/mês</span></p>
                                @if ($item['min_cents'] !== $item['max_cents'])
                                    <p class="text-xs text-[var(--text-dim)] font-mono">{{ $brl($item['min_cents']) }} a {{ $brl($item['max_cents']) }}</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @empty
            <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl px-5 py-8 text-center text-sm text-[var(--text-dim)]">
                Nenhuma cobrança se repetiu em {{ $minMonths }} meses seguidos ainda.
                Importe mais extratos e faturas em <a href="{{ route('import.index') }}" class="text-[var(--accent)] underline">Importar</a>.
            </div>
        @endforelse
    </div>
@endsection
