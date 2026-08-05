@extends('layouts.app')

@section('title', 'Categorias — ' . config('app.name'))

@section('content')
    <h1 class="text-2xl font-semibold mb-1">Categorias</h1>
    <p class="text-sm text-[var(--text-dim)] mb-6">
        A ordem é automática: primeiro as categorias que têm subcategorias, em ordem alfabética, com as filhas
        logo abaixo; depois as categorias soltas. Criar, renomear ou mudar a categoria-pai já reposiciona tudo.
    </p>

    @if ($errors->any())
        <div class="bg-[var(--surface-1)] border border-[var(--critical)] rounded-xl px-4 py-3 mb-4 text-sm text-[var(--critical)]">
            {{ $errors->first() }}
        </div>
    @endif

    <details class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl mb-6" @if ($errors->has('name') || $errors->has('parent_id')) open @endif>
        <summary class="list-none cursor-pointer px-4 sm:px-5 py-4 text-sm font-medium text-[var(--accent)]">
            + Nova categoria
        </summary>
        <form method="POST" action="{{ route('categories.store') }}"
            class="px-4 sm:px-5 pb-5 flex flex-col sm:flex-row sm:items-end gap-3">
            @csrf
            <div class="flex-1 min-w-0">
                <label for="new-name" class="block text-xs text-[var(--text-dim)] mb-1">Nome</label>
                <input type="text" id="new-name" name="name" required maxlength="80" placeholder="Ex.: Academia"
                    class="w-full rounded-md bg-[var(--surface-2)] border border-[var(--border)] px-3 py-2 text-sm text-[var(--text)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]">
            </div>
            <div class="flex-1 min-w-0">
                <label for="new-parent" class="block text-xs text-[var(--text-dim)] mb-1">Categoria-pai (opcional)</label>
                <div class="relative">
                    <select id="new-parent" name="parent_id"
                        class="w-full appearance-none rounded-md bg-[var(--surface-2)] border border-[var(--border)] pl-3 pr-9 py-2 text-sm text-[var(--text)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]">
                        <option value="">Sem categoria-pai</option>
                        @foreach ($parents as $parent)
                            <option value="{{ $parent->id }}">{{ $parent->name }}</option>
                        @endforeach
                    </select>
                    <svg xmlns="http://www.w3.org/2000/svg" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--text)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                    </svg>
                </div>
            </div>
            <button type="submit"
                class="rounded-md bg-[var(--accent)] text-[var(--bg)] font-semibold px-4 py-2 text-sm hover:opacity-90 transition-opacity whitespace-nowrap">
                Criar
            </button>
        </form>
    </details>

    <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl overflow-hidden">
        <div class="divide-y divide-[var(--border)]">
            @php($independentsStarted = false)
            @forelse ($categories as $category)
                {{-- Separador entre a árvore e as categorias soltas --}}
                @if ($category->depth === 0 && ! $category->is_parent && ! $independentsStarted)
                    @php($independentsStarted = true)
                    @if (! $loop->first)
                        <div class="px-4 sm:px-5 py-2 bg-[var(--bg)] text-xs uppercase tracking-wider text-[var(--text-mute)]">
                            Sem subcategorias
                        </div>
                    @endif
                @endif

                <div class="px-4 sm:px-5 py-3">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                        <div class="min-w-0 flex items-center gap-2" @if ($category->depth > 0) style="padding-left: {{ $category->depth * 20 }}px" @endif>
                            @if ($category->depth > 0)
                                <span class="text-[var(--text-mute)]" aria-hidden="true">└</span>
                            @endif
                            <span class="truncate {{ $category->is_parent ? 'font-medium' : '' }}">{{ $category->name }}</span>
                            @if ($category->is_parent)
                                <span class="text-xs text-[var(--text-mute)] whitespace-nowrap">
                                    {{ $category->child_count }} {{ $category->child_count === 1 ? 'subcategoria' : 'subcategorias' }}
                                </span>
                            @endif
                            @if (($usage[$category->id] ?? 0) > 0)
                                <span class="text-xs text-[var(--text-mute)] whitespace-nowrap">· {{ $usage[$category->id] }} lançamentos</span>
                            @endif
                        </div>

                        <details class="relative shrink-0">
                            <summary class="list-none cursor-pointer text-[var(--text)] hover:opacity-70 transition-opacity w-fit"
                                role="button" aria-label="Editar categoria {{ $category->name }}" title="Editar">
                                @include('partials.icon-edit')
                            </summary>
                            <form method="POST" action="{{ route('categories.update', $category->id) }}"
                                class="absolute right-0 z-10 mt-2 w-72 bg-[var(--surface-1)] border border-[var(--border)] rounded-xl p-4 flex flex-col gap-3">
                                @csrf
                                @method('PATCH')
                                <div>
                                    <label for="name-{{ $category->id }}" class="block text-xs text-[var(--text-dim)] mb-1">Nome</label>
                                    <input type="text" id="name-{{ $category->id }}" name="name" value="{{ $category->name }}" required maxlength="80"
                                        class="w-full rounded-md bg-[var(--surface-2)] border border-[var(--border)] px-3 py-2 text-sm text-[var(--text)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]">
                                </div>
                                <div>
                                    <label for="parent-{{ $category->id }}" class="block text-xs text-[var(--text-dim)] mb-1">Categoria-pai</label>
                                    @if ($category->is_parent)
                                        <p class="text-xs text-[var(--text-mute)]">
                                            Tem subcategorias, então continua no topo. Mova as filhas antes, se quiser aninhá-la.
                                        </p>
                                    @else
                                        <div class="relative">
                                            <select id="parent-{{ $category->id }}" name="parent_id"
                                                class="w-full appearance-none rounded-md bg-[var(--surface-2)] border border-[var(--border)] pl-3 pr-9 py-2 text-sm text-[var(--text)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]">
                                                <option value="">Sem categoria-pai</option>
                                                @foreach ($parents as $parent)
                                                    @if ($parent->id !== $category->id)
                                                        <option value="{{ $parent->id }}" {{ $category->parent_id === $parent->id ? 'selected' : '' }}>
                                                            {{ $parent->name }}
                                                        </option>
                                                    @endif
                                                @endforeach
                                            </select>
                                            <svg xmlns="http://www.w3.org/2000/svg" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--text)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                                            </svg>
                                        </div>
                                    @endif
                                </div>
                                <button type="submit"
                                    class="rounded-md bg-[var(--accent)] text-[var(--bg)] font-semibold py-2 text-sm hover:opacity-90 transition-opacity">
                                    Salvar
                                </button>
                            </form>
                        </details>
                    </div>
                </div>
            @empty
                <div class="px-5 py-8 text-center text-sm text-[var(--text-dim)]">Nenhuma categoria ainda.</div>
            @endforelse
        </div>
    </div>
@endsection
