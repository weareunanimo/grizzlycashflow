@extends('layouts.app')

@section('title', 'Bancos — ' . config('app.name'))

@section('content')
    <h1 class="text-2xl font-semibold mb-6">Bancos</h1>

    @if ($accounts->isEmpty())
        <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl px-5 py-8 text-center text-sm text-[var(--text-dim)]">
            Nenhuma conta cadastrada. <a href="{{ route('accounts.create') }}" class="text-[var(--accent)] underline">Adicionar</a>
        </div>
    @else
        {{-- As ações vivem dentro da aba ativa: editar e excluir sempre se referem
             à conta aberta, então ficam ao lado do nome dela. --}}
        <div class="flex flex-wrap items-center gap-[1px] mb-6 text-sm bg-[var(--surface-1)] border border-[var(--border)] rounded-lg p-1 w-fit max-w-full">
            @foreach ($accounts as $account)
                @if ($selected && (int) $selected->id === (int) $account->id)
                    <span class="flex items-center gap-2 pl-4 pr-3 py-2 rounded-md bg-[var(--surface-2)] text-[var(--text)] font-medium whitespace-nowrap">
                        {{ $account->name }}
                        <details class="relative">
                            <summary class="list-none cursor-pointer flex text-[var(--text)] hover:opacity-70 transition-opacity"
                                role="button" aria-label="Editar nome da conta" title="Editar nome">
                                @include('partials.icon-edit')
                            </summary>
                            <form method="POST" action="{{ route('banks.update', $selected->id) }}"
                                class="absolute left-0 z-10 mt-2 w-72 bg-[var(--surface-1)] border border-[var(--border)] rounded-xl p-4 flex flex-col gap-2 font-normal">
                                @csrf
                                @method('PATCH')
                                <label for="bank-name" class="text-xs text-[var(--text-dim)]">Nome da conta</label>
                                <input type="text" id="bank-name" name="name" value="{{ $selected->name }}" required maxlength="120"
                                    class="w-full rounded-md bg-[var(--surface-2)] border border-[var(--border)] px-3 py-2 text-sm text-[var(--text)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]">
                                <button type="submit"
                                    class="rounded-md bg-[var(--accent)] text-[var(--bg)] font-semibold py-2 text-sm hover:opacity-90 transition-opacity">
                                    Salvar
                                </button>
                            </form>
                        </details>
                        <form method="POST" action="{{ route('banks.destroy', $selected->id) }}"
                            onsubmit="return confirm('Tem certeza que deseja excluir a conta \'{{ $selected->name }}\'? Todos os lançamentos relacionados a ela serão apagados permanentemente. Essa ação não pode ser desfeita.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="flex text-[var(--text)] hover:opacity-70 transition-opacity"
                                aria-label="Excluir conta" title="Excluir conta">
                                @include('partials.icon-trash')
                            </button>
                        </form>
                    </span>
                @else
                    <a href="{{ route('banks.index', ['account' => $account->id]) }}"
                        class="px-4 py-2 rounded-md transition-colors whitespace-nowrap text-[var(--text-dim)] hover:text-[var(--text)]">
                        {{ $account->name }}
                    </a>
                @endif
            @endforeach

            <a href="{{ route('accounts.create') }}"
                class="px-4 py-2 rounded-md transition-colors whitespace-nowrap text-[var(--accent)] hover:opacity-80">
                + Adicionar
            </a>
        </div>

        @if ($selected)
            <div class="mb-4">
                <p class="text-sm text-[var(--text-dim)]">{{ $selected->institution_name }} · {{ $selected->type }}</p>
                <p class="text-sm mt-1">
                    <span class="font-mono text-[var(--in)]">+R$ {{ number_format($selected->total_in_cents / 100, 2, ',', '.') }}</span>
                    <span class="font-mono text-[var(--out)] ml-3">-R$ {{ number_format($selected->total_out_cents / 100, 2, ',', '.') }}</span>
                </p>
            </div>

            @error('name')
                <p class="text-sm text-[var(--critical)] mb-4">{{ $message }}</p>
            @enderror

            <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl overflow-hidden mb-4">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-sm">
                        <thead>
                            <tr class="border-b border-[var(--border)] text-left text-xs uppercase tracking-wider text-[var(--text-mute)]">
                                <th>Data</th>
                                <th>Descrição</th>
                                <th>Categoria</th>
                                <th>Valor</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--border)]">
                            @forelse ($rows as $row)
                                @if ($row->type === 'tx')
                                    @php($tx = $row->data)
                                    <tr>
                                        <td class="whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($tx->occurred_on)->format('d/m/Y') }}</td>
                                        <td class="whitespace-nowrap">
                                            {{ \Grizzly\Domain\Classification\DescriptionCleaner::forDisplay($tx->description) }}
                                            @if (\App\Support\ReviewQueue::isPending($tx))
                                                @include('partials.review-dot')
                                            @endif
                                        </td>
                                        <td class="text-[var(--text-dim)]">
                                            @include('partials.category-picker', [
                                                'action' => route('transactions.category', $tx->id),
                                                'current' => $tx->category_id === null ? null : (int) $tx->category_id,
                                                'currentName' => $tx->category_name,
                                            ])
                                        </td>
                                        <td class="font-mono {{ $tx->direction === 'out' ? 'text-[var(--out)]' : 'text-[var(--in)]' }}">
                                            {{ $tx->direction === 'out' ? '-' : '' }}R$ {{ number_format($tx->amount_cents / 100, 2, ',', '.') }}
                                        </td>
                                    </tr>
                                @else
                                    @php($group = $row->data)
                                    <tr>
                                        <td colspan="4" class="p-0">
                                            <details class="group/rendimento">
                                                <summary class="list-none cursor-pointer px-3 sm:px-5 py-2.5 flex items-center gap-4 hover:bg-[var(--surface-2)] transition-colors">
                                                    <span class="text-[var(--text-dim)]">
                                                        <span aria-hidden="true" class="inline-block transition-transform group-open/rendimento:rotate-90 mr-1">▸</span>
                                                        Rendimento automático · {{ \Illuminate\Support\Carbon::parse($group->month . '-01')->translatedFormat('M/Y') }}
                                                        · {{ $group->count }} {{ $group->count === 1 ? 'lançamento' : 'lançamentos' }}
                                                    </span>
                                                    <span class="font-mono text-[var(--in)]">R$ {{ number_format($group->total_cents / 100, 2, ',', '.') }}</span>
                                                </summary>
                                                <div class="max-h-48 overflow-y-auto border-t border-[var(--border)] divide-y divide-[var(--border)]">
                                                    @foreach ($group->items as $item)
                                                        <div class="flex items-center gap-4 px-3 sm:px-5 py-2 pl-9 text-xs">
                                                            <span class="text-[var(--text-mute)]">{{ \Illuminate\Support\Carbon::parse($item->occurred_on)->format('d/m/Y') }} — {{ $item->description }}</span>
                                                            <span class="font-mono text-[var(--in)]">R$ {{ number_format($item->amount_cents / 100, 2, ',', '.') }}</span>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </details>
                                        </td>
                                    </tr>
                                @endif
                            @empty
                                <tr>
                                    <td colspan="4" class="px-4 py-8 text-center text-[var(--text-dim)]">
                                        Nenhum lançamento ainda. <a href="{{ route('import.index') }}" class="text-[var(--accent)] underline">Importar extrato</a>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            {{ $rows->links() }}
        @endif
    @endif
@endsection
