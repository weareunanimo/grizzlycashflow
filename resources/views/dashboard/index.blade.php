@extends('layouts.app')

@section('title', 'Dashboard — ' . config('app.name', 'Grizzly Cashflow'))

@section('content')
    <h1 class="text-2xl font-semibold mb-8">Olá, {{ $user->name }}</h1>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-10">
        @foreach ([
            ['label' => 'Contas', 'value' => $accounts->count(), 'route' => route('banks.index')],
            ['label' => 'Cartões', 'value' => $creditCards->count() + $benefitCards->count(), 'route' => route('cards.index')],
            ['label' => 'Categorias', 'value' => $categoryCount, 'route' => route('closing.index')],
            ['label' => 'Regras de categorização', 'value' => $ruleCount, 'route' => route('review.index')],
        ] as $card)
            <a href="{{ $card['route'] }}"
                class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl p-5 hover:bg-[var(--surface-2)] hover:border-[var(--text-mute)] transition-colors">
                <p class="text-xs uppercase tracking-wider text-[var(--text-mute)] mb-1">{{ $card['label'] }}</p>
                <p class="text-2xl font-semibold">{{ $card['value'] }}</p>
            </a>
        @endforeach
    </div>

    <section class="mb-10 pt-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-sm font-semibold text-[var(--text-dim)] uppercase tracking-wider">Contas</h2>
            <a href="{{ route('accounts.create') }}" class="text-sm text-[var(--accent)] hover:opacity-80 transition-opacity">+ Adicionar</a>
        </div>
        <div class="space-y-4">
            @foreach ($accounts as $account)
                <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl overflow-hidden">
                    <a href="{{ route('banks.index', ['account' => $account->id]) }}" class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 px-4 sm:px-5 py-4 hover:bg-[var(--surface-2)] transition-colors">
                        <div class="min-w-0">
                            <p class="font-medium truncate">{{ $account->name }}</p>
                            <p class="text-xs text-[var(--text-dim)] truncate">{{ $account->institution_name }} · {{ $account->type }}</p>
                        </div>
                        <div class="text-left sm:text-right shrink-0">
                            <p class="font-mono text-sm text-[var(--in)]">+R$ {{ number_format($account->total_in_cents / 100, 2, ',', '.') }}</p>
                            <p class="font-mono text-sm text-[var(--out)]">-R$ {{ number_format($account->total_out_cents / 100, 2, ',', '.') }}</p>
                        </div>
                    </a>
                    @if ($account->recent->isNotEmpty())
                        <div class="border-t border-[var(--border)] divide-y divide-[var(--border)]">
                            @foreach ($account->recent as $tx)
                                <div class="flex items-center justify-between gap-3 px-4 sm:px-5 py-2.5 text-sm">
                                    <div class="flex items-baseline gap-3 min-w-0 flex-1">
                                        <span class="text-xs text-[var(--text-mute)] shrink-0">{{ \Illuminate\Support\Carbon::parse($tx->occurred_on)->format('d/m') }}</span>
                                        <span class="truncate text-[var(--text-dim)]">{{ \Grizzly\Domain\Classification\DescriptionCleaner::forDisplay($tx->description) }}</span>
                                    </div>
                                    <span class="font-mono shrink-0 {{ $tx->direction === 'out' ? 'text-[var(--out)]' : 'text-[var(--in)]' }}">
                                        {{ $tx->direction === 'out' ? '-' : '' }}R$ {{ number_format($tx->amount_cents / 100, 2, ',', '.') }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    <section class="pt-6">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-sm font-semibold text-[var(--text-dim)] uppercase tracking-wider">Cartões de crédito</h2>
            <a href="{{ route('accounts.create') }}" class="text-sm text-[var(--accent)] hover:opacity-80 transition-opacity">+ Adicionar</a>
        </div>
        <div class="space-y-4">
            @forelse ($creditCards as $card)
                <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl overflow-hidden">
                    <a href="{{ route('cards.index', ['card' => 'c' . $card->id]) }}" class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 px-4 sm:px-5 py-4 hover:bg-[var(--surface-2)] transition-colors">
                        <div class="min-w-0">
                            <p class="font-medium truncate">{{ $card->account_name }}</p>
                            <p class="text-xs text-[var(--text-dim)] truncate">
                                {{ ucfirst((string) $card->brand) }} · fecha dia {{ $card->closing_day }}, vence dia {{ $card->due_day }}
                            </p>
                        </div>
                        <div class="text-left sm:text-right shrink-0">
                            <p class="text-xs text-[var(--text-mute)] uppercase tracking-wider">Total em aberto</p>
                            <p class="font-mono text-sm text-[var(--out)]">R$ {{ number_format($card->open_total_cents / 100, 2, ',', '.') }}</p>
                        </div>
                    </a>
                    @if ($card->recent->isNotEmpty())
                        <div class="border-t border-[var(--border)] divide-y divide-[var(--border)]">
                            @foreach ($card->recent as $purchase)
                                <div class="flex items-center justify-between gap-3 px-4 sm:px-5 py-2.5 text-sm">
                                    <div class="flex items-baseline gap-3 min-w-0 flex-1">
                                        <span class="text-xs text-[var(--text-mute)] shrink-0">
                                            {{ $purchase->purchase_date ? \Illuminate\Support\Carbon::parse($purchase->purchase_date)->format('d/m') : '—' }}
                                        </span>
                                        <span class="truncate text-[var(--text-dim)]">{{ $purchase->display_name }}</span>
                                    </div>
                                    <span class="font-mono shrink-0 text-[var(--out)]">
                                        R$ {{ number_format($purchase->installment_amount_cents / 100, 2, ',', '.') }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                @if ($benefitCards->isEmpty())
                    <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl px-4 sm:px-5 py-4 text-sm text-[var(--text-dim)]">
                        Nenhum cartão cadastrado.
                    </div>
                @endif
            @endforelse
        </div>
    </section>

    @if ($benefitCards->isNotEmpty())
        <section class="pt-6">
            <h2 class="text-sm font-semibold text-[var(--text-dim)] uppercase tracking-wider mb-4">Cartões de benefícios</h2>
            <div class="space-y-4">
                @foreach ($benefitCards as $card)
                    <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl overflow-hidden">
                        <a href="{{ route('cards.index', ['card' => 'v' . $card->id]) }}" class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 px-4 sm:px-5 py-4 hover:bg-[var(--surface-2)] transition-colors">
                            <div class="min-w-0">
                                <p class="font-medium truncate">{{ $card->name }}</p>
                                <p class="text-xs text-[var(--text-dim)] truncate">{{ $card->institution_name }} · benefícios</p>
                            </div>
                            <div class="text-left sm:text-right shrink-0">
                                <p class="text-xs text-[var(--text-mute)] uppercase tracking-wider">Saldo</p>
                                <p class="font-mono text-sm {{ $card->balance_cents < 0 ? 'text-[var(--out)]' : 'text-[var(--in)]' }}">R$ {{ number_format($card->balance_cents / 100, 2, ',', '.') }}</p>
                            </div>
                        </a>
                        @if ($card->recent->isNotEmpty())
                            <div class="border-t border-[var(--border)] divide-y divide-[var(--border)]">
                                @foreach ($card->recent as $tx)
                                    <div class="flex items-center justify-between gap-3 px-4 sm:px-5 py-2.5 text-sm">
                                        <div class="flex items-baseline gap-3 min-w-0 flex-1">
                                            <span class="text-xs text-[var(--text-mute)] shrink-0">{{ \Illuminate\Support\Carbon::parse($tx->occurred_on)->format('d/m') }}</span>
                                            <span class="truncate text-[var(--text-dim)]">{{ \Grizzly\Domain\Classification\DescriptionCleaner::forDisplay($tx->description) }}</span>
                                        </div>
                                        <span class="font-mono shrink-0 {{ $tx->direction === 'out' ? 'text-[var(--out)]' : 'text-[var(--in)]' }}">
                                            {{ $tx->direction === 'out' ? '-' : '' }}R$ {{ number_format($tx->amount_cents / 100, 2, ',', '.') }}
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endif
@endsection
