@extends('layouts.app')

@section('title', 'Importar extrato — ' . config('app.name'))

@section('content')
    <h1 class="text-2xl font-semibold mb-1">Importar extrato da conta</h1>
    <p class="text-sm text-[var(--text-dim)] mb-8">CSV exportado do app do Banco XP (extrato da conta).</p>

    <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl p-6 max-w-lg">
        <form method="POST" action="{{ route('import.conta.preview') }}" enctype="multipart/form-data" class="space-y-4">
            @csrf
            <div>
                <label for="account_id" class="block text-sm text-[var(--text-dim)] mb-1">Conta</label>
                <select id="account_id" name="account_id" required
                    class="w-full rounded-md bg-[var(--surface-2)] border border-[var(--border)] px-3 py-2 text-sm text-[var(--text)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]">
                    @foreach ($accounts as $account)
                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                    @endforeach
                </select>
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
