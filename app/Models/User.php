<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Sem cadastro público (ADR-0009) — o único usuário inicial é criado via
 * `php artisan user:create`. Sem "lembrar-me" (sem coluna remember_token):
 * a sessão de 30 dias (SESSION_LIFETIME) já cobre a conveniência pedida.
 */
#[Fillable(['name', 'email', 'password_hash', 'timezone', 'locale', 'currency'])]
#[Hidden(['password_hash', 'totp_secret'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'password_hash' => 'hashed', // Argon2id (config/hashing.php)
            'totp_enabled_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    // Sem "lembrar-me": nenhuma coluna remember_token na tabela users.
    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
        // no-op — intencional
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
