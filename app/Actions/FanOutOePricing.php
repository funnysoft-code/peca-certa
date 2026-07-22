<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\OePart;
use App\Enums\SearchRunStatus;
use App\Enums\Supplier;
use App\Enums\SupplierLookupStatus;
use App\Events\SearchRunAdvanced;
use App\Jobs\PriceSupplierJob;
use App\Models\SearchRun;
use App\Models\SupplierLookup;

final readonly class FanOutOePricing
{
    /**
     * Persist final OE parts and dispatch supplier pricing only for those OEs.
     *
     * @param  list<OePart>  $oeParts
     */
    public function execute(SearchRun $run, array $oeParts): void
    {
        $run->oe_parts = array_map(
            fn (OePart $part): array => $part->jsonSerialize(),
            $oeParts,
        );
        $run->pending_question = null;
        $run->save();

        event(new SearchRunAdvanced($run));

        if ($oeParts === []) {
            $run->status = SearchRunStatus::Done;
            $run->save();
            event(new SearchRunAdvanced($run));

            return;
        }

        /** @var list<SupplierLookup> $createdLookups */
        $createdLookups = [];

        foreach ($oeParts as $part) {
            foreach ([Supplier::AutoDelta, Supplier::AutoZitania] as $supplier) {
                $lookup = SupplierLookup::query()->firstOrCreate([
                    'search_run_id' => $run->id,
                    'supplier' => $supplier,
                    'query' => $part->oeNumber,
                ], [
                    'oe_description' => $part->description,
                    'status' => SupplierLookupStatus::Pending,
                ]);

                if ($lookup->wasRecentlyCreated) {
                    $createdLookups[] = $lookup;
                }
            }
        }

        foreach ($createdLookups as $createdLookup) {
            dispatch(new PriceSupplierJob($createdLookup));
        }
    }
}
