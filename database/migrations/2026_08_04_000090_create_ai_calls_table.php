<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auditoria e cache de chamadas de IA — docs/02-modelo-de-dados.md#29 e docs/06-ingestao-ia.md.
 *
 * A fila genérica de jobs usa a tabela nativa `jobs` do Laravel (QUEUE_CONNECTION=database já
 * implementa o claim atômico do ADR-0002). Esta tabela é específica do domínio: registra custo,
 * cache por input_hash e serve de base ao BudgetGuard (teto mensal de gasto).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->unsignedBigInteger('job_id')->nullable(); // id da tabela `jobs`, sem FK (efêmera)
            $table->string('purpose', 40); // extract_statement|vision_receipt|transcribe|parse_text|categorize|insight
            $table->string('provider', 40);
            $table->string('model', 80);
            $table->char('input_hash', 64); // ⭐ cache: mesmo input nunca é pago 2x
            $table->boolean('cached')->default(false);
            $table->unsignedInteger('tokens_in')->nullable();
            $table->unsignedInteger('tokens_out')->nullable();
            $table->bigInteger('cost_micros')->default(0); // USD * 1.000.000
            $table->unsignedInteger('latency_ms')->nullable();
            $table->boolean('ok');
            $table->string('error', 500)->nullable();
            $table->json('response')->nullable(); // resposta estruturada (para o cache)
            $table->timestamp('created_at');

            $table->index(['user_id', 'purpose', 'input_hash'], 'ix_ai_cache');
            $table->index(['user_id', 'created_at'], 'ix_ai_cost');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_calls');
    }
};
