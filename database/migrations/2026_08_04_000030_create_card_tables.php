<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo Card — o coração do produto (ADR-0004). docs/02-modelo-de-dados.md#22-23.
 *
 * card_purchases + card_installments são entidades de primeira classe, separadas de
 * `transactions`: uma parcela futura é uma obrigação, não um fato consumado (invariante I4).
 * `group_key` é o mecanismo que reconhece a mesma compra entre faturas sucessivas (invariante I10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('account_id')->unique('uq_card_account')->constrained();
            $table->foreignId('payment_account_id')->nullable()->constrained('accounts');
            $table->string('brand', 20)->nullable(); // visa|mastercard|elo|amex|hipercard
            $table->char('last4', 4)->nullable();
            $table->string('holder_name', 120)->nullable();
            $table->bigInteger('credit_limit_cents')->nullable();
            $table->tinyInteger('closing_day')->nullable();
            $table->tinyInteger('due_day');
            $table->string('due_day_rule', 20)->default('next_business_day');
            $table->boolean('is_virtual')->default(false);
            $table->timestamps();
        });

        Schema::create('card_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('credit_card_id')->constrained();
            $table->date('reference_month'); // sempre dia 01
            $table->date('opening_date')->nullable();
            $table->date('closing_date')->nullable();
            $table->date('due_date');
            $table->bigInteger('previous_balance_cents')->default(0);
            $table->bigInteger('purchases_cents')->default(0);
            $table->bigInteger('payments_cents')->default(0);
            $table->bigInteger('interest_cents')->default(0);
            $table->bigInteger('fees_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);
            $table->bigInteger('minimum_cents')->nullable();
            // projected|open|closed|paid|partially_paid|overdue (ADR-0018: fatura aberta)
            $table->string('status', 20);
            $table->foreignId('source_document_id')->nullable();
            $table->bigInteger('items_sum_cents')->nullable();
            $table->boolean('reconciled')->default(false); // items_sum == total? (ADR-0019)
            $table->bigInteger('advance_payment_cents')->nullable(); // antecipação (ADR-0022)
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->unique(['credit_card_id', 'reference_month'], 'uq_stmt_card_month');
            $table->index(['user_id', 'due_date'], 'ix_stmt_due');
        });

        Schema::create('card_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('credit_card_id')->constrained();
            $table->foreignId('merchant_id')->nullable();
            $table->foreignId('category_id')->nullable();
            $table->string('description', 255); // 'Notebook Dell'
            $table->string('raw_description', 500)->nullable(); // texto cru da fatura
            $table->date('purchase_date')->nullable(); // ⭐ âncora do group_key quando informada
            $table->smallInteger('installments_total')->default(1);
            $table->bigInteger('installment_amount_cents');
            $table->bigInteger('total_amount_cents');
            $table->date('first_reference_month'); // derivado: mês_da_fatura - (n-1)
            $table->char('currency', 3)->default('BRL');
            $table->decimal('original_amount', 18, 6)->nullable(); // compra internacional
            $table->char('original_currency', 3)->nullable();
            $table->string('status', 20); // active|completed|canceled|refunded|disputed
            $table->boolean('is_installment_plan')->default(false); // parcelamento de fatura
            $table->char('group_key', 64); // ⭐ chave de reconhecimento entre faturas
            $table->decimal('detection_confidence', 4, 3)->default(1.000);
            $table->boolean('needs_review')->default(false);
            $table->string('source', 20); // pdf|csv|manual|ocr|text
            $table->foreignId('source_document_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'credit_card_id', 'group_key'], 'uq_purchase_group'); // invariante I10
            $table->index(['user_id', 'status', 'first_reference_month'], 'ix_purchase_status');
            $table->index(['user_id', 'merchant_id'], 'ix_purchase_merchant');
        });

        Schema::create('card_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('purchase_id')->constrained('card_purchases')->cascadeOnDelete();
            $table->foreignId('credit_card_id')->constrained(); // desnormalizado — 90% das queries filtram por cartão
            $table->smallInteger('number'); // 5
            $table->smallInteger('installments_total'); // 12 (desnormalizado p/ exibir "5/12")
            $table->bigInteger('amount_cents');
            $table->date('reference_month'); // mês da fatura em que cai (dia 01)
            $table->date('due_date')->nullable();
            $table->foreignId('statement_id')->nullable()->constrained('card_statements');
            $table->foreignId('transaction_id')->nullable();
            $table->string('status', 20); // projected|billed|paid|canceled|refunded
            $table->boolean('is_reconstructed')->default(false); // inferida, não vista em fatura
            $table->timestamps();

            $table->unique(['purchase_id', 'number'], 'uq_inst_purchase_number');
            $table->index(['user_id', 'credit_card_id', 'reference_month', 'status'], 'ix_inst_forecast'); // ⭐ query da projeção
            $table->index('statement_id', 'ix_inst_statement');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_installments');
        Schema::dropIfExists('card_purchases');
        Schema::dropIfExists('card_statements');
        Schema::dropIfExists('credit_cards');
    }
};
