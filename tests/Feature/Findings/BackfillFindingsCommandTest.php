<?php

declare(strict_types=1);

use App\Enums\Supplier;
use App\Enums\SupplierLookupStatus;
use App\Models\Finding;
use App\Models\SearchRun;
use App\Models\SupplierLookup;

it('backfills findings from lookup result JSON', function (): void {
    $run = SearchRun::factory()->create();
    SupplierLookup::factory()->for($run, 'run')->create([
        'supplier' => Supplier::AutoDelta,
        'status' => SupplierLookupStatus::Done,
        'result' => [
            'query' => 'OC90',
            'variants' => [
                [
                    'brandName' => 'Mann',
                    'articleNumber' => 'OC90',
                    'traderArticleNumber' => 'T1',
                    'purchasePrice' => 10.5,
                    'retailPrice' => 18.0,
                    'currency' => 'EUR',
                    'availableQuantity' => 2,
                    'inStock' => true,
                    'warehouse' => 'WH1',
                ],
            ],
            'searchUrl' => null,
        ],
    ]);

    expect(Finding::query()->count())->toBe(0);

    $this->artisan('findings:backfill')
        ->assertSuccessful()
        ->expectsOutputToContain('Processed 1 lookups');

    expect(Finding::query()->where('search_run_id', $run->id)->count())->toBe(1)
        ->and(Finding::query()->first()?->brand)->toBe('Mann');
});

it('can scope the backfill to a single search run', function (): void {
    $runA = SearchRun::factory()->create();
    $runB = SearchRun::factory()->create();

    foreach ([$runA, $runB] as $run) {
        SupplierLookup::factory()->for($run, 'run')->create([
            'supplier' => Supplier::AutoZitania,
            'status' => SupplierLookupStatus::Done,
            'result' => [
                'query' => 'X',
                'variants' => [
                    [
                        'brandName' => 'Brand',
                        'articleNumber' => 'A1',
                        'traderArticleNumber' => '',
                        'purchasePrice' => 1.0,
                        'retailPrice' => 2.0,
                        'currency' => 'EUR',
                        'availableQuantity' => 1,
                        'inStock' => true,
                        'warehouse' => '',
                    ],
                ],
                'searchUrl' => null,
            ],
        ]);
    }

    $this->artisan('findings:backfill', ['--run' => $runA->id])
        ->assertSuccessful();

    expect(Finding::query()->where('search_run_id', $runA->id)->count())->toBe(1)
        ->and(Finding::query()->where('search_run_id', $runB->id)->count())->toBe(0);
});
