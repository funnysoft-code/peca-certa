<?php

declare(strict_types=1);

namespace App\Data;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * @phpstan-type PriceRow array{dataSupplierId: int, articleNumber: string, traderArticleNumber: string, priceTypeKey: string, price: float, currencyCode: string, availableQuantity: int, stockStatusDescription: string, stockMatchCode: string}
 */
#[TypeScript]
final readonly class PartVariant implements JsonSerializable
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
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            brandName: is_string($data['brandName'] ?? null) ? $data['brandName'] : '',
            articleNumber: is_string($data['articleNumber'] ?? null) ? $data['articleNumber'] : '',
            traderArticleNumber: is_string($data['traderArticleNumber'] ?? null) ? $data['traderArticleNumber'] : '',
            purchasePrice: is_numeric($data['purchasePrice'] ?? null) ? (float) $data['purchasePrice'] : null,
            retailPrice: is_numeric($data['retailPrice'] ?? null) ? (float) $data['retailPrice'] : null,
            currency: is_string($data['currency'] ?? null) ? $data['currency'] : '',
            availableQuantity: is_numeric($data['availableQuantity'] ?? null) ? (int) $data['availableQuantity'] : 0,
            inStock: is_bool($data['inStock'] ?? null) && $data['inStock'],
            warehouse: is_string($data['warehouse'] ?? null) ? $data['warehouse'] : '',
        );
    }

    /**
     * Each article comes back with one purchase (E) and one retail (V) price row
     * per warehouse. Stock is summed across warehouses; the price/location shown
     * is taken from the best-stocked warehouse so an in-stock part is never
     * reported as out of stock.
     *
     * @param  list<array{dataSupplierId: int, mfrId: int, brandName: string, articleNumber: string}>  $articles
     * @param  list<PriceRow>  $priceRows
     */
    public static function merge(array $articles, array $priceRows, string $query = ''): PartSearchResult
    {
        $rowsByKey = [];
        foreach ($priceRows as $row) {
            $key = $row['dataSupplierId'].'.'.$row['articleNumber'];
            $rowsByKey[$key][$row['priceTypeKey']][] = $row;
        }

        $variants = [];
        foreach ($articles as $a) {
            $key = $a['dataSupplierId'].'.'.$a['articleNumber'];
            $purchaseRows = $rowsByKey[$key]['E'] ?? [];
            $retailRows = $rowsByKey[$key]['V'] ?? [];
            $stockRows = $purchaseRows !== [] ? $purchaseRows : $retailRows;

            // Unpriced articles are pure TecDoc cross-references Auto Delta
            // does not carry: keep them as unavailable variants so the UI can
            // list them under the collapsed "Indisponíveis" section.
            if ($stockRows === []) {
                $variants[] = new self(
                    brandName: $a['brandName'],
                    articleNumber: $a['articleNumber'],
                    traderArticleNumber: '',
                    purchasePrice: null,
                    retailPrice: null,
                    currency: 'EUR',
                    availableQuantity: 0,
                    inStock: false,
                    warehouse: '',
                );

                continue;
            }

            $totalQuantity = 0;
            foreach ($stockRows as $row) {
                $totalQuantity += $row['availableQuantity'];
            }

            $primary = self::bestStocked($stockRows);
            $bestPurchase = self::bestStocked($purchaseRows);
            $bestRetail = self::bestStocked($retailRows);

            $variants[] = new self(
                brandName: $a['brandName'],
                articleNumber: $a['articleNumber'],
                traderArticleNumber: $primary['traderArticleNumber'] ?? '',
                purchasePrice: $bestPurchase['price'] ?? null,
                retailPrice: $bestRetail['price'] ?? null,
                currency: $primary['currencyCode'] ?? 'EUR',
                availableQuantity: $totalQuantity,
                inStock: $totalQuantity > 0,
                warehouse: mb_trim($primary['stockMatchCode'] ?? '', " ,\t\n"),
            );
        }

        return new PartSearchResult($query, $variants);
    }

    /**
     * @return array{brandName: string, articleNumber: string, traderArticleNumber: string, purchasePrice: float|null, retailPrice: float|null, currency: string, availableQuantity: int, inStock: bool, warehouse: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'brandName' => $this->brandName,
            'articleNumber' => $this->articleNumber,
            'traderArticleNumber' => $this->traderArticleNumber,
            'purchasePrice' => $this->purchasePrice,
            'retailPrice' => $this->retailPrice,
            'currency' => $this->currency,
            'availableQuantity' => $this->availableQuantity,
            'inStock' => $this->inStock,
            'warehouse' => $this->warehouse,
        ];
    }

    /**
     * @param  list<PriceRow>  $rows
     * @return PriceRow|null
     */
    private static function bestStocked(array $rows): ?array
    {
        $best = null;
        foreach ($rows as $row) {
            if ($best === null || $row['availableQuantity'] > $best['availableQuantity']) {
                $best = $row;
            }
        }

        return $best;
    }
}
