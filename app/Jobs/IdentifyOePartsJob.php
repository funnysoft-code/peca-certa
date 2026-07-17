<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\IdentifyOeParts;
use App\Data\OePart;
use App\Data\PartRequestUnderstanding;
use App\Enums\SearchRunStatus;
use App\Enums\Supplier;
use App\Enums\SupplierLookupStatus;
use App\Events\SearchRunAdvanced;
use App\Models\SearchRun;
use App\Models\SupplierLookup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

#[Timeout(90)]
#[Tries(2)]
final class IdentifyOePartsJob implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly SearchRun $run,
    ) {
        $this->onQueue('partslink24');
    }

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [new WithoutOverlapping('partslink24')->expireAfter(150)];
    }

    public function handle(IdentifyOeParts $identify): void
    {
        $run = $this->run->fresh();

        if (! $run instanceof SearchRun) {
            return;
        }

        $terminalStatuses = [SearchRunStatus::Done, SearchRunStatus::Failed];

        if (in_array($run->status, $terminalStatuses, true)) {
            return;
        }

        $understanding = PartRequestUnderstanding::fromArray($run->understanding ?? []);

        $oeParts = $identify->execute((string) $run->vin, $understanding->searchTerm, $understanding->keywords);

        $run->oe_parts = array_map(
            fn (OePart $part): array => $part->jsonSerialize(),
            $oeParts,
        );
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

    public function failed(?Throwable $exception): void
    {
        $run = $this->run->fresh();

        if (! $run instanceof SearchRun) {
            return;
        }

        $terminalStatuses = [SearchRunStatus::Done, SearchRunStatus::Failed];

        if (in_array($run->status, $terminalStatuses, true)) {
            return;
        }

        $run->status = SearchRunStatus::Failed;
        $run->save();

        event(new SearchRunAdvanced($run));
    }
}
