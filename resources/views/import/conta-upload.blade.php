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
                <label for="file"
                    class="flex flex-col items-center justify-center gap-2 rounded-md border-2 border-dashed border-[var(--text-mute)] px-6 py-10 text-center cursor-pointer hover:border-[var(--accent)] transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-[var(--text-dim)]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L7 9m5-5l5 5" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3" />
                    </svg>
                    <span class="text-sm text-[var(--text-dim)]" data-file-label>Clique para escolher o arquivo CSV</span>
                    <span class="text-xs text-[var(--text-mute)]">ou arraste e solte aqui</span>
                </label>
                <input type="file" id="file" name="file" accept=".csv" required class="sr-only"
                    onchange="this.previousElementSibling.querySelector('[data-file-label]').textContent = this.files[0]?.name ?? 'Clique para escolher o arquivo CSV'">
            </div>
            <button type="submit"
                class="w-full rounded-md bg-[var(--accent)] text-[var(--bg)] font-semibold py-2 text-sm hover:opacity-90 transition-opacity">
                Ver prévia
            </button>
        </form>
    </div>
@endsection
