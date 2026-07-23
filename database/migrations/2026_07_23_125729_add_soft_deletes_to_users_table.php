<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->softDeletes();
        });

        // Allow re-inviting the same email after soft-delete (active rows only).
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['email']);
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email) WHERE deleted_at IS NULL');
        } elseif ($driver === 'sqlite') {
            DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email) WHERE deleted_at IS NULL');
        } else {
            // Fallback drivers without partial unique support: keep a plain unique
            // (soft-deleted emails block re-invite until hard purge).
            Schema::table('users', function (Blueprint $table): void {
                $table->unique('email');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS users_email_unique');
        } else {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropUnique(['email']);
            });
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->unique('email');
            $table->dropSoftDeletes();
        });
    }
};
