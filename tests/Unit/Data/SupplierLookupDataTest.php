<?php

declare(strict_types=1);

use App\Data\SupplierLookupData;
use App\Enums\Supplier;
use App\Enums\SupplierLookupStatus;
use App\Models\SupplierLookup;

it('builds SupplierLookupData from a lookup with a stored result', function (): void {
    $lookup = SupplierLookup::factory()->create([
        'supplier' => Supplier::AutoDelta,
        'status' => SupplierLookupStatus::Done,
        'result' => [
            'query' => 'OC 90',
            'variants' => [[
                'brandName' => 'JAPANPARTS',
                'articleNumber' => 'FO-398S',
                'traderArticleNumber' => 'JFO-398',
                'purchasePrice' => 1.70,
                'retailPrice' => 2.26,
                'currency' => 'EUR',
                'availableQuantity' => 23,
                'inStock' => true,
                'warehouse' => '1 - Leiria',
            ]],
            'searchUrl' => null,
        ],
    ]);

    $data = SupplierLookupData::fromModel($lookup);

    expect($data->supplier)->toBe(Supplier::AutoDelta)
        ->and($data->status)->toBe(SupplierLookupStatus::Done)
        ->and($data->result?->query)->toBe('OC 90')
        ->and($data->result?->variants)->toHaveCount(1)
        ->and($data->result?->variants[0]->brandName)->toBe('JAPANPARTS')
        ->and($data->jsonSerialize())->toHaveKeys(['id', 'supplier', 'query', 'oeDescription', 'status', 'result']);
});

it('builds SupplierLookupData from a lookup with no stored result', function (): void {
    $lookup = SupplierLookup::factory()->create(['result' => null]);

    $data = SupplierLookupData::fromModel($lookup);

    expect($data->result)->toBeNull();
});
