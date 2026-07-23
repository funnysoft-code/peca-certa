<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\FinalizeFailedIdentifySteps;
use App\Actions\RunIdentifyAgentTurn;
use App\Enums\SearchRunStatus;
use App\Events\SearchRunAdvanced;
use App\Models\SearchRun;
use App\Support\SupplierSessionLock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

#[Timeout(120)]
#[Tries(1)]
final class IdentifyAgentJob implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly SearchRun $run,
    ) {
        $this->onQueue('ai');
    }

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        // PL24 session mutex shared with IdentifyOePartsJob (and any future PL24 jobs).
        return [
            SupplierSessionLock::partsLink24(),
            new WithoutOverlapping('identify-agent:'.$this->run->id)
                ->expireAfter(SupplierSessionLock::ExpiresAfterSeconds),
        ];
    }

    public function handle(RunIdentifyAgentTurn $runTurn): void
    {
        $run = $this->run->fresh();

        if (! $run instanceof SearchRun) {
            return;
        }

        if ($run->status->isTerminal() || $run->status === SearchRunStatus::NeedsInput) {
            // NeedsInput only resumes via ResumeIdentifyRun (status reset to pending first).
            return;
        }

        $runTurn->execute($run);
    }

    public function failed(?Throwable $exception): void
    {
        $run = $this->run->fresh();

        if (! $run instanceof SearchRun || $run->status->isTerminal()) {
            return;
        }

        $run->status = SearchRunStatus::Failed;
        resolve(FinalizeFailedIdentifySteps::class)->execute($run, $exception);

        event(new SearchRunAdvanced($run->fresh() ?? $run));
    }
}
