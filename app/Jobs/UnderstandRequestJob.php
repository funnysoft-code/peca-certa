<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\UnderstandPartRequest;
use App\Enums\SearchRunStatus;
use App\Events\SearchRunAdvanced;
use App\Models\SearchRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\SerializesModels;
use Throwable;

#[Timeout(120)]
#[Tries(1)]
final class UnderstandRequestJob implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly SearchRun $run,
    ) {
        $this->onQueue('ai');
    }

    public function handle(UnderstandPartRequest $understand): void
    {
        $this->run->status = SearchRunStatus::Running;
        $this->run->save();

        $understanding = $understand->execute((string) $this->run->request_text);

        $this->run->understanding = $understanding->jsonSerialize();
        $this->run->save();

        event(new SearchRunAdvanced($this->run));

        if ($understanding->needsClarification()) {
            $this->run->status = SearchRunStatus::Done;
            $this->run->save();

            event(new SearchRunAdvanced($this->run));
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = $this->run->fresh();

        if (! $run instanceof SearchRun) {
            return;
        }

        if ($run->status->isTerminal()) {
            return;
        }

        $run->status = SearchRunStatus::Failed;
        $run->save();

        event(new SearchRunAdvanced($run));
    }
}
