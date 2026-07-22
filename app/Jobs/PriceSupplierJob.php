<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\PersistLookupFindings;
use App\Actions\SearchAutoDeltaParts;
use App\Actions\SearchAutoZitaniaParts;
use App\Enums\SearchRunStatus;
use App\Enums\Supplier;
use App\Enums\SupplierLookupStatus;
use App\Events\SearchRunAdvanced;
use App\Events\SupplierResultReady;
use App\Models\SearchRun;
use App\Models\SupplierLookup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Tries(2)]
final class PriceSupplierJob implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public int $timeout;

    public function __construct(
        public readonly SupplierLookup $lookup,
    ) {
        $this->onQueue($lookup->supplier === Supplier::AutoZitania ? 'zitania' : 'autodelta');
        $this->timeout = $lookup->supplier === Supplier::AutoZitania ? 90 : 30;
    }

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return $this->lookup->supplier === Supplier::AutoZitania
            ? [new WithoutOverlapping('zitania')->expireAfter(150)]
            : [];
    }

    public function handle(
        SearchAutoDeltaParts $autoDelta,
        SearchAutoZitaniaParts $autoZitania,
        PersistLookupFindings $persistLookupFindings,
    ): void {
        $result = $this->lookup->supplier === Supplier::AutoZitania
            ? $autoZitania->execute($this->lookup->query)
            : $autoDelta->execute($this->lookup->query);

        $this->lookup->update([
            'result' => $result->jsonSerialize(),
            'status' => $result->variants === [] ? SupplierLookupStatus::Empty : SupplierLookupStatus::Done,
        ]);

        $persistLookupFindings->execute($this->lookup->refresh());

        event(new SupplierResultReady($this->lookup));

        $this->completeRunIfFinished();
    }

    public function failed(Throwable $e): void
    {
        $this->lookup->update([
            'status' => SupplierLookupStatus::Failed,
            'error' => $e->getMessage(),
        ]);

        event(new SupplierResultReady($this->lookup));

        $this->completeRunIfFinished();
    }

    private function completeRunIfFinished(): void
    {
        $run = null;

        DB::transaction(function () use (&$run): void {
            $run = SearchRun::query()->whereKey($this->lookup->search_run_id)->lockForUpdate()->first();

            $terminalStatuses = [SearchRunStatus::Done, SearchRunStatus::Failed];

            if (! $run instanceof SearchRun || in_array($run->status, $terminalStatuses, true)) {
                $run = null;

                return;
            }

            $unfinished = $run->lookups()
                ->whereIn('status', [SupplierLookupStatus::Pending, SupplierLookupStatus::Running])
                ->count();

            if ($unfinished > 0) {
                $run = null;

                return;
            }

            $run->status = SearchRunStatus::Done;
            $run->save();
        });

        if ($run instanceof SearchRun) {
            event(new SearchRunAdvanced($run));
        }
    }
}
