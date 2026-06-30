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
