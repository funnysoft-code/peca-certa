<?php

declare(strict_types=1);

use App\Data\PartSearchResult;

it('rebuilds a PartSearchResult with variants from a stored array', function (): void {
    $result = PartSearchResult::fromArray([
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
        'searchUrl' => 'https://example.test',
    ]);

    expect($result->query)->toBe('OC 90')
        ->and($result->searchUrl)->toBe('https://example.test')
        ->and($result->variants)->toHaveCount(1)
        ->and($result->variants[0]->brandName)->toBe('JAPANPARTS')
        ->and($result->variants[0]->purchasePrice)->toBe(1.70);
});

it('rebuilds a PartSearchResult from an empty or malformed array', function (): void {
    $result = PartSearchResult::fromArray([]);

    expect($result->query)->toBe('')
        ->and($result->variants)->toBe([])
        ->and($result->searchUrl)->toBeNull();
});

it('round-trips a PartSearchResult through jsonSerialize and fromArray', function (): void {
    $original = PartSearchResult::fromArray([
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
    ]);

    /** @var array<string, mixed> $stored */
    $stored = json_decode(json_encode($original, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $roundTripped = PartSearchResult::fromArray($stored);

    expect($roundTripped)->toEqual($original);
});
