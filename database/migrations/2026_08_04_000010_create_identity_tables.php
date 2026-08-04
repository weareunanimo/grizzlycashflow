<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo Identity — ver docs/02-modelo-de-dados.md#21.
 *
 * Sessão de login vive na tabela nativa `sessions` do Laravel (ver migração
 * 2026_08_04_000001), não duplicada aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 190)->unique();
            $table->string('password_hash'); // Argon2id (ADR-0009)
            $table->binary('totp_secret')->nullable(); // cifrado com APP_KEY
            $table->timestamp('totp_enabled_at')->nullable();
            $table->string('timezone', 64)->default('America/Sao_Paulo');
            $table->string('locale', 10)->default('pt-BR');
            $table->char('currency', 3)->default('BRL');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('email', 190);
            $table->binary('ip')->nullable();
            $table->boolean('successful');
            $table->timestamp('created_at');

            $table->index(['email', 'created_at'], 'ix_attempts_email_time');
            // Sem índice em `ip`: é BLOB (sem tamanho fixo) e MySQL exige um
            // prefixo de tamanho para indexar BLOB/TEXT. Hoje o backoff (Grizzly\Domain\Identity\LoginLockoutPolicy)
            // só consulta por email, então esse índice não é necessário ainda.
        });

        // Append-only: nunca UPDATE/DELETE (invariante I7). Ver docs/11-seguranca.md#5.
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('entity_type', 60);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('action', 40);
            $table->json('changes')->nullable(); // {"campo":{"from":x,"to":y}}
            $table->string('actor', 20); // user|system|ai|import|rule
            $table->string('source', 20)->nullable(); // manual|pdf|csv|ocr|text|audio|whatsapp
            $table->char('request_id', 26)->nullable();
            $table->binary('ip')->nullable();
            $table->timestamp('created_at', 3);

            $table->index(['user_id', 'entity_type', 'entity_id', 'id'], 'ix_audit_entity');
            $table->index(['user_id', 'created_at'], 'ix_audit_time');
        });

        Schema::create('user_settings', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained();
            $table->string('skey', 80);
            $table->json('svalue');
            $table->timestamp('updated_at');

            $table->primary(['user_id', 'skey']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_settings');
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('login_attempts');
        Schema::dropIfExists('users');
    }
};
