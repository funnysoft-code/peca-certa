<?php

declare(strict_types=1);

use App\Data\PartVariant;

it('merges articles with E/V price rows into one variant each', function (): void {
    $articles = [[
        'dataSupplierId' => 156, 'mfrId' => 2194, 'brandName' => 'JAPANPARTS',
        'articleNumber' => 'FO-398S',
    ]];
    $priceRows = [
        ['dataSupplierId' => 156, 'articleNumber' => 'FO-398S', 'traderArticleNumber' => 'JFO-398', 'priceTypeKey' => 'E', 'price' => 1.70, 'currencyCode' => 'EUR', 'availableQuantity' => 23, 'stockStatusDescription' => 'em stock', 'stockMatchCode' => '1 - Leiria,'],
        ['dataSupplierId' => 156, 'articleNumber' => 'FO-398S', 'traderArticleNumber' => 'JFO-398', 'priceTypeKey' => 'V', 'price' => 2.26, 'currencyCode' => 'EUR', 'availableQuantity' => 23, 'stockStatusDescription' => 'em stock', 'stockMatchCode' => '1 - Leiria,'],
    ];

    $result = PartVariant::merge($articles, $priceRows);

    expect($result->query)->toBe('')
        ->and($result->variants)->toHaveCount(1);

    $v = $result->variants[0];
    expect($v->brandName)->toBe('JAPANPARTS')
        ->and($v->traderArticleNumber)->toBe('JFO-398')
        ->and($v->purchasePrice)->toBe(1.70)
        ->and($v->retailPrice)->toBe(2.26)
        ->and($v->availableQuantity)->toBe(23)
        ->and($v->inStock)->toBeTrue()
        ->and($v->warehouse)->toBe('1 - Leiria');
});

it('sums stock across warehouses and reports the best-stocked location', function (): void {
    $articles = [[
        'dataSupplierId' => 156, 'mfrId' => 2194, 'brandName' => 'JAPANPARTS',
        'articleNumber' => 'FO-398S',
    ]];

    // Same article in four warehouses: Leiria 23, C.Branco 0, ALECARPEÇAS 4, FIMAG 0.
    $warehouses = [
        ['1 - Leiria,', 23],
        ['2 - C. Branco,', 0],
        ['4 - ALECARPEÇAS,', 4],
        ['5 - FIMAG,', 0],
    ];
    $priceRows = [];
    foreach ($warehouses as [$wh, $qty]) {
        foreach (['E' => 1.70, 'V' => 2.26] as $type => $price) {
            $priceRows[] = [
                'dataSupplierId' => 156, 'articleNumber' => 'FO-398S', 'traderArticleNumber' => 'JFO-398',
                'priceTypeKey' => $type, 'price' => $price, 'currencyCode' => 'EUR',
                'availableQuantity' => $qty, 'stockStatusDescription' => 'em stock', 'stockMatchCode' => $wh,
            ];
        }
    }

    $v = PartVariant::merge($articles, $priceRows)->variants[0];

    expect($v->availableQuantity)->toBe(27)
        ->and($v->inStock)->toBeTrue()
        ->and($v->warehouse)->toBe('1 - Leiria')
        ->and($v->purchasePrice)->toBe(1.70)
        ->and($v->retailPrice)->toBe(2.26);
});

it('reports zero stock and no warehouse loss when all warehouses are empty', function (): void {
    $articles = [[
        'dataSupplierId' => 350, 'mfrId' => 3063, 'brandName' => 'BLUE PRINT',
        'articleNumber' => 'ADG02102',
    ]];
    $priceRows = [
        ['dataSupplierId' => 350, 'articleNumber' => 'ADG02102', 'traderArticleNumber' => 'BPADG02102', 'priceTypeKey' => 'E', 'price' => 2.65, 'currencyCode' => 'EUR', 'availableQuantity' => 0, 'stockStatusDescription' => 'em stock', 'stockMatchCode' => '2 - C. Branco,'],
        ['dataSupplierId' => 350, 'articleNumber' => 'ADG02102', 'traderArticleNumber' => 'BPADG02102', 'priceTypeKey' => 'V', 'price' => 3.31, 'currencyCode' => 'EUR', 'availableQuantity' => 0, 'stockStatusDescription' => 'em stock', 'stockMatchCode' => '2 - C. Branco,'],
    ];

    $v = PartVariant::merge($articles, $priceRows)->variants[0];

    expect($v->availableQuantity)->toBe(0)
        ->and($v->inStock)->toBeFalse()
        ->and($v->purchasePrice)->toBe(2.65)
        ->and($v->warehouse)->toBe('2 - C. Branco');
});

it('keeps articles without price rows as unavailable variants (TecDoc cross-references Auto Delta does not carry)', function (): void {
    $articles = [
        ['dataSupplierId' => 999, 'mfrId' => 1, 'brandName' => 'ORPHAN', 'articleNumber' => 'X1'],
        ['dataSupplierId' => 156, 'mfrId' => 2194, 'brandName' => 'JAPANPARTS', 'articleNumber' => 'FO-398S'],
    ];
    $priceRows = [
        ['dataSupplierId' => 156, 'articleNumber' => 'FO-398S', 'traderArticleNumber' => 'JFO-398', 'priceTypeKey' => 'E', 'price' => 1.70, 'currencyCode' => 'EUR', 'availableQuantity' => 23, 'stockStatusDescription' => 'em stock', 'stockMatchCode' => '1 - Leiria,'],
    ];

    $result = PartVariant::merge($articles, $priceRows);

    expect($result->variants)->toHaveCount(2);

    $orphan = $result->variants[0];
    expect($orphan->brandName)->toBe('ORPHAN')
        ->and($orphan->purchasePrice)->toBeNull()
        ->and($orphan->retailPrice)->toBeNull()
        ->and($orphan->availableQuantity)->toBe(0)
        ->and($orphan->inStock)->toBeFalse();

    expect($result->variants[1]->brandName)->toBe('JAPANPARTS')
        ->and($result->variants[1]->inStock)->toBeTrue();
});
