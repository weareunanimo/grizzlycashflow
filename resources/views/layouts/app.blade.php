<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Grizzly Cashflow')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[var(--bg)] text-[var(--text)] font-sans">
    <header class="border-b border-[var(--border)] px-6 py-4 flex items-center justify-between">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
            <span class="text-xl">🐻</span>
            <span class="font-semibold tracking-tight">Grizzly Cashflow</span>
        </a>
        <nav class="flex items-center gap-5 text-sm text-[var(--text-dim)]">
            <a href="{{ route('import.conta.show') }}" class="hover:text-[var(--text)] transition-colors">Importar extrato</a>
            <a href="{{ route('import.fatura.show') }}" class="hover:text-[var(--text)] transition-colors">Importar fatura</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="hover:text-[var(--text)] transition-colors">Sair</button>
            </form>
        </nav>
    </header>

    <main class="max-w-5xl mx-auto px-6 py-8">
        @if (session('status'))
            <div class="mb-6 rounded-md border border-[var(--in)]/40 bg-[var(--in)]/10 px-4 py-3 text-sm text-[var(--in)]">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6 rounded-md border border-[var(--critical)]/40 bg-[var(--critical)]/10 px-4 py-3 text-sm text-[var(--critical)]">
                {{ $errors->first() }}
            </div>
        @endif

        @yield('content')
    </main>
</body>
</html>
