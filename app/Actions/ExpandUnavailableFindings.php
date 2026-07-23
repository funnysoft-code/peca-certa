<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\SearchRunStatus;
use App\Enums\SupplierLookupStatus;
use App\Events\SearchRunAdvanced;
use App\Jobs\PriceSupplierJob;
use App\Models\SearchRun;
use App\Models\SupplierLookup;

/**
 * Re-price every lookup with unavailable variants included.
 *
 * Default pricing only persists in-stock rows. Operators who open
 * "Mostrar indisponíveis" trigger this once per run.
 */
final readonly class ExpandUnavailableFindings
{
    public function execute(SearchRun $run): bool
    {
        if ($run->unavailable_included) {
            return false;
        }

        $busy = $run->lookups()
            ->whereIn('status', [SupplierLookupStatus::Pending, SupplierLookupStatus::Running])
            ->exists();

        if ($busy) {
            return false;
        }

        $lookups = $run->lookups()->get();

        if ($lookups->isEmpty()) {
            return false;
        }

        $run->status = SearchRunStatus::Running;
        $run->save();

        event(new SearchRunAdvanced($run));

        /** @var SupplierLookup $lookup */
        foreach ($lookups as $lookup) {
            $lookup->update([
                'status' => SupplierLookupStatus::Pending,
                'error' => null,
            ]);

            dispatch(new PriceSupplierJob($lookup, includeUnavailable: true));
        }

        return true;
    }
}
