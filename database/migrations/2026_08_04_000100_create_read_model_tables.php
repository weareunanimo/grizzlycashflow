<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo Projection/Insight — read models materializados (ADR-0016).
 * docs/02-modelo-de-dados.md#210.
 *
 * Reconstruídos por cron com invalidação por evento (snapshot_dirty), nunca agregados ao vivo —
 * é o que mantém o dashboard rápido mesmo com 10 anos de histórico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_daily_balances', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained();
            $table->foreignId('account_id')->constrained();
            $table->date('balance_date');
            $table->bigInteger('inflow_cents')->default(0);
            $table->bigInteger('outflow_cents')->default(0);
            $table->bigInteger('closing_cents')->default(0);
            $table->timestamp('updated_at')->useCurrent();

            $table->primary(['user_id', 'account_id', 'balance_date']);
        });

        Schema::create('cashflow_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('granularity', 10); // day|week|month
            $table->date('bucket_start');
            $table->bigInteger('realized_in_cents')->default(0);
            $table->bigInteger('realized_out_cents')->default(0);
            $table->bigInteger('projected_in_cents')->default(0);
            $table->bigInteger('projected_out_cents')->default(0);
            $table->bigInteger('card_committed_cents')->default(0); // parcelas já comprometidas
            $table->bigInteger('card_estimated_cents')->default(0); // estimativa de gasto variável
            $table->bigInteger('recurring_cents')->default(0);
            $table->bigInteger('opening_balance_cents')->default(0);
            $table->bigInteger('closing_balance_cents')->default(0);
            $table->boolean('is_projection')->default(false);
            $table->string('risk_level', 10)->nullable(); // ok|attention|critical
            $table->timestamp('computed_at')->useCurrent();

            $table->unique(['user_id', 'granularity', 'bucket_start'], 'uq_cf');
            $table->index(['user_id', 'granularity', 'bucket_start'], 'ix_cf_period');
        });

        // Marca períodos que precisam ser recalculados (invalidação por evento).
        Schema::create('snapshot_dirty', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained();
            $table->string('scope', 20); // cashflow|balances|category_rollup
            $table->date('from_date');
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['user_id', 'scope', 'from_date']);
        });

        Schema::create('category_month_rollup', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained();
            $table->foreignId('category_id')->constrained();
            $table->date('month'); // dia 01
            $table->string('direction', 3);
            $table->bigInteger('total_cents');
            $table->unsignedInteger('tx_count');
            $table->timestamp('updated_at')->useCurrent();

            $table->primary(['user_id', 'category_id', 'month', 'direction']);
        });

        Schema::create('insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('type', 50); // category_spike|negative_forecast|committed_total|subscription_count|...
            $table->string('severity', 10); // info|warn|critical
            $table->string('title', 200);
            $table->string('body', 500)->nullable();
            $table->json('metrics')->nullable(); // números por trás da frase (auditável)
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('action_url', 190)->nullable();
            $table->string('dedupe_key', 190);
            $table->string('generated_by', 10); // rule|llm
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('status', 20); // new|seen|dismissed|pinned
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'dedupe_key'], 'uq_insight');
            $table->index(['user_id', 'status', 'created_at'], 'ix_insight_feed');
        });

        Schema::create('metrics_daily', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained();
            $table->date('metric_date');
            $table->unsignedInteger('tx_created')->default(0);
            $table->unsignedInteger('tx_auto_classified')->default(0);
            $table->unsignedInteger('tx_manual_fixed')->default(0);
            $table->unsignedInteger('dedup_prevented')->default(0);
            $table->bigInteger('ai_cost_micros')->default(0);
            $table->decimal('forecast_error_pct', 7, 2)->nullable();

            $table->primary(['user_id', 'metric_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metrics_daily');
        Schema::dropIfExists('insights');
        Schema::dropIfExists('category_month_rollup');
        Schema::dropIfExists('snapshot_dirty');
        Schema::dropIfExists('cashflow_snapshots');
        Schema::dropIfExists('account_daily_balances');
    }
};
