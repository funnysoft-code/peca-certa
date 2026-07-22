<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_runs', function (Blueprint $table): void {
            $table->json('messages')->nullable()->after('understanding');
            $table->json('pending_question')->nullable()->after('messages');
        });
    }

    public function down(): void
    {
        Schema::table('search_runs', function (Blueprint $table): void {
            $table->dropColumn(['messages', 'pending_question']);
        });
    }
};
