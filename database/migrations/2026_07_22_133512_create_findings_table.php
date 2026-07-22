<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Run the migrations. */
    public function up(): void
    {
        Schema::create('findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('search_run_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('supplier_lookup_id')->constrained()->cascadeOnDelete();
            $table->string('supplier');
            $table->string('brand');
            $table->string('article');
            $table->string('trader_article_number')->default('');
            $table->decimal('price', 12, 2)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->unsignedInteger('available_quantity')->default(0);
            $table->boolean('in_stock')->default(false);
            $table->string('warehouse')->default('');
            $table->timestamps();

            $table->index(['search_run_id', 'in_stock']);
            $table->index(['search_run_id', 'brand']);
            $table->index(['search_run_id', 'price']);
            $table->index(['search_run_id', 'supplier']);
        });
    }

    /** Reverse the migrations. */
    public function down(): void
    {
        Schema::dropIfExists('findings');
    }
};
