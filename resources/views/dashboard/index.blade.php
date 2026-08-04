<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard — {{ config('app.name', 'Grizzly Cashflow') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-[var(--bg)] text-[var(--text)] font-sans">
    <header class="border-b border-[var(--border)] px-6 py-4 flex items-center justify-between">
        <div class="flex items-center gap-2">
            <span class="text-xl">🐻</span>
            <span class="font-semibold tracking-tight">Grizzly Cashflow</span>
        </div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="text-sm text-[var(--text-dim)] hover:text-[var(--text)] transition-colors">
                Sair
            </button>
        </form>
    </header>

    <main class="max-w-5xl mx-auto px-6 py-8">
        <h1 class="text-2xl font-semibold mb-1">Olá, {{ $user->name }}</h1>
        <p class="text-sm text-[var(--text-dim)] mb-8">{{ $user->email }} · Fase 0 — arquitetura aprovada, autenticação funcionando.</p>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
            <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl p-5">
                <p class="text-xs uppercase tracking-wider text-[var(--text-mute)] mb-1">Contas</p>
                <p class="text-2xl font-semibold">{{ $accounts->count() }}</p>
            </div>
            <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl p-5">
                <p class="text-xs uppercase tracking-wider text-[var(--text-mute)] mb-1">Categorias</p>
                <p class="text-2xl font-semibold">{{ $categoryCount }}</p>
            </div>
            <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl p-5">
                <p class="text-xs uppercase tracking-wider text-[var(--text-mute)] mb-1">Regras de categorização</p>
                <p class="text-2xl font-semibold">{{ $ruleCount }}</p>
            </div>
        </div>

        <section class="mb-8">
            <h2 class="text-sm font-semibold text-[var(--text-dim)] uppercase tracking-wider mb-3">Contas</h2>
            <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl divide-y divide-[var(--border)]">
                @foreach ($accounts as $account)
                    <div class="flex items-center justify-between px-5 py-4">
                        <div>
                            <p class="font-medium">{{ $account->name }}</p>
                            <p class="text-xs text-[var(--text-dim)]">{{ $account->institution_name }} · {{ $account->type }}</p>
                        </div>
                        <p class="font-mono text-sm {{ $account->opening_balance_cents >= 0 ? 'text-[var(--in)]' : 'text-[var(--out)]' }}">
                            R$ {{ number_format($account->opening_balance_cents / 100, 2, ',', '.') }}
                        </p>
                    </div>
                @endforeach
            </div>
        </section>

        <section>
            <h2 class="text-sm font-semibold text-[var(--text-dim)] uppercase tracking-wider mb-3">Cartões de crédito</h2>
            <div class="bg-[var(--surface-1)] border border-[var(--border)] rounded-xl divide-y divide-[var(--border)]">
                @forelse ($creditCards as $card)
                    <div class="flex items-center justify-between px-5 py-4">
                        <div>
                            <p class="font-medium">{{ $card->account_name }}</p>
                            <p class="text-xs text-[var(--text-dim)]">
                                {{ ucfirst((string) $card->brand) }} · fecha dia {{ $card->closing_day }}, vence dia {{ $card->due_day }}
                            </p>
                        </div>
                    </div>
                @empty
                    <p class="px-5 py-4 text-sm text-[var(--text-dim)]">Nenhum cartão cadastrado.</p>
                @endforelse
            </div>
        </section>
    </main>
</body>
</html>
