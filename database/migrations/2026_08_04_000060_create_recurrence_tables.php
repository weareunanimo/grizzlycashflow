<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo Recurrence — agrupador, nunca gerador de lançamento (ADR-0010, invariante I5).
 * docs/02-modelo-de-dados.md#26.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('name', 140); // 'Internet Vivo'
            $table->string('kind', 30); // subscription|bill|installment_plan|salary|rent|tuition|other
            $table->string('direction', 3); // in|out
            $table->string('frequency', 20); // weekly|biweekly|monthly|bimonthly|quarterly|semiannual|annual|custom
            $table->smallInteger('interval_count')->default(1);
            $table->tinyInteger('day_of_month')->nullable();
            $table->tinyInteger('day_of_week')->nullable();
            $table->json('custom_rule')->nullable();
            $table->bigInteger('expected_amount_cents')->nullable();
            $table->decimal('amount_tolerance_pct', 5, 2)->default(15.00);
            $table->tinyInteger('date_tolerance_days')->default(5);
            $table->foreignId('category_id')->nullable()->constrained();
            $table->foreignId('merchant_id')->nullable()->constrained();
            $table->foreignId('counterparty_id')->nullable()->constrained();
            $table->foreignId('account_id')->nullable()->constrained();
            $table->foreignId('credit_card_id')->nullable()->constrained();
            $table->string('payment_method', 20)->nullable();
            $table->json('match_config')->nullable(); // {description_contains, regex, exclude_if_contains, ...}
            $table->date('started_on');
            $table->date('ended_on')->nullable();
            $table->string('status', 20); // active|paused|canceled|ended
            $table->string('cancel_reason', 255)->nullable();
            $table->boolean('is_essential')->default(false);
            $table->text('notes')->nullable();
            $table->string('created_from', 20); // manual|suggestion
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status', 'frequency'], 'ix_rec_status');
        });

        // Uma linha por período esperado — nunca vira lançamento sozinha (I5).
        Schema::create('recurrence_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('recurrence_id')->constrained()->cascadeOnDelete();
            $table->char('period_key', 10); // '2026-08' | '2026-W32'
            $table->date('expected_on');
            $table->bigInteger('expected_amount_cents')->nullable();
            $table->foreignId('transaction_id')->nullable()->unique('uq_occ_tx')->constrained();
            $table->bigInteger('actual_amount_cents')->nullable();
            $table->date('actual_on')->nullable();
            $table->string('status', 20); // expected|matched|late|missed|skipped
            $table->decimal('match_confidence', 4, 3)->nullable();
            $table->decimal('delta_pct', 7, 2)->nullable();
            $table->timestamp('matched_at')->nullable();
            $table->timestamps();

            $table->unique(['recurrence_id', 'period_key'], 'uq_occ_period');
            $table->index(['user_id', 'status', 'expected_on'], 'ix_occ_status');
        });

        // Histórico de reajustes — derivado, mas materializado para exibição instantânea.
        Schema::create('recurrence_price_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('recurrence_id')->constrained()->cascadeOnDelete();
            $table->date('changed_on');
            $table->bigInteger('from_cents');
            $table->bigInteger('to_cents');
            $table->decimal('delta_pct', 7, 2);
            $table->foreignId('transaction_id')->nullable()->constrained();
            $table->timestamp('created_at');

            $table->index(['user_id', 'recurrence_id', 'changed_on'], 'ix_rpc');
        });

        // Padrões detectados que ainda não são recorrência — sugere, nunca cria sozinho.
        Schema::create('recurrence_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->char('signature', 64); // hash do padrão detectado, inclui faixa de valor (ADR-0021)
            $table->json('proposed');
            $table->json('evidence_tx_ids');
            $table->smallInteger('occurrences');
            $table->decimal('confidence', 4, 3);
            $table->string('status', 20); // pending|accepted|dismissed|expired
            $table->foreignId('recurrence_id')->nullable()->constrained('recurrences');
            $table->timestamp('created_at');
            $table->timestamp('decided_at')->nullable();

            $table->unique(['user_id', 'signature'], 'uq_recsug');
            $table->index(['user_id', 'status'], 'ix_recsug_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurrence_suggestions');
        Schema::dropIfExists('recurrence_price_changes');
        Schema::dropIfExists('recurrence_occurrences');
        Schema::dropIfExists('recurrences');
    }
};
