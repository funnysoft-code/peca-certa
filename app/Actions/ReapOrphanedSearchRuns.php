<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\SearchRunStatus;
use App\Enums\SupplierLookupStatus;
use App\Events\SearchRunAdvanced;
use App\Events\SupplierResultReady;
use App\Models\SearchRun;
use Illuminate\Support\Facades\DB;

/**
 * Close search runs that stayed non-terminal after managed queue work was lost.
 *
 * Pricing jobs that never start (or die without calling failed()) leave lookups
 * in pending/running forever. This sweep is the backstop for Laravel Cloud
 * managed queues (and any other queue driver) — not Horizon-specific.
 */
final readonly class ReapOrphanedSearchRuns
{
    private const string LookupError = 'Timed out waiting for supplier pricing (orphaned queue work).';

    /**
     * @return int Number of search runs closed
     */
    public function execute(?int $olderThanMinutes = null): int
    {
        $minutes = $olderThanMinutes ?? config()->integer('peca.orphan_reap_after_minutes');
        $cutoff = now()->subMinutes($minutes);

        /** @var list<string> $runIds */
        $runIds = SearchRun::query()
            ->whereIn('status', [SearchRunStatus::Pending, SearchRunStatus::Running])
            ->where('updated_at', '<', $cutoff)
            ->oldest('updated_at')
            ->pluck('id')
            ->all();

        $closed = 0;

        foreach ($runIds as $runId) {
            if ($this->reapRun($runId)) {
                $closed++;
            }
        }

        return $closed;
    }

    private function reapRun(string $runId): bool
    {
        $broadcastLookups = [];
        $run = null;

        DB::transaction(function () use ($runId, &$broadcastLookups, &$run): void {
            $locked = SearchRun::query()->whereKey($runId)->lockForUpdate()->first();

            if (! $locked instanceof SearchRun) {
                return;
            }

            if (! in_array($locked->status, [SearchRunStatus::Pending, SearchRunStatus::Running], true)) {
                return;
            }

            $unfinished = $locked->lookups()
                ->whereIn('status', [SupplierLookupStatus::Pending, SupplierLookupStatus::Running])
                ->lockForUpdate()
                ->get();

            foreach ($unfinished as $lookup) {
                $lookup->status = SupplierLookupStatus::Failed;
                $lookup->error = self::LookupError;
                $lookup->save();
                $broadcastLookups[] = $lookup;
            }

            $stillUnfinished = $locked->lookups()
                ->whereIn('status', [SupplierLookupStatus::Pending, SupplierLookupStatus::Running])
                ->count();

            if ($stillUnfinished > 0) {
                return;
            }

            $lookupCount = $locked->lookups()->count();

            if ($lookupCount === 0) {
                // Agent / identify job never finished (or never dispatched pricing).
                $locked->status = SearchRunStatus::Failed;
            } else {
                $locked->status = SearchRunStatus::Done;
            }

            $locked->save();
            $run = $locked;
        });

        foreach ($broadcastLookups as $lookup) {
            event(new SupplierResultReady($lookup));
        }

        if ($run instanceof SearchRun) {
            event(new SearchRunAdvanced($run));

            return true;
        }

        return false;
    }
}
