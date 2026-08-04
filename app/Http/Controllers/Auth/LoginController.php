<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use DateTimeImmutable;
use Grizzly\Domain\Identity\LoginLockoutPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Login server-side (ADR-0009): sem cadastro público, sessão no banco, backoff progressivo
 * contra força bruta usando `login_attempts` + Grizzly\Domain\Identity\LoginLockoutPolicy.
 */
final class LoginController extends Controller
{
    public function __construct(private readonly LoginLockoutPolicy $lockoutPolicy)
    {
    }

    public function show(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $ip = $request->ip();
        $now = now();

        if ($blockedUntil = $this->blockedUntil($credentials['email'], $now)) {
            return back()
                ->withInput(['email' => $credentials['email']])
                ->withErrors(['email' => 'Muitas tentativas. Tente novamente às ' . $blockedUntil->format('H:i:s') . '.']);
        }

        $ok = Auth::attempt($credentials, remember: false);

        DB::table('login_attempts')->insert([
            'email' => $credentials['email'],
            'ip' => @inet_pton($ip) ?: null,
            'successful' => $ok,
            'created_at' => $now,
        ]);

        if (!$ok) {
            return back()
                ->withInput(['email' => $credentials['email']])
                ->withErrors(['email' => 'Credenciais inválidas.']);
        }

        $request->session()->regenerate();

        /** @var \App\Models\User $user */
        $user = Auth::user();
        $user->forceFill(['last_login_at' => $now])->save();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function blockedUntil(string $email, Carbon $now): ?DateTimeImmutable
    {
        $lookback = $now->copy()->subHour();

        $lastFailure = DB::table('login_attempts')
            ->where('email', $email)
            ->where('successful', false)
            ->where('created_at', '>=', $lookback)
            ->orderByDesc('created_at')
            ->first();

        if ($lastFailure === null) {
            return null;
        }

        $recentFailures = DB::table('login_attempts')
            ->where('email', $email)
            ->where('successful', false)
            ->where('created_at', '>=', $lookback)
            ->count();

        $lastFailureAt = new DateTimeImmutable($lastFailure->created_at);
        $nowImmutable = new DateTimeImmutable((string) $now);

        return $this->lockoutPolicy->isBlocked($recentFailures, $lastFailureAt, $nowImmutable)
            ? $this->lockoutPolicy->blockedUntil($recentFailures, $lastFailureAt)
            : null;
    }
}
