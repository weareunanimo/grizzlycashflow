@extends('layouts.app')

@section('title', 'Revisar categorias — ' . config('app.name'))

@section('content')
    <h1 class="text-2xl font-semibold mb-1">Revisar categorias</h1>
    <p class="text-sm text-[var(--text-dim)] mb-6">
        Nenhuma regra reconheceu esses lançamentos com confiança. Confirme a sugestão ou escolha outra categoria —
        a decisão vale para todo lançamento igual pendente e vira uma regra para as próximas importações.
    </p>

    <div class="inline-flex gap-[1px] mb-6 text-sm bg-[var(--surface-1)] border border-[var(--border)] rounded-lg p-1">
        @foreach (['all' => 'Todos', 'bank' => 'Bancos', 'card' => 'Cartões'] as $key => $label)
            <a href="{{ route('review.index', ['type' => $key]) }}"
                class="px-4 py-2 rounded-md transition-colors {{ $type === $key ? 'bg-[var(--surface-2)] text-[var(--text)] font-medium' : 'text-[var(--text-dim)] hover:text-[var(--text)]' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    @if ($accounts->isNotEmpty() || $cards->isNotEmpty())
        <form method="GET" action="{{ route('review.index') }}"
            class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl p-4 mb-6 flex flex-wrap items-start gap-6">
            <input type="hidden" name="type" value="{{ $type }}">

            @if ($accounts->isNotEmpty())
                <div>
                    <p class="text-xs uppercase tracking-wider text-[var(--text-mute)] mb-2">Contas</p>
                    <div class="flex flex-col gap-1">
                        @foreach ($accounts as $account)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="accounts[]" value="{{ $account->id }}"
                                    {{ in_array($account->id, $selectedAccountIds, true) ? 'checked' : '' }}
                                    class="rounded border-[var(--border)] bg-[var(--surface-2)] text-[var(--accent)] focus:ring-[var(--accent)]">
                                {{ $account->name }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($cards->isNotEmpty())
                <div>
                    <p class="text-xs uppercase tracking-wider text-[var(--text-mute)] mb-2">Cartões</p>
                    <div class="flex flex-col gap-1">
                        @foreach ($cards as $card)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="cards[]" value="{{ $card->id }}"
                                    {{ in_array($card->id, $selectedCardIds, true) ? 'checked' : '' }}
                                    class="rounded border-[var(--border)] bg-[var(--surface-2)] text-[var(--accent)] focus:ring-[var(--accent)]">
                                {{ $card->account_name }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="self-end">
                <button type="submit"
                    class="rounded-md bg-[var(--accent)] text-[var(--bg)] font-semibold px-4 py-2 text-sm hover:opacity-90 transition-opacity whitespace-nowrap">
                    Filtrar
                </button>
                @if ($selectedAccountIds !== [] || $selectedCardIds !== [])
                    <a href="{{ route('review.index', ['type' => $type]) }}" class="ml-2 text-sm text-[var(--text-dim)] underline">Limpar</a>
                @endif
            </div>
        </form>
    @endif

    <div class="space-y-3">
        @forelse ($pending as $row)
            @php($key = $row->kind . '-' . $row->id)
            <form method="POST" action="{{ route('review.store', ['kind' => $row->kind, 'id' => $row->id, 'type' => $type, 'accounts' => $selectedAccountIds, 'cards' => $selectedCardIds]) }}"
                class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl p-4 flex flex-col sm:flex-row sm:items-center gap-3">
                @csrf
                <div class="flex-1 min-w-0">
                    <p class="font-medium truncate">
                        {{ \Grizzly\Domain\Classification\DescriptionCleaner::forDisplay($row->description) }}
                        <span class="text-xs text-[var(--text-mute)] font-normal">{{ $row->kind === 'bank' ? '· banco' : '· cartão' }}</span>
                    </p>
                    <p class="text-xs text-[var(--text-dim)]">
                        {{ \Illuminate\Support\Carbon::parse($row->date)->format('d/m/Y') }} ·
                        <span class="font-mono {{ $row->amount_cents < 0 ? 'text-[var(--out)]' : 'text-[var(--in)]' }}">
                            R$ {{ number_format($row->amount_cents / 100, 2, ',', '.') }}
                        </span>
                    </p>
                </div>

                <div class="flex items-center gap-2">
                    @if ($suggestions[$key] ?? null)
                        <span class="text-xs text-[var(--text-mute)] whitespace-nowrap">Sugestão aplicada, troque se quiser:</span>
                    @else
                        <span class="text-xs text-[var(--text-mute)] whitespace-nowrap">Outra categoria:</span>
                    @endif
                    <div class="relative">
                        <select name="category_id" required
                            class="appearance-none rounded-md bg-[var(--surface-2)] border border-[var(--border)] pl-3 pr-9 py-2 text-sm text-[var(--text)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]">
                            <option value="" disabled {{ !($suggestions[$key] ?? null) ? 'selected' : '' }}>Selecione…</option>
                            @foreach ($categories as $option)
                                <option value="{{ $option['id'] }}" {{ ($suggestions[$key] ?? null) === $option['id'] ? 'selected' : '' }}>
                                    {{ $option['label'] }}
                                </option>
                            @endforeach
                        </select>
                        <svg xmlns="http://www.w3.org/2000/svg" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--text)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                        </svg>
                    </div>
                    <button type="submit"
                        class="rounded-md bg-[var(--accent)] text-[var(--bg)] font-semibold px-4 py-2 text-sm hover:opacity-90 transition-opacity whitespace-nowrap">
                        Confirmar
                    </button>
                </div>
            </form>
        @empty
            <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl px-5 py-8 text-center text-sm text-[var(--text-dim)]">
                Nada pendente — tudo categorizado. 🎉
            </div>
        @endforelse
    </div>

    {{ $pending->links() }}
@endsection
