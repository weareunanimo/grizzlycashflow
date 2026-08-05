@extends('layouts.app')

@section('title', 'Prévia da importação — ' . config('app.name'))

@section('content')
    <h1 class="text-2xl font-semibold mb-1">Prévia — {{ $account->name }}</h1>
    <p class="text-sm text-[var(--text-dim)] mb-6">Confira antes de confirmar. Nada é gravado até você clicar em "Confirmar importação".</p>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6 max-w-lg">
        <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl p-4">
            <p class="text-xs uppercase tracking-wider text-[var(--text-mute)] mb-1">Novos</p>
            <p class="text-xl font-semibold text-[var(--in)]">{{ $toCreate }}</p>
        </div>
        <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl p-4">
            <p class="text-xs uppercase tracking-wider text-[var(--text-mute)] mb-1">Já existentes</p>
            <p class="text-xl font-semibold text-[var(--text-dim)]">{{ $toSkip }}</p>
        </div>
        <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl p-4">
            <p class="text-xs uppercase tracking-wider text-[var(--text-mute)] mb-1">Total no arquivo</p>
            <p class="text-xl font-semibold">{{ count($rows) }}</p>
        </div>
    </div>

    @if (count($breaks) > 0)
        <div class="mb-6 rounded-md border border-[var(--warn)]/40 bg-[var(--warn)]/10 px-4 py-3 text-sm text-[var(--warn)]">
            A cadeia de saldo quebrou em {{ count($breaks) }} linha(s) — pode haver lançamento faltando ou fora de ordem no arquivo. A importação continua funcionando normalmente, mas vale conferir.
        </div>
    @endif

    <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl overflow-hidden mb-6">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[640px] text-sm">
                <thead>
                    <tr class="border-b border-[var(--border)] text-left text-xs uppercase tracking-wider text-[var(--text-mute)]">
                        <th>Data</th>
                        <th>Descrição</th>
                        <th>Valor</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--border)]">
                    @foreach ($rows as $row)
                        <tr class="{{ $row['decision'] === 'duplicata' ? 'opacity-50' : '' }}">
                            <td class="whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($row['date'])->format('d/m/Y') }}</td>
                            <td class="whitespace-nowrap">{{ $row['description'] }}</td>
                            <td class="font-mono {{ $row['direction'] === 'out' ? 'text-[var(--out)]' : 'text-[var(--in)]' }}">
                                {{ $row['amount']->formatBrl() }}
                            </td>
                            <td>
                                @if ($row['decision'] === 'novo')
                                    <span class="text-xs text-[var(--in)]">novo</span>
                                @else
                                    <span class="text-xs text-[var(--text-mute)]">já existe</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <form method="POST" action="{{ route('import.conta.commit') }}">
        @csrf
        <button type="submit"
            class="rounded-md bg-[var(--accent)] text-[var(--bg)] font-semibold px-5 py-2 text-sm hover:opacity-90 transition-opacity">
            Confirmar importação
        </button>
        <a href="{{ route('import.index') }}" class="ml-3 text-sm text-[var(--text-dim)] hover:text-[var(--text)]">Cancelar</a>
    </form>
@endsection
