<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\PartSearchResult;
use App\Data\PartVariant;
use App\Findings\MapVariantToFindingAttributes;
use App\Models\Finding;
use App\Models\SupplierLookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class PersistLookupFindings
{
    public function execute(SupplierLookup $lookup): void
    {
        $result = $lookup->result;

        if ($result === null) {
            Finding::query()
                ->where('supplier_lookup_id', $lookup->id)
                ->delete();

            return;
        }

        $searchResult = PartSearchResult::fromArray($result);

        DB::transaction(function () use ($lookup, $searchResult): void {
            Finding::query()
                ->where('supplier_lookup_id', $lookup->id)
                ->delete();

            if ($searchResult->variants === []) {
                return;
            }

            $now = now();

            $rows = array_map(
                function (PartVariant $variant) use ($lookup, $now): array {
                    $attrs = MapVariantToFindingAttributes::map($lookup->supplier, $variant);

                    return [
                        'id' => (string) Str::uuid(),
                        'search_run_id' => $lookup->search_run_id,
                        'supplier_lookup_id' => $lookup->id,
                        'supplier' => $attrs['supplier']->value,
                        'brand' => $attrs['brand'],
                        'article' => $attrs['article'],
                        'trader_article_number' => $attrs['trader_article_number'],
                        'price' => $attrs['price'],
                        'currency' => $attrs['currency'],
                        'available_quantity' => $attrs['available_quantity'],
                        'in_stock' => $attrs['in_stock'],
                        'warehouse' => $attrs['warehouse'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                },
                $searchResult->variants,
            );

            Finding::query()->insert($rows);
        });
    }
}
