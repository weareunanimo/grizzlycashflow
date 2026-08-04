<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Módulo Classification (categorias/estabelecimentos/pessoas/tags) — docs/02-modelo-de-dados.md#24. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('parent_id')->nullable()->constrained('categories');
            $table->string('name', 80);
            $table->string('slug', 80);
            $table->string('kind', 20); // expense|income|transfer|investment
            $table->char('color', 7)->nullable();
            $table->string('icon', 40)->nullable();
            $table->boolean('is_system')->default(false); // do seed; não pode ser deletada, só arquivada
            $table->boolean('is_essential')->default(false); // gasto fixo x supérfluo
            $table->smallInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'parent_id', 'slug'], 'uq_cat_user_slug');
            $table->index(['user_id', 'parent_id'], 'ix_cat_parent');
        });

        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('canonical_name', 160); // 'Shell'
            $table->string('normalized_key', 160); // 'shell'
            $table->foreignId('default_category_id')->nullable()->constrained('categories');
            $table->string('cnpj', 14)->nullable();
            $table->string('city', 80)->nullable();
            $table->boolean('is_online')->default(false);
            $table->unsignedInteger('times_seen')->default(0);
            $table->date('first_seen_on')->nullable();
            $table->date('last_seen_on')->nullable();
            $table->bigInteger('total_spent_cents')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'normalized_key'], 'uq_merchant_key');
            $table->index(['user_id', 'last_seen_on'], 'ix_merchant_seen');
        });

        // Aliases: 'SHELL BOX 4412', 'AUTO POSTO SHELL' → mesmo merchant.
        Schema::create('merchant_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('alias_key', 190);
            $table->string('origin', 20); // learned|manual|seed
            $table->unsignedInteger('times_seen')->default(1);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'alias_key'], 'uq_alias');
        });

        Schema::create('counterparties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('name', 160); // 'João Silva'
            $table->string('normalized_key', 160);
            $table->string('kind', 20); // person|company|self
            $table->string('document_masked', 20)->nullable();
            $table->char('document_hash', 64)->nullable(); // permite casar sem armazenar o dado completo
            $table->json('pix_keys')->nullable();
            $table->foreignId('default_category_id')->nullable()->constrained('categories');
            $table->string('relationship', 40)->nullable(); // família|amigo|trabalho|fornecedor
            $table->unsignedInteger('times_seen')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'normalized_key'], 'uq_cp_key');
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('name', 60);
            $table->string('slug', 60);
            $table->char('color', 7)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'slug'], 'uq_tag');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
        Schema::dropIfExists('counterparties');
        Schema::dropIfExists('merchant_aliases');
        Schema::dropIfExists('merchants');
        Schema::dropIfExists('categories');
    }
};
