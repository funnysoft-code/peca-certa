<?php

declare(strict_types=1);

use App\Data\PartVariant;
use App\Enums\Supplier;
use App\Findings\MapVariantToFindingAttributes;

it('freezes Auto Delta price to purchase price', function (): void {
    $variant = new PartVariant(
        brandName: 'Mann',
        articleNumber: 'OC90',
        traderArticleNumber: 'T-1',
        purchasePrice: 12.5,
        retailPrice: 19.9,
        currency: 'EUR',
        availableQuantity: 3,
        inStock: true,
        warehouse: 'A',
    );

    $attrs = MapVariantToFindingAttributes::map(Supplier::AutoDelta, $variant);

    expect($attrs['price'])->toBe(12.5)
        ->and($attrs['brand'])->toBe('Mann')
        ->and($attrs['article'])->toBe('OC90')
        ->and($attrs['in_stock'])->toBeTrue();
});

it('freezes Auto Zitânia price to retail price', function (): void {
    $variant = new PartVariant(
        brandName: 'Mann',
        articleNumber: 'OC90',
        traderArticleNumber: 'T-1',
        purchasePrice: 12.5,
        retailPrice: 19.9,
        currency: 'EUR',
        availableQuantity: 0,
        inStock: false,
        warehouse: '',
    );

    $attrs = MapVariantToFindingAttributes::map(Supplier::AutoZitania, $variant);

    expect($attrs['price'])->toBe(19.9)
        ->and($attrs['in_stock'])->toBeFalse();
});
