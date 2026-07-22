<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\PersistLookupFindings;
use App\Models\Finding;
use App\Models\SupplierLookup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('findings:backfill {--run= : Only backfill lookups for this search run id}')]
#[Description('Rebuild findings rows from supplier_lookups.result JSON')]
final class BackfillFindingsCommand extends Command
{
    public function handle(PersistLookupFindings $persistLookupFindings): int
    {
        $query = SupplierLookup::query()->whereNotNull('result');

        $runId = $this->option('run');
        if (is_string($runId) && $runId !== '') {
            $query->where('search_run_id', $runId);
        }

        $lookups = $query->get();
        $before = Finding::query()->count();

        foreach ($lookups as $lookup) {
            $persistLookupFindings->execute($lookup);
        }

        $after = Finding::query()->count();

        $this->info(sprintf(
            'Processed %d lookups. Findings: %d → %d.',
            $lookups->count(),
            $before,
            $after,
        ));

        return self::SUCCESS;
    }
}
