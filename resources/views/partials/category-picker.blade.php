{{--
    Edita a categoria de um lançamento sem sair da lista.

    Espera: $action (rota que recebe o PATCH), $current (id atual ou null),
    $currentName (nome atual ou null) e $categories (CategoryTree::options).

    O <details> mantém o padrão do resto do produto e não depende de JavaScript
    para funcionar — o onchange só poupa um clique de quem tem JS.
--}}
<details class="relative">
    <summary class="list-none cursor-pointer hover:text-[var(--text)] transition-colors w-fit"
        role="button" title="Editar categoria" aria-label="Editar categoria">
        {{ $currentName ?? '—' }}
    </summary>
    <form method="POST" action="{{ $action }}"
        class="absolute left-0 z-10 mt-2 w-64 bg-[var(--surface-1)] border border-[var(--border)] rounded-xl p-3 flex flex-col gap-2 text-left normal-case">
        @csrf
        @method('PATCH')
        <div class="relative">
            <select name="category_id" required onchange="this.form.requestSubmit()"
                class="w-full appearance-none rounded-md bg-[var(--surface-2)] border border-[var(--border)] pl-3 pr-9 py-2 text-sm text-[var(--text)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]">
                {{-- Uma linha por <option>, sem indentação: este bloco se repete em toda
                     linha da tabela, e a indentação sozinha triplicava o tamanho da página. --}}
                <option value="" disabled @selected($current === null)>Selecione…</option>
                @foreach ($categories as $option)<option value="{{ $option['id'] }}" @selected($current === $option['id'])>{{ $option['label'] }}</option>@endforeach
            </select>
            <svg xmlns="http://www.w3.org/2000/svg" class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--text)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
            </svg>
        </div>
        <button type="submit"
            class="rounded-md bg-[var(--accent)] text-[var(--bg)] font-semibold py-1.5 text-sm hover:opacity-90 transition-opacity">
            Salvar
        </button>
    </form>
</details>
