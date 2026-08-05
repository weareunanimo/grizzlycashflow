@extends('layouts.app')

@section('title', 'Bancos — ' . config('app.name'))

@section('content')
    <div class="flex items-center justify-between mb-8">
        <h1 class="text-2xl font-semibold">Bancos</h1>
        <a href="{{ route('accounts.create') }}" class="text-sm text-[var(--accent)] hover:opacity-80 transition-opacity">+ Adicionar</a>
    </div>

    <div class="space-y-4">
        @forelse ($accounts as $account)
            <a href="{{ route('accounts.show', $account->id) }}"
                class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 bg-[var(--surface-1)] border border-[var(--border)] rounded-xl px-4 sm:px-5 py-4 hover:bg-[var(--surface-2)] transition-colors">
                <div class="min-w-0">
                    <p class="font-medium truncate">{{ $account->name }}</p>
                    <p class="text-xs text-[var(--text-dim)] truncate">{{ $account->institution_name }} · {{ $account->type }}</p>
                </div>
                <div class="text-left sm:text-right shrink-0">
                    <p class="font-mono text-sm text-[var(--in)]">+R$ {{ number_format($account->total_in_cents / 100, 2, ',', '.') }}</p>
                    <p class="font-mono text-sm text-[var(--out)]">-R$ {{ number_format($account->total_out_cents / 100, 2, ',', '.') }}</p>
                </div>
            </a>
        @empty
            <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl px-5 py-8 text-center text-sm text-[var(--text-dim)]">
                Nenhuma conta cadastrada. <a href="{{ route('accounts.create') }}" class="text-[var(--accent)] underline">Adicionar</a>
            </div>
        @endforelse
    </div>
@endsection
