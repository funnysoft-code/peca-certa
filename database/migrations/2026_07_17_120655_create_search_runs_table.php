<?php

declare(strict_types=1);

use App\Enums\SearchRunStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Run the migrations. */
    public function up(): void
    {
        Schema::create('search_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->text('request_text')->nullable();
            $table->string('vin')->nullable();
            $table->string('reference')->nullable();
            $table->json('understanding')->nullable();
            $table->json('oe_parts')->nullable();
            $table->string('status')->default(SearchRunStatus::Pending->value);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    /** Reverse the migrations. */
    public function down(): void
    {
        Schema::dropIfExists('search_runs');
    }
};
