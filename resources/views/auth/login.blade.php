<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entrar — {{ config('app.name', 'Grizzly Cashflow') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen flex items-center justify-center bg-[var(--bg)] text-[var(--text)] font-sans px-4">
    <div class="w-full max-w-sm">
        <div class="flex items-center justify-center gap-2 mb-8">
            <span class="text-2xl">🐻</span>
            <span class="text-lg font-semibold tracking-tight">Grizzly Cashflow</span>
        </div>

        <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl p-6 shadow-xl">
            <h1 class="text-lg font-semibold mb-1">Entrar</h1>
            <p class="text-sm text-[var(--text-dim)] mb-6">Acesso restrito — sem cadastro público.</p>

            @if ($errors->any())
                <div class="mb-4 rounded-md border border-[var(--critical)]/40 bg-[var(--critical)]/10 px-3 py-2 text-sm text-[var(--critical)]">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="space-y-4">
                @csrf
                <div>
                    <label for="email" class="block text-sm text-[var(--text-dim)] mb-1">E-mail</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus
                        class="w-full rounded-md bg-[var(--surface-2)] border border-[var(--border)] px-3 py-2 text-sm text-[var(--text)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]">
                </div>
                <div>
                    <label for="password" class="block text-sm text-[var(--text-dim)] mb-1">Senha</label>
                    <input type="password" id="password" name="password" required
                        class="w-full rounded-md bg-[var(--surface-2)] border border-[var(--border)] px-3 py-2 text-sm text-[var(--text)] focus:outline-none focus:ring-2 focus:ring-[var(--accent)]">
                </div>
                <button type="submit"
                    class="w-full rounded-md bg-[var(--accent)] text-[var(--bg)] font-semibold py-2 text-sm hover:opacity-90 transition-opacity">
                    Entrar
                </button>
            </form>
        </div>

        <p class="text-center text-xs text-[var(--text-mute)] mt-6">ADR-0009 · sessão no banco · backoff progressivo</p>
    </div>
</body>
</html>
