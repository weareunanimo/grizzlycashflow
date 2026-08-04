<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Sessão server-side (ADR-0009) via driver nativo do Laravel: cookie carrega só o ID,
        // dado fica no servidor, revogar é deletar a linha. Colunas extras (device_label,
        // is_trusted, revoked_at) atendem ao requisito de listar/revogar dispositivos.
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('device_label', 80)->nullable();
            $table->boolean('is_trusted')->default(false); // pulou 2FA neste dispositivo
            $table->timestamp('revoked_at')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
