@extends('layouts.app')

@section('title', $account->name . ' — ' . config('app.name'))

@section('content')
    <h1 class="text-2xl font-semibold mb-1">{{ $account->name }}</h1>
    <p class="text-sm text-[var(--text-dim)] mb-8">{{ $account->institution_name }} · {{ $account->type }}</p>

    <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl overflow-hidden mb-4">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[640px] text-sm">
                <thead>
                    <tr class="border-b border-[var(--border)] text-left text-xs uppercase tracking-wider text-[var(--text-mute)]">
                        <th class="px-4 sm:px-5 py-3">Data</th>
                        <th class="px-4 sm:px-5 py-3">Descrição</th>
                        <th class="px-4 sm:px-5 py-3">Categoria</th>
                        <th class="px-4 sm:px-5 py-3">Valor</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border)]">
                    @forelse ($rows as $row)
                        @if ($row->type === 'tx')
                            @php($tx = $row->data)
                            <tr>
                                <td class="px-4 sm:px-5 py-2.5 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($tx->occurred_on)->format('d/m/Y') }}</td>
                                <td class="px-4 sm:px-5 py-2.5 whitespace-nowrap">
                                    {{ \Grizzly\Domain\Classification\DescriptionCleaner::forDisplay($tx->description) }}
                                    @if ($tx->needs_review)
                                        <span class="ml-1 text-xs text-[var(--warn)]" title="Nenhuma regra reconheceu esse lançamento com confiança — categoria ainda não definida.">revisar</span>
                                    @endif
                                </td>
                                <td class="px-4 sm:px-5 py-2.5 text-[var(--text-dim)]">{{ $tx->category_name ?? '—' }}</td>
                                <td class="px-4 sm:px-5 py-2.5 font-mono {{ $tx->direction === 'out' ? 'text-[var(--out)]' : 'text-[var(--in)]' }}">
                                    {{ $tx->direction === 'out' ? '-' : '' }}R$ {{ number_format($tx->amount_cents / 100, 2, ',', '.') }}
                                </td>
                            </tr>
                        @else
                            @php($group = $row->data)
                            <tr>
                                <td colspan="4" class="p-0">
                                    <details class="group/rendimento">
                                        <summary class="list-none cursor-pointer px-4 sm:px-5 py-2.5 flex items-center justify-between hover:bg-[var(--surface-2)] transition-colors">
                                            <span class="text-[var(--text-dim)]">
                                                <span aria-hidden="true" class="inline-block transition-transform group-open/rendimento:rotate-90 mr-1">▸</span>
                                                Rendimento automático · {{ \Illuminate\Support\Carbon::parse($group->month . '-01')->translatedFormat('M/Y') }}
                                                · {{ $group->count }} {{ $group->count === 1 ? 'lançamento' : 'lançamentos' }}
                                            </span>
                                            <span class="font-mono text-[var(--in)]">R$ {{ number_format($group->total_cents / 100, 2, ',', '.') }}</span>
                                        </summary>
                                        <div class="max-h-48 overflow-y-auto border-t border-[var(--border)] divide-y divide-[var(--border)]">
                                            @foreach ($group->items as $item)
                                                <div class="flex items-center justify-between px-4 sm:px-5 py-2 pl-9 text-xs">
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
@endsection
