<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\RunIdentifyAgentTurn;
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
        // Share PL24 session mutex with catalog work.
        return [
            new WithoutOverlapping('partslink24')->expireAfter(150),
            new WithoutOverlapping('identify-agent:'.$this->run->id)->expireAfter(150),
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
        $run->save();

        event(new SearchRunAdvanced($run));
    }
}
