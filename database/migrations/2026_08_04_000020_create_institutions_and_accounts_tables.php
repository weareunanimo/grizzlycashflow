<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Módulo Ledger (contas/instituições) — docs/02-modelo-de-dados.md#22. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('name', 120); // 'Nubank','Itaú','Banco XP'
            $table->string('kind', 20); // bank|card_issuer|broker|wallet|other
            $table->char('ispb', 8)->nullable(); // código do BC
            $table->char('color', 7)->nullable();
            $table->string('logo_path')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'name'], 'uq_inst_user_name');
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('institution_id')->nullable()->constrained();
            $table->string('name', 120); // 'Banco XP','Carteira','Visa Black XP'
            $table->string('type', 20); // checking|savings|cash|investment|credit_card|voucher
            $table->char('currency', 3)->default('BRL');
            $table->bigInteger('opening_balance_cents')->default(0);
            $table->date('opening_balance_date');
            $table->boolean('include_in_networth')->default(true);
            $table->boolean('include_in_cashflow')->default(true);
            $table->char('color', 7)->nullable();
            $table->smallInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'archived_at'], 'ix_accounts_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('institutions');
    }
};
