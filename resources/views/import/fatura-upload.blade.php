@extends('layouts.app')

@section('title', 'Importar fatura — ' . config('app.name'))

@section('content')
    <h1 class="text-2xl font-semibold mb-1">Importar fatura do cartão</h1>
    <p class="text-sm text-[var(--text-dim)] mb-8">CSV exportado do app do XP (fatura do cartão de crédito).</p>

    <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl p-6 max-w-lg">
        <form method="POST" action="{{ route('import.fatura.preview') }}" enctype="multipart/form-data" class="space-y-4">
            @csrf
            <div>
                <label for="credit_card_id" class="block text-sm text-[var(--text-dim)] mb-1">Cartão</label>
                <select id="credit_card_id" name="credit_card_id" required
                    class="w-full rounded-md bg-[var(--surface-2)] border border-[var(--border)] px-3 py-2 text-sm text-[var(--text)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]">
                    @foreach ($cards as $card)
                        <option value="{{ $card->id }}">{{ $card->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="reference_month" class="block text-sm text-[var(--text-dim)] mb-1">Mês desta fatura</label>
                <input type="month" id="reference_month" name="reference_month" value="{{ $defaultMonth }}" required
                    class="w-full rounded-md bg-[var(--surface-2)] border border-[var(--border)] px-3 py-2 text-sm text-[var(--text)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]">
                <p class="text-xs text-[var(--text-mute)] mt-1">O mês que aparece no nome do arquivo (ex: Fatura20260901 = setembro/2026).</p>
            </div>
            <div>
                <label for="file" class="block text-sm text-[var(--text-dim)] mb-1">Arquivo CSV</label>
                <input type="file" id="file" name="file" accept=".csv" required
                    class="w-full text-sm text-[var(--text-dim)] file:mr-3 file:rounded-md file:border-0 file:bg-[var(--surface-2)] file:px-3 file:py-2 file:text-[var(--text)]">
            </div>
            <button type="submit"
                class="w-full rounded-md bg-[var(--accent)] text-[var(--bg)] font-semibold py-2 text-sm hover:opacity-90 transition-opacity">
                Ver prévia
            </button>
        </form>
    </div>
@endsection
