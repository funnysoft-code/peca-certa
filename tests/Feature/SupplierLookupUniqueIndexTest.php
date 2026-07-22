<?php

declare(strict_types=1);

use App\Actions\FanOutOePricing;
use App\Data\OePart;
use App\Enums\Supplier;
use App\Enums\SupplierLookupStatus;
use App\Jobs\PriceSupplierJob;
use App\Models\SearchRun;
use App\Models\SupplierLookup;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;

it('enforces uniqueness on search_run_id, supplier, and query', function (): void {
    $run = SearchRun::factory()->create();

    SupplierLookup::factory()->for($run, 'run')->create([
        'supplier' => Supplier::AutoDelta,
        'query' => '11427622446',
        'status' => SupplierLookupStatus::Pending,
    ]);

    expect(fn () => SupplierLookup::factory()->for($run, 'run')->create([
        'supplier' => Supplier::AutoDelta,
        'query' => '11427622446',
        'status' => SupplierLookupStatus::Pending,
    ]))->toThrow(QueryException::class);
});

it('allows the same query for different suppliers on one run', function (): void {
    $run = SearchRun::factory()->create();

    SupplierLookup::factory()->for($run, 'run')->create([
        'supplier' => Supplier::AutoDelta,
        'query' => '11427622446',
    ]);

    SupplierLookup::factory()->for($run, 'run')->create([
        'supplier' => Supplier::AutoZitania,
        'query' => '11427622446',
    ]);

    expect($run->lookups()->count())->toBe(2);
});

it('fan-out remains idempotent under the unique index', function (): void {
    Bus::fake([PriceSupplierJob::class]);

    $run = SearchRun::factory()->create();
    $parts = [
        new OePart(oeNumber: '11427622446', description: 'Oil filter', brand: 'OE'),
    ];

    resolve(FanOutOePricing::class)->execute($run, $parts);
    resolve(FanOutOePricing::class)->execute($run->fresh(), $parts);

    expect($run->lookups()->count())->toBe(2)
        ->and($run->lookups()->pluck('query')->unique()->all())->toBe(['11427622446']);

    Bus::assertDispatchedTimes(PriceSupplierJob::class, 2);
});

it('has the unique index on supplier_lookups', function (): void {
    $indexes = Schema::getIndexes('supplier_lookups');
    $names = array_column($indexes, 'name');

    expect($names)->toContain('supplier_lookups_run_supplier_query_unique');
});
