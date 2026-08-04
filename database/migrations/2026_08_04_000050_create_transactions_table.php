<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tabela central do ledger — docs/02-modelo-de-dados.md#25.
 *
 * `amount_cents` sempre positivo + `direction` evita a classe de bugs "esqueci o ABS()".
 * `raw_description` imutável permite reprocessar o histórico quando um parser melhorar.
 * Sem UNIQUE em `fingerprint`: duplicidade é decidida por scoring + usuário, nunca por
 * constraint cega (dois cafés de R$ 7 no mesmo dia são legítimos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();

            // Onde
            $table->foreignId('account_id')->constrained();
            $table->foreignId('credit_card_id')->nullable()->constrained();
            $table->foreignId('statement_id')->nullable()->constrained('card_statements');

            // Quanto
            $table->string('direction', 3); // in|out
            $table->bigInteger('amount_cents');
            $table->char('currency', 3)->default('BRL');

            // Quando (dois eixos — ADR-0007)
            $table->date('occurred_on'); // competência
            $table->dateTime('occurred_at')->nullable(); // hora, quando a fonte informa
            $table->date('cash_effect_on'); // caixa: cartão = vencimento da fatura

            // O quê
            $table->string('description', 255);
            $table->string('raw_description', 500)->nullable(); // ⭐ imutável
            $table->foreignId('category_id')->nullable()->constrained();
            $table->foreignId('merchant_id')->nullable()->constrained();
            $table->foreignId('counterparty_id')->nullable()->constrained();
            $table->string('payment_method', 20); // pix|credit_card|debit_card|cash|boleto|ted|doc|transfer|direct_debit|other

            // Estado
            $table->string('status', 20); // pending|cleared|canceled|refunded
            $table->boolean('is_transfer')->default(false);
            $table->char('transfer_group_id', 26)->nullable(); // une as 2 pernas / pgto de fatura
            $table->boolean('excluded_from_analytics')->default(false);

            // Vínculos de inteligência
            $table->foreignId('installment_id')->nullable()->constrained('card_installments');
            $table->foreignId('purchase_id')->nullable()->constrained('card_purchases');
            $table->foreignId('recurrence_id')->nullable();
            $table->foreignId('occurrence_id')->nullable();

            // Procedência e confiança
            $table->string('source', 20); // manual|pdf|csv|ocr|text|audio|whatsapp|api
            $table->foreignId('source_document_id')->nullable();
            $table->foreignId('import_row_id')->nullable();
            $table->string('external_id', 190)->nullable();
            $table->decimal('category_confidence', 4, 3)->nullable();
            $table->string('category_source', 20)->nullable(); // user|rule|memory|stats|ai|seed
            $table->boolean('needs_review')->default(false);
            $table->string('review_reason', 60)->nullable(); // low_confidence|possible_duplicate|parse_mismatch

            // Dedup
            $table->char('fingerprint', 64); // estrito
            $table->char('fingerprint_loose', 64); // tolerante

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'account_id', 'external_id'], 'uq_tx_external'); // invariante I1
            $table->unique('import_row_id', 'uq_tx_import_row'); // invariante I2
            $table->index(['user_id', 'occurred_on', 'id'], 'ix_tx_period');
            $table->index(['user_id', 'cash_effect_on', 'status'], 'ix_tx_cash'); // ⭐ fluxo de caixa
            $table->index(['user_id', 'category_id', 'occurred_on'], 'ix_tx_cat');
            $table->index(['user_id', 'account_id', 'occurred_on'], 'ix_tx_account');
            $table->index(['user_id', 'merchant_id', 'occurred_on'], 'ix_tx_merchant');
            $table->index(['user_id', 'fingerprint'], 'ix_tx_fp'); // ⭐ dedup
            $table->index(['user_id', 'fingerprint_loose', 'amount_cents'], 'ix_tx_fp_loose');
            $table->index(['user_id', 'needs_review', 'occurred_on'], 'ix_tx_review');
            $table->index(['user_id', 'recurrence_id', 'occurred_on'], 'ix_tx_recurrence');
            $table->index('statement_id', 'ix_tx_statement');
            $table->index(['user_id', 'deleted_at'], 'ix_tx_deleted');
        });

        Schema::create('transaction_tags', function (Blueprint $table) {
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            $table->primary(['transaction_id', 'tag_id']);
            $table->index('tag_id', 'ix_tt_tag');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_tags');
        Schema::dropIfExists('transactions');
    }
};
