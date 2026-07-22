<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ReapOrphanedSearchRuns;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('search-runs:reap-orphaned {--minutes= : Override peca.orphan_reap_after_minutes}')]
#[Description('Fail stuck pending/running supplier lookups and close orphaned search runs')]
final class ReapOrphanedSearchRunsCommand extends Command
{
    public function handle(ReapOrphanedSearchRuns $reap): int
    {
        $minutesOption = $this->option('minutes');
        $minutes = is_numeric($minutesOption) ? (int) $minutesOption : null;

        if ($minutes !== null && $minutes < 1) {
            $this->error('The --minutes option must be a positive integer.');

            return self::FAILURE;
        }

        $closed = $reap->execute($minutes);

        $this->info(sprintf('Closed %d orphaned search run(s).', $closed));

        return self::SUCCESS;
    }
}
