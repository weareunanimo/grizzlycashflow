@extends('layouts.app')

@section('title', 'Cartões — ' . config('app.name'))

@section('content')
    <div class="flex items-center justify-between mb-8">
        <h1 class="text-2xl font-semibold">Cartões de crédito</h1>
        <a href="{{ route('accounts.create') }}" class="text-sm text-[var(--accent)] hover:opacity-80 transition-opacity">+ Adicionar</a>
    </div>

    <div class="space-y-4">
        @forelse ($cards as $card)
            <a href="{{ route('cards.show', $card->id) }}"
                class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 bg-[var(--surface-1)] border border-[var(--border)] rounded-xl px-4 sm:px-5 py-4 hover:bg-[var(--surface-2)] transition-colors">
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
        @empty
            <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl px-5 py-8 text-center text-sm text-[var(--text-dim)]">
                Nenhum cartão cadastrado. <a href="{{ route('accounts.create') }}" class="text-[var(--accent)] underline">Adicionar</a>
            </div>
        @endforelse
    </div>
@endsection
