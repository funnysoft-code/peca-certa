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
        Schema::table('search_runs', function (Blueprint $table): void {
            $table->json('tool_traces')->nullable()->after('agent_steps');
        });
    }

    /** Reverse the migrations. */
    public function down(): void
    {
        Schema::table('search_runs', function (Blueprint $table): void {
            $table->dropColumn('tool_traces');
        });
    }
};
