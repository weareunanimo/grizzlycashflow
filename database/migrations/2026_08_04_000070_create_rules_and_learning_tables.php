<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Módulo Classification: regras + aprendizado incremental — docs/02-modelo-de-dados.md#27. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('name', 140);
            $table->integer('priority')->default(100); // menor = avaliada antes
            $table->boolean('is_active')->default(true);
            $table->boolean('stop_on_match')->default(true);
            $table->json('conditions'); // {"all":[...], "any":[...]}
            $table->json('actions'); // {"set_category_id":..., "add_tags":[...]}
            $table->string('applies_to', 20)->default('all'); // all|in|out
            $table->integer('match_count')->unsigned()->default(0);
            $table->timestamp('last_matched_at')->nullable();
            $table->string('created_from', 20); // manual|suggestion|seed
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'is_active', 'priority'], 'ix_rules_eval');
        });

        Schema::create('rule_hits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('rule_id')->constrained();
            $table->foreignId('transaction_id')->constrained();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'rule_id', 'created_at'], 'ix_hits');
        });

        Schema::create('rule_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->char('signature', 64);
            $table->json('proposed_conditions');
            $table->json('proposed_actions');
            $table->string('human_summary', 255); // "Toda compra na Shell → Combustível"
            $table->smallInteger('evidence_count');
            $table->json('evidence_tx_ids');
            $table->decimal('confidence', 4, 3);
            $table->string('status', 20); // pending|accepted|dismissed|expired
            $table->foreignId('rule_id')->nullable()->constrained('rules');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('decided_at')->nullable();

            $table->unique(['user_id', 'signature'], 'uq_rulesug');
            $table->index(['user_id', 'status'], 'ix_rulesug_status');
        });

        // Sinal de treino: toda correção manual de categoria.
        Schema::create('category_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('transaction_id')->constrained();
            $table->foreignId('from_category_id')->nullable()->constrained('categories');
            $table->foreignId('to_category_id')->constrained('categories');
            $table->string('predicted_by', 20)->nullable(); // memory|stats|ai|rule
            $table->decimal('predicted_confidence', 4, 3)->nullable();
            $table->string('merchant_key', 160)->nullable();
            $table->json('tokens')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'merchant_key', 'created_at'], 'ix_feedback');
        });

        // Classificador estatístico incremental (Naive Bayes em SQL).
        Schema::create('classifier_tokens', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained();
            $table->string('token', 60);
            $table->foreignId('category_id')->constrained();
            $table->integer('weight')->default(1); // correção manual pesa mais
            $table->timestamp('updated_at')->useCurrent();

            $table->primary(['user_id', 'token', 'category_id']);
            $table->index(['user_id', 'token'], 'ix_ct_token');
        });

        Schema::create('merchant_category_stats', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained();
            $table->foreignId('merchant_id')->constrained();
            $table->foreignId('category_id')->constrained();
            $table->integer('hits')->unsigned()->default(0);
            $table->integer('manual_hits')->unsigned()->default(0);
            $table->timestamp('last_at')->useCurrent();

            $table->primary(['user_id', 'merchant_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_category_stats');
        Schema::dropIfExists('classifier_tokens');
        Schema::dropIfExists('category_feedback');
        Schema::dropIfExists('rule_suggestions');
        Schema::dropIfExists('rule_hits');
        Schema::dropIfExists('rules');
    }
};
