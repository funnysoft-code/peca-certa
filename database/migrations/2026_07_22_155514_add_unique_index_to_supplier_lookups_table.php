<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Run the migrations. */
    public function up(): void
    {
        // Keep the oldest row per (search_run_id, supplier, query) so firstOrCreate stays stable.
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            // Keep the oldest row (created_at, then id); delete newer duplicates.
            DB::statement(<<<'SQL'
                DELETE FROM supplier_lookups a
                USING supplier_lookups b
                WHERE a.search_run_id = b.search_run_id
                  AND a.supplier = b.supplier
                  AND a.query = b.query
                  AND (
                    a.created_at > b.created_at
                    OR (a.created_at = b.created_at AND a.id::text > b.id::text)
                  )
                SQL);
        } else {
            // SQLite / MySQL (tests): delete newer duplicates by created_at then id.
            $duplicates = DB::table('supplier_lookups')
                ->select('search_run_id', 'supplier', 'query')
                ->groupBy('search_run_id', 'supplier', 'query')
                ->havingRaw('count(*) > 1')
                ->get();

            foreach ($duplicates as $group) {
                $ids = DB::table('supplier_lookups')
                    ->where('search_run_id', $group->search_run_id)
                    ->where('supplier', $group->supplier)
                    ->where('query', $group->query)->oldest()
                    ->orderBy('id')
                    ->pluck('id');

                $keep = $ids->shift();
                if ($keep === null) {
                    continue;
                }

                DB::table('supplier_lookups')
                    ->whereIn('id', $ids->all())
                    ->delete();
            }
        }

        Schema::table('supplier_lookups', function (Blueprint $table): void {
            $table->unique(
                ['search_run_id', 'supplier', 'query'],
                'supplier_lookups_run_supplier_query_unique',
            );
        });
    }

    /** Reverse the migrations. */
    public function down(): void
    {
        Schema::table('supplier_lookups', function (Blueprint $table): void {
            $table->dropUnique('supplier_lookups_run_supplier_query_unique');
        });
    }
};
