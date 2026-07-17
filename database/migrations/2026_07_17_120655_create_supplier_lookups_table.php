<?php

declare(strict_types=1);

use App\Enums\SupplierLookupStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Run the migrations. */
    public function up(): void
    {
        Schema::create('supplier_lookups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('search_run_id')->constrained()->cascadeOnDelete();
            $table->string('supplier');
            $table->string('query');
            $table->string('oe_description')->nullable();
            $table->string('status')->default(SupplierLookupStatus::Pending->value);
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['search_run_id', 'status']);
        });
    }

    /** Reverse the migrations. */
    public function down(): void
    {
        Schema::dropIfExists('supplier_lookups');
    }
};
