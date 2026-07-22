<?php

declare(strict_types=1);

namespace App\Findings;

use App\Data\PartVariant;
use App\Enums\Supplier;

/**
 * Pure mapping from a supplier variant to denormalized finding row attributes.
 * Price is frozen at write time: Auto Delta = purchase, Auto Zitânia = retail.
 */
final class MapVariantToFindingAttributes
{
    /**
     * @return array{
     *     supplier: Supplier,
     *     brand: string,
     *     article: string,
     *     trader_article_number: string,
     *     price: float|null,
     *     currency: string,
     *     available_quantity: int,
     *     in_stock: bool,
     *     warehouse: string
     * }
     */
    public static function map(Supplier $supplier, PartVariant $variant): array
    {
        return [
            'supplier' => $supplier,
            'brand' => $variant->brandName,
            'article' => $variant->articleNumber,
            'trader_article_number' => $variant->traderArticleNumber,
            'price' => self::frozenPrice($supplier, $variant),
            'currency' => $variant->currency !== '' ? $variant->currency : 'EUR',
            'available_quantity' => $variant->availableQuantity,
            'in_stock' => $variant->inStock,
            'warehouse' => $variant->warehouse,
        ];
    }

    public static function frozenPrice(Supplier $supplier, PartVariant $variant): ?float
    {
        return match ($supplier) {
            Supplier::AutoDelta => $variant->purchasePrice,
            Supplier::AutoZitania => $variant->retailPrice,
        };
    }
}
