<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\Supplier;
use App\Models\Finding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Shop-wide procurement aggregates over normalized findings only.
 *
 * @phpstan-type RankedRow array{label: string, count: int, in_stock_count: int, stock_rate: float, min_price: float|null, median_price: float|null, p25_price: float|null}
 * @phpstan-type ChartPoint array{name: string, value: int}
 * @phpstan-type WinnerStats array{pairs: int, autodelta_wins: int, autozitania_wins: int, ties: int, autodelta_win_rate: float, autozitania_win_rate: float}
 * @phpstan-type AnalyticsPayload array{
 *     range_days: int,
 *     from: string,
 *     to: string,
 *     scorecards: array{
 *         findings: int,
 *         in_stock: int,
 *         stock_hit_rate: float,
 *         brands: int,
 *         suppliers: int,
 *         with_price: int
 *     },
 *     suppliers_chart: list<ChartPoint>,
 *     brands_chart: list<ChartPoint>,
 *     stock_chart: list<ChartPoint>,
 *     ranked_brands: list<RankedRow>,
 *     ranked_suppliers: list<RankedRow>,
 *     head_to_head: WinnerStats
 * }
 */
final readonly class BuildProcurementAnalytics
{
    public const array AllowedRanges = [7, 30, 90];

    public const int DefaultRange = 30;

    /**
     * @return AnalyticsPayload
     */
    public function execute(int $rangeDays = self::DefaultRange): array
    {
        $days = in_array($rangeDays, self::AllowedRanges, true)
            ? $rangeDays
            : self::DefaultRange;

        $to = CarbonImmutable::now();
        $from = $to->subDays($days);

        $base = Finding::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to);

        $total = (clone $base)->count();
        $inStock = (clone $base)->where('in_stock', true)->count();
        $withPrice = (clone $base)->whereNotNull('price')->count();
        $brands = (clone $base)->distinct()->count('brand');
        $suppliers = (clone $base)->distinct()->count('supplier');

        /** @var Collection<int, object{supplier: mixed, aggregate_count: int|string|float}> $supplierRows */
        $supplierRows = (clone $base)
            ->selectRaw('supplier, COUNT(*) as aggregate_count')
            ->groupBy('supplier')
            ->orderByDesc('aggregate_count')
            ->toBase()
            ->get();

        /** @var Collection<int, object{brand: mixed, aggregate_count: int|string|float}> $brandRows */
        $brandRows = (clone $base)
            ->selectRaw('brand, COUNT(*) as aggregate_count')
            ->groupBy('brand')
            ->orderByDesc('aggregate_count')
            ->limit(10)
            ->toBase()
            ->get();

        /** @var list<ChartPoint> $suppliersChart */
        $suppliersChart = array_values($supplierRows
            ->map(fn (object $row): array => [
                'name' => $this->supplierLabel($this->supplierKey($row->supplier)),
                'value' => (int) $row->aggregate_count,
            ])
            ->all());

        /** @var list<ChartPoint> $brandsChart */
        $brandsChart = array_values($brandRows
            ->map(fn (object $row): array => [
                'name' => $this->stringKey($row->brand),
                'value' => (int) $row->aggregate_count,
            ])
            ->all());

        /** @var list<ChartPoint> $stockChart */
        $stockChart = [
            ['name' => 'Em stock', 'value' => $inStock],
            ['name' => 'Sem stock', 'value' => max(0, $total - $inStock)],
        ];

        return [
            'range_days' => $days,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'scorecards' => [
                'findings' => $total,
                'in_stock' => $inStock,
                'stock_hit_rate' => $this->rate($inStock, $total),
                'brands' => $brands,
                'suppliers' => $suppliers,
                'with_price' => $withPrice,
            ],
            'suppliers_chart' => $suppliersChart,
            'brands_chart' => $brandsChart,
            'stock_chart' => $stockChart,
            'ranked_brands' => $this->rankedDimension($from, $to, 'brand', 15),
            'ranked_suppliers' => $this->rankedDimension($from, $to, 'supplier', 10),
            'head_to_head' => $this->headToHead($from, $to),
        ];
    }

    /**
     * @param  'brand'|'supplier'  $column
     * @return list<RankedRow>
     */
    private function rankedDimension(
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $column,
        int $limit,
    ): array {
        $select = $column === 'brand'
            ? 'brand as key, COUNT(*) as aggregate_count, SUM(CASE WHEN in_stock = 1 THEN 1 ELSE 0 END) as in_stock_count'
            : 'supplier as key, COUNT(*) as aggregate_count, SUM(CASE WHEN in_stock = 1 THEN 1 ELSE 0 END) as in_stock_count';

        /** @var Collection<int, object{key: mixed, aggregate_count: int|string|float, in_stock_count: int|string|float}> $groups */
        $groups = Finding::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->selectRaw($select)
            ->groupBy($column)
            ->orderByDesc('aggregate_count')
            ->limit($limit)
            ->toBase()
            ->get();

        $keys = $groups
            ->map(fn (object $row): string => $column === 'supplier'
                ? $this->supplierKey($row->key)
                : $this->stringKey($row->key))
            ->all();

        $pricesByKey = Finding::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->whereNotNull('price')
            ->whereIn($column, $keys)
            ->get([$column, 'price'])
            ->groupBy(function (Finding $finding) use ($column): string {
                $value = $finding->getAttribute($column);

                return $column === 'supplier'
                    ? $this->supplierKey($value)
                    : $this->stringKey($value);
            });

        /** @var list<RankedRow> $ranked */
        $ranked = [];

        foreach ($groups as $row) {
            $key = $column === 'supplier'
                ? $this->supplierKey($row->key)
                : $this->stringKey($row->key);
            $count = (int) $row->aggregate_count;
            $inStockCount = (int) $row->in_stock_count;
            /** @var Collection<int, Finding> $priceRows */
            $priceRows = $pricesByKey->get($key, collect());
            $prices = $priceRows
                ->map(fn (Finding $finding): float => (float) $finding->price)
                ->sort()
                ->values();

            $ranked[] = [
                'label' => $column === 'supplier' ? $this->supplierLabel($key) : $key,
                'count' => $count,
                'in_stock_count' => $inStockCount,
                'stock_rate' => $this->rate($inStockCount, $count),
                'min_price' => $prices->isEmpty() ? null : $prices->first(),
                'p25_price' => $this->percentile($prices, 0.25),
                'median_price' => $this->percentile($prices, 0.5),
            ];
        }

        return $ranked;
    }

    /**
     * @return WinnerStats
     */
    private function headToHead(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $inStockPriced = Finding::query()
            ->where('created_at', '>=', $from)
            ->where('created_at', '<=', $to)
            ->where('in_stock', true)
            ->whereNotNull('price')
            ->whereIn('supplier', [
                Supplier::AutoDelta->value,
                Supplier::AutoZitania->value,
            ])
            ->get(['supplier', 'brand', 'article', 'price']);

        /** @var array<string, array<string, float>> $best */
        $best = [];

        foreach ($inStockPriced as $finding) {
            $pairKey = mb_strtolower(mb_trim($finding->brand)).'|'.mb_strtolower(mb_trim($finding->article));
            $supplier = $finding->supplier->value;
            $price = (float) $finding->price;

            if (! isset($best[$pairKey][$supplier]) || $price < $best[$pairKey][$supplier]) {
                $best[$pairKey][$supplier] = $price;
            }
        }

        $pairs = 0;
        $deltaWins = 0;
        $zitaniaWins = 0;
        $ties = 0;

        foreach ($best as $prices) {
            if (! isset($prices[Supplier::AutoDelta->value], $prices[Supplier::AutoZitania->value])) {
                continue;
            }

            $pairs++;
            $delta = $prices[Supplier::AutoDelta->value];
            $zitania = $prices[Supplier::AutoZitania->value];

            if ($delta < $zitania) {
                $deltaWins++;
            } elseif ($zitania < $delta) {
                $zitaniaWins++;
            } else {
                $ties++;
            }
        }

        return [
            'pairs' => $pairs,
            'autodelta_wins' => $deltaWins,
            'autozitania_wins' => $zitaniaWins,
            'ties' => $ties,
            'autodelta_win_rate' => $this->rate($deltaWins, $pairs),
            'autozitania_win_rate' => $this->rate($zitaniaWins, $pairs),
        ];
    }

    /**
     * @param  Collection<int, float>  $sortedPrices
     */
    private function percentile(Collection $sortedPrices, float $p): ?float
    {
        if ($sortedPrices->isEmpty()) {
            return null;
        }

        $count = $sortedPrices->count();
        $index = (int) floor(($count - 1) * $p);

        return (float) $sortedPrices->values()->get($index);
    }

    private function rate(int $part, int $whole): float
    {
        if ($whole === 0) {
            return 0.0;
        }

        return round(($part / $whole) * 100, 1);
    }

    private function supplierLabel(string $supplier): string
    {
        return match ($supplier) {
            Supplier::AutoDelta->value => 'Auto Delta',
            Supplier::AutoZitania->value => 'Auto Zitânia',
            default => $supplier,
        };
    }

    private function supplierKey(mixed $supplier): string
    {
        if ($supplier instanceof Supplier) {
            return $supplier->value;
        }

        return $this->stringKey($supplier);
    }

    private function stringKey(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value instanceof Supplier) {
            return $value->value;
        }

        return '';
    }
}
