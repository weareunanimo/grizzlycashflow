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
        <div class="max-w-5xl mx-auto px-4 sm:px-6 py-4 flex items-center justify-between">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2 shrink-0">
                <img src="{{ asset('images/logo-header.png') }}" alt="" class="w-8 h-8">
                <span class="font-semibold tracking-tight">Grizzly Cashflow</span>
            </a>

            <nav class="hidden md:flex items-center gap-6 text-sm text-[var(--text-dim)]">
                <a href="{{ route('banks.index') }}" class="hover:text-[var(--text)] transition-colors">Bancos</a>
                <a href="{{ route('cards.index') }}" class="hover:text-[var(--text)] transition-colors">Cartões</a>
                <a href="{{ route('import.index') }}" class="hover:text-[var(--text)] transition-colors">Importar</a>
                <a href="{{ route('categories.index') }}" class="hover:text-[var(--text)] transition-colors">Categorias</a>
                <a href="{{ route('closing.index') }}" class="hover:text-[var(--text)] transition-colors">Fechamento</a>
                <a href="{{ route('review.index') }}" class="hover:text-[var(--text)] transition-colors">
                    Revisar
                    @if (($pendingReviewCount ?? 0) > 0)
                        <span class="ml-1 inline-flex items-center justify-center rounded-full bg-[var(--warn)]/20 text-[var(--warn)] text-xs px-1.5 py-0.5">{{ $pendingReviewCount }}</span>
                    @endif
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="hover:text-[var(--text)] transition-colors">Sair</button>
                </form>
            </nav>

            <button type="button" aria-label="Menu" aria-expanded="false" id="mobile-nav-toggle"
                class="md:hidden p-2 -mr-2 text-[var(--text)]"
                onclick="document.getElementById('mobile-nav').classList.toggle('hidden'); this.setAttribute('aria-expanded', document.getElementById('mobile-nav').classList.contains('hidden') ? 'false' : 'true')">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>
        </div>

        <nav id="mobile-nav" class="hidden md:hidden border-t border-[var(--border)] px-4 py-3 flex flex-col gap-3 text-sm text-[var(--text-dim)]">
            <a href="{{ route('banks.index') }}" class="hover:text-[var(--text)] transition-colors">Bancos</a>
            <a href="{{ route('cards.index') }}" class="hover:text-[var(--text)] transition-colors">Cartões</a>
            <a href="{{ route('import.index') }}" class="hover:text-[var(--text)] transition-colors">Importar</a>
            <a href="{{ route('categories.index') }}" class="hover:text-[var(--text)] transition-colors">Categorias</a>
            <a href="{{ route('closing.index') }}" class="hover:text-[var(--text)] transition-colors">Fechamento</a>
            <a href="{{ route('review.index') }}" class="hover:text-[var(--text)] transition-colors">
                Revisar
                @if (($pendingReviewCount ?? 0) > 0)
                    <span class="ml-1 inline-flex items-center justify-center rounded-full bg-[var(--warn)]/20 text-[var(--warn)] text-xs px-1.5 py-0.5">{{ $pendingReviewCount }}</span>
                @endif
            </a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="hover:text-[var(--text)] transition-colors">Sair</button>
            </form>
        </nav>
    </header>

    <main class="max-w-5xl mx-auto px-4 sm:px-6 py-8">
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
