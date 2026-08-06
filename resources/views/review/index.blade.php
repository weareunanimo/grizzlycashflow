@extends('layouts.app')

@section('title', 'Revisar categorias — ' . config('app.name'))

@section('content')
    <h1 class="text-2xl font-semibold mb-1">Revisar categorias</h1>
    <p class="text-sm text-[var(--text-dim)] mb-6">
        Nenhuma regra reconheceu esses lançamentos com confiança. Confirme a sugestão ou escolha outra categoria —
        a decisão vale para todo lançamento do mesmo estabelecimento e vira uma regra para as próximas importações.
        Falta alguma categoria? <a href="{{ route('categories.index') }}" class="text-[var(--accent)] underline">Crie em Categorias</a>.
    </p>

    @if ($accounts->isNotEmpty() || $cards->isNotEmpty())
        <form method="GET" action="{{ route('review.index') }}"
            class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl p-4 sm:p-5 mb-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-5">
                @if ($accounts->isNotEmpty())
                    <fieldset class="min-w-0">
                        <legend class="text-xs uppercase tracking-wider text-[var(--text-mute)] mb-2">Contas</legend>
                        <div class="flex flex-wrap gap-x-5 gap-y-2">
                            @foreach ($accounts as $account)
                                <label class="flex items-center gap-2 text-sm min-w-0">
                                    <input type="checkbox" name="accounts[]" value="{{ $account->id }}"
                                        {{ in_array((int) $account->id, $selectedAccountIds, true) ? 'checked' : '' }}
                                        class="shrink-0 rounded border-[var(--border)] bg-[var(--surface-2)] text-[var(--accent)] focus:ring-[var(--accent)]">
                                    <span class="truncate">{{ $account->name }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endif

                @if ($cards->isNotEmpty())
                    <fieldset class="min-w-0">
                        <legend class="text-xs uppercase tracking-wider text-[var(--text-mute)] mb-2">Cartões de crédito e de benefícios</legend>
                        <div class="flex flex-wrap gap-x-5 gap-y-2">
                            @foreach ($cards as $card)
                                <label class="flex items-center gap-2 text-sm min-w-0">
                                    <input type="checkbox" name="cards[]" value="{{ $card->key }}"
                                        {{ in_array($card->key, $selectedCardKeys, true) ? 'checked' : '' }}
                                        class="shrink-0 rounded border-[var(--border)] bg-[var(--surface-2)] text-[var(--accent)] focus:ring-[var(--accent)]">
                                    <span class="truncate">{{ $card->name }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endif
            </div>

            <div class="flex flex-wrap items-center gap-3 mt-5 pt-4 border-t border-[var(--border)]">
                <button type="submit"
                    class="rounded-md bg-[var(--accent)] text-[var(--bg)] font-semibold px-4 py-2 text-sm hover:opacity-90 transition-opacity">
                    Filtrar
                </button>
                @if ($selectedAccountIds !== [] || $selectedCardKeys !== [])
                    <a href="{{ route('review.index') }}" class="text-sm text-[var(--text-dim)] underline">Limpar filtros</a>
                @endif
                <span class="text-xs text-[var(--text-mute)] ml-auto">
                    {{ $pending->total() }} {{ $pending->total() === 1 ? 'estabelecimento a revisar' : 'estabelecimentos a revisar' }}
                </span>
            </div>
        </form>
    @endif

    @if ($errors->any())
        <div class="bg-[var(--surface-1)] border border-[var(--critical)] rounded-xl px-4 py-3 mb-4 text-sm text-[var(--critical)]">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="space-y-3">
        @forelse ($pending as $row)
            @php
                $key = $row->kind . '-' . $row->id;
                $suggestion = $suggestions[$key] ?? ['category_id' => null, 'reason' => ''];
                $suggestedId = $suggestion['category_id'];
                $suggestedName = $suggestedId !== null ? ($categoryNames[$suggestedId] ?? null) : null;
            @endphp
            <form method="POST"
                action="{{ route('review.store', ['kind' => $row->kind, 'id' => $row->id, 'accounts' => $selectedAccountIds, 'cards' => $selectedCardKeys]) }}"
                class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl p-4">
                @csrf

                <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                    <div class="min-w-0 lg:flex-1">
                        <p class="font-medium break-words">
                            {{ $row->display_name }}
                            <span class="text-xs text-[var(--text-mute)] font-normal">{{ $row->kind === 'bank' ? '· banco' : '· cartão' }}</span>
                        </p>
                        <p class="text-xs text-[var(--text-dim)] mt-0.5">
                            {{ \Illuminate\Support\Carbon::parse($row->date)->format('d/m/Y') }} ·
                            <span class="font-mono {{ $row->amount_cents < 0 ? 'text-[var(--out)]' : 'text-[var(--in)]' }}">
                                R$ {{ number_format($row->amount_cents / 100, 2, ',', '.') }}
                            </span>
                            @if ($row->group_count > 1)
                                · <span class="text-[var(--warn)]">{{ $row->group_count }} lançamentos deste estabelecimento</span>,
                                somando <span class="font-mono">R$ {{ number_format(abs($row->group_total_cents) / 100, 2, ',', '.') }}</span>
                            @endif
                        </p>

                        @if ($suggestedName)
                            <p class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-[var(--surface-2)] border border-[var(--border)] px-2.5 py-1">
                                    <span class="inline-block w-1.5 h-1.5 rounded-full bg-[var(--accent)]" aria-hidden="true"></span>
                                    <span class="text-[var(--text-mute)] uppercase tracking-wider">Sugestão</span>
                                    <span class="font-medium text-[var(--text)]">{{ $suggestedName }}</span>
                                </span>
                                <span class="text-[var(--text-mute)]">porque {{ $suggestion['reason'] }}</span>
                            </p>
                        @else
                            <p class="mt-2 text-xs text-[var(--text-mute)]">Sem sugestão — nenhum lançamento parecido foi classificado ainda.</p>
                        @endif
                    </div>

                    <div class="flex flex-col sm:flex-row sm:items-center gap-2 lg:shrink-0">
                        <div class="relative w-full sm:w-64">
                            <select name="category_id" aria-label="Categoria"
                                class="w-full appearance-none rounded-md bg-[var(--surface-2)] border border-[var(--border)] pl-3 pr-9 py-2 text-sm text-[var(--text)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]">
                                <option value="" {{ $suggestedId === null ? 'selected' : '' }}>Selecione…</option>
                                @foreach ($categories as $option)
                                    <option value="{{ $option['id'] }}" {{ $suggestedId === $option['id'] ? 'selected' : '' }}>
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
                            {{ $suggestedName ? 'Aprovar' : 'Confirmar' }}
                        </button>
                    </div>
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
