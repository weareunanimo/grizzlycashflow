<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Grizzly Cashflow')</title>
    @include('partials.favicon')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[var(--bg)] text-[var(--text)] font-sans">
    <header class="border-b border-[var(--border)]">
        <div class="max-w-5xl mx-auto px-6 py-4 flex items-center justify-between">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2 shrink-0">
                <img src="{{ asset('images/logo-header.png') }}" alt="" class="w-8 h-8">
                <span class="font-semibold tracking-tight">Grizzly Cashflow</span>
            </a>
            <nav class="flex items-center gap-6 text-sm text-[var(--text-dim)]">
                <a href="{{ route('import.conta.show') }}" class="hover:text-[var(--text)] transition-colors">Importar extrato</a>
                <a href="{{ route('import.fatura.show') }}" class="hover:text-[var(--text)] transition-colors">Importar fatura</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="hover:text-[var(--text)] transition-colors">Sair</button>
                </form>
            </nav>
        </div>
    </header>

    <main class="max-w-5xl mx-auto px-6 py-8">
        @unless (request()->routeIs('dashboard'))
            <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-1 text-sm text-[var(--text-dim)] hover:text-[var(--text)] transition-colors mb-6">
                <span aria-hidden="true">←</span> Início
            </a>
        @endunless

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
