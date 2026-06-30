<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class PartVariant
{
    public function __construct(
        public string $brandName,
        public string $articleNumber,
        public string $traderArticleNumber,
        public ?float $purchasePrice,
        public ?float $retailPrice,
        public string $currency,
        public int $availableQuantity,
        public bool $inStock,
        public string $warehouse,
    ) {}

    /**
     * @param  list<array{dataSupplierId: int, mfrId: int, brandName: string, articleNumber: string}>  $articles
     * @param  list<array{dataSupplierId: int, articleNumber: string, traderArticleNumber: string, priceTypeKey: string, price: float, currencyCode: string, availableQuantity: int, stockStatusDescription: string, stockMatchCode: string}>  $priceRows
     */
    public static function merge(array $articles, array $priceRows, string $query = ''): PartSearchResult
    {
        $pricesByKey = [];
        foreach ($priceRows as $row) {
            $key = $row['dataSupplierId'].'.'.$row['articleNumber'];
            $pricesByKey[$key][$row['priceTypeKey']] = $row;
        }

        $variants = [];
        foreach ($articles as $a) {
            $key = $a['dataSupplierId'].'.'.$a['articleNumber'];
            $purchase = $pricesByKey[$key]['E'] ?? null;
            $retail = $pricesByKey[$key]['V'] ?? null;
            $any = $purchase ?? $retail;

            $variants[] = new self(
                brandName: $a['brandName'],
                articleNumber: $a['articleNumber'],
                traderArticleNumber: $any['traderArticleNumber'] ?? '',
                purchasePrice: $purchase['price'] ?? null,
                retailPrice: $retail['price'] ?? null,
                currency: $any['currencyCode'] ?? 'EUR',
                availableQuantity: $any['availableQuantity'] ?? 0,
                inStock: ($any['availableQuantity'] ?? 0) > 0,
                warehouse: mb_trim($any['stockMatchCode'] ?? '', " ,\t\n"),
            );
        }

        return new PartSearchResult($query, $variants);
    }
}
