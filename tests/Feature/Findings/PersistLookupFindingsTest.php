<?php

declare(strict_types=1);

use App\Actions\PersistLookupFindings;
use App\Enums\Supplier;
use App\Enums\SupplierLookupStatus;
use App\Models\Finding;
use App\Models\SearchRun;
use App\Models\SupplierLookup;

it('persists one finding row per variant with frozen Auto Delta purchase price', function (): void {
    $run = SearchRun::factory()->create();
    $lookup = SupplierLookup::factory()->for($run, 'run')->create([
        'supplier' => Supplier::AutoDelta,
        'status' => SupplierLookupStatus::Done,
        'result' => [
            'query' => 'OC90',
            'variants' => [
                [
                    'brandName' => 'Mann',
                    'articleNumber' => 'OC90',
                    'traderArticleNumber' => 'AD-1',
                    'purchasePrice' => 10.5,
                    'retailPrice' => 18.0,
                    'currency' => 'EUR',
                    'availableQuantity' => 4,
                    'inStock' => true,
                    'warehouse' => 'WH1',
                ],
                [
                    'brandName' => 'Bosch',
                    'articleNumber' => 'B1',
                    'traderArticleNumber' => 'AD-2',
                    'purchasePrice' => 5.0,
                    'retailPrice' => 9.0,
                    'currency' => 'EUR',
                    'availableQuantity' => 0,
                    'inStock' => false,
                    'warehouse' => '',
                ],
            ],
            'searchUrl' => null,
        ],
    ]);

    resolve(PersistLookupFindings::class)->execute($lookup);

    $findings = Finding::query()->where('supplier_lookup_id', $lookup->id)->orderBy('article')->get();

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->search_run_id)->toBe($run->id)
        ->and($findings[0]->brand)->toBe('Bosch')
        ->and((float) $findings[0]->price)->toBe(5.0)
        ->and($findings[0]->in_stock)->toBeFalse()
        ->and($findings[1]->brand)->toBe('Mann')
        ->and((float) $findings[1]->price)->toBe(10.5)
        ->and($findings[1]->in_stock)->toBeTrue();
});

it('replaces previous findings for the same lookup', function (): void {
    $run = SearchRun::factory()->create();
    $lookup = SupplierLookup::factory()->for($run, 'run')->create([
        'supplier' => Supplier::AutoZitania,
        'status' => SupplierLookupStatus::Done,
        'result' => [
            'query' => 'X',
            'variants' => [
                [
                    'brandName' => 'Old',
                    'articleNumber' => 'OLD',
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

    resolve(PersistLookupFindings::class)->execute($lookup);

    $lookup->update([
        'result' => [
            'query' => 'X',
            'variants' => [
                [
                    'brandName' => 'New',
                    'articleNumber' => 'NEW',
                    'traderArticleNumber' => '',
                    'purchasePrice' => 3.0,
                    'retailPrice' => 4.5,
                    'currency' => 'EUR',
                    'availableQuantity' => 2,
                    'inStock' => true,
                    'warehouse' => '',
                ],
            ],
            'searchUrl' => null,
        ],
    ]);

    resolve(PersistLookupFindings::class)->execute($lookup->refresh());

    $findings = Finding::query()->where('supplier_lookup_id', $lookup->id)->get();

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->brand)->toBe('New')
        ->and((float) $findings[0]->price)->toBe(4.5);
});

it('deletes findings when the lookup has no result payload', function (): void {
    $run = SearchRun::factory()->create();
    $lookup = SupplierLookup::factory()->for($run, 'run')->create([
        'supplier' => Supplier::AutoDelta,
        'result' => [
            'query' => 'X',
            'variants' => [
                [
                    'brandName' => 'Mann',
                    'articleNumber' => 'OC90',
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

    resolve(PersistLookupFindings::class)->execute($lookup);

    $lookup->update(['result' => null]);
    resolve(PersistLookupFindings::class)->execute($lookup->refresh());

    expect(Finding::query()->where('supplier_lookup_id', $lookup->id)->count())->toBe(0);
});
