<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\Supplier;
use App\Models\Finding;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class FindingData implements JsonSerializable
{
    public function __construct(
        public string $id,
        public Supplier $supplier,
        public string $brand,
        public string $article,
        public string $traderArticleNumber,
        public ?float $price,
        public string $currency,
        public int $availableQuantity,
        public bool $inStock,
        public string $warehouse,
    ) {}

    public static function fromModel(Finding $finding): self
    {
        return new self(
            id: $finding->id,
            supplier: $finding->supplier,
            brand: $finding->brand,
            article: $finding->article,
            traderArticleNumber: $finding->trader_article_number,
            price: $finding->price === null ? null : (float) $finding->price,
            currency: $finding->currency,
            availableQuantity: $finding->available_quantity,
            inStock: $finding->in_stock,
            warehouse: $finding->warehouse,
        );
    }

    /**
     * @return array{
     *     id: string,
     *     supplier: Supplier,
     *     brand: string,
     *     article: string,
     *     traderArticleNumber: string,
     *     price: float|null,
     *     currency: string,
     *     availableQuantity: int,
     *     inStock: bool,
     *     warehouse: string
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'supplier' => $this->supplier,
            'brand' => $this->brand,
            'article' => $this->article,
            'traderArticleNumber' => $this->traderArticleNumber,
            'price' => $this->price,
            'currency' => $this->currency,
            'availableQuantity' => $this->availableQuantity,
            'inStock' => $this->inStock,
            'warehouse' => $this->warehouse,
        ];
    }
}
