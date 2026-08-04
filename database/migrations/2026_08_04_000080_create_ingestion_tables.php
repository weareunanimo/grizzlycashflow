<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Módulo Ingestion/Import/Reconciliation — docs/02-modelo-de-dados.md#28. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('kind', 30); // card_statement_pdf|bank_statement_pdf|csv|ofx|image|audio|text|whatsapp
            $table->string('channel', 20); // web|whatsapp|api|email
            $table->string('original_name')->nullable();
            $table->string('mime', 100);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('storage_path'); // relativo a storage/uploads, fora do webroot
            $table->smallInteger('pages')->nullable();
            $table->unsignedInteger('duration_ms')->nullable(); // áudio
            $table->boolean('has_text_layer')->nullable(); // PDF
            $table->boolean('is_encrypted')->default(false);
            $table->mediumText('extracted_text')->nullable(); // ⭐ permite reprocessar sem o arquivo
            $table->mediumText('transcript')->nullable();
            $table->foreignId('detected_institution_id')->nullable()->constrained('institutions');
            $table->foreignId('detected_account_id')->nullable()->constrained('accounts');
            $table->string('status', 20); // received|processing|parsed|needs_input|failed|imported|discarded
            $table->string('error_code', 60)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('uploaded_at')->useCurrent();
            $table->timestamp('parsed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'sha256'], 'uq_doc_hash'); // mesmo arquivo nunca entra 2x
            $table->index(['user_id', 'status', 'uploaded_at'], 'ix_doc_status');
        });

        // Conciliação multicanal: a MESMA transação pode ter N provas (anexos = documents ligados aqui).
        Schema::create('transaction_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained();
            $table->string('source', 20); // pdf|csv|ocr|text|audio|whatsapp|manual
            $table->string('role', 20); // primary|corroboration|receipt|invoice|screenshot|note
            $table->string('external_id', 190)->nullable();
            $table->json('payload')->nullable(); // snapshot do que essa fonte afirmou
            $table->foreignId('merged_from_transaction_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('transaction_id', 'ix_ev_tx');
            $table->index('document_id', 'ix_ev_doc');
        });

        // Perfis de importador: layout de CSV por instituição, editável pelo usuário (ADR-0014).
        Schema::create('importer_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('institution_id')->nullable()->constrained();
            $table->string('key_slug', 60); // 'xp_cartao_fatura_csv'
            $table->string('name', 140);
            $table->string('format', 10); // csv|ofx|pdf
            $table->foreignId('default_account_id')->nullable()->constrained('accounts');
            $table->json('options'); // {delimiter,encoding,date_format,decimal_sep,skip_rows,amount_mode,has_running_balance,...}
            $table->json('column_map'); // {"date":0,"description":2,"amount":3,...}
            $table->json('detection')->nullable(); // {header_hash, header_contains:[]}
            $table->boolean('is_builtin')->default(false);
            $table->unsignedInteger('times_used')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'key_slug'], 'uq_imp_key');
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('document_id')->constrained();
            $table->foreignId('profile_id')->nullable()->constrained('importer_profiles');
            $table->string('importer_key', 60);
            $table->foreignId('target_account_id')->nullable()->constrained('accounts');
            $table->foreignId('credit_card_id')->nullable()->constrained();
            $table->foreignId('statement_id')->nullable()->constrained('card_statements');
            $table->string('status', 20); // draft|previewed|committing|committed|failed|rolled_back
            $table->json('stats')->nullable(); // {rows, to_create, to_merge, to_skip, conflicts, sum_cents}
            $table->json('validation')->nullable(); // {items_sum, statement_total, matches, warnings, strategy}
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('previewed_at')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();

            $table->index(['user_id', 'status', 'created_at'], 'ix_batch_status');
            $table->index('document_id', 'ix_batch_doc');
        });

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('batch_id')->constrained('import_batches')->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->json('raw'); // exatamente o que veio
            $table->json('normalized'); // CandidateTransaction serializado
            $table->char('row_hash', 64);
            $table->string('proposed_decision', 20); // create|merge|skip|conflict
            $table->string('final_decision', 20)->nullable();
            $table->json('dedup_candidates')->nullable(); // [{transaction_id, score, reasons}]
            $table->foreignId('target_transaction_id')->nullable()->constrained('transactions');
            $table->json('installment_info')->nullable(); // {number, total, group_key, recognized, ...}
            $table->foreignId('category_id')->nullable()->constrained();
            $table->decimal('category_confidence', 4, 3)->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->unique(['batch_id', 'row_hash'], 'uq_row'); // idempotência dentro do lote (I11)
            $table->index(['batch_id', 'line_no'], 'ix_row_batch');
        });

        // Fila de decisões de duplicidade que precisam do usuário.
        Schema::create('dedup_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('import_row_id')->nullable()->constrained('import_rows');
            $table->json('incoming_payload');
            $table->foreignId('existing_transaction_id')->constrained('transactions');
            $table->decimal('score', 4, 3);
            $table->json('reasons'); // ["same_amount","date_diff_1d","merchant_similar_0.91"]
            $table->string('decision', 20); // pending|merge|keep_both|replace|skip
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'decision', 'created_at'], 'ix_dedup_pending');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dedup_reviews');
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('importer_profiles');
        Schema::dropIfExists('transaction_evidences');
        Schema::dropIfExists('documents');
    }
};
