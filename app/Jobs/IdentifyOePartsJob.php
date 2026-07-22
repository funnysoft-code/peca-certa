<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\FanOutOePricing;
use App\Actions\IdentifyOeParts;
use App\Data\PartRequestUnderstanding;
use App\Enums\SearchRunStatus;
use App\Events\SearchRunAdvanced;
use App\Models\SearchRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Legacy identify OE step (text-only understand chain). Prefer IdentifyAgentJob for /identify.
 *
 * Kept for parts of the suite that still exercise the old Understand → Identify chain;
 * IdentifyController no longer dispatches this job.
 */
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

    public function handle(IdentifyOeParts $identify, FanOutOePricing $fanOut): void
    {
        $run = $this->run->fresh();

        if (! $run instanceof SearchRun) {
            return;
        }

        $terminalStatuses = [SearchRunStatus::Done, SearchRunStatus::Failed, SearchRunStatus::NeedsInput];

        if (in_array($run->status, $terminalStatuses, true)) {
            return;
        }

        $understanding = PartRequestUnderstanding::fromArray($run->understanding ?? []);

        $oeParts = $identify->execute((string) $run->vin, $understanding->searchTerm, $understanding->keywords);

        $fanOut->execute($run, $oeParts);
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
