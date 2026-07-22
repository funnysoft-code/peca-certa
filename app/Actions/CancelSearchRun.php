<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\SearchRunStatus;
use App\Enums\SupplierLookupStatus;
use App\Events\SearchRunAdvanced;
use App\Events\SupplierResultReady;
use App\Models\SearchRun;
use App\Models\SupplierLookup;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Operator-initiated cancel for non-terminal search runs.
 *
 * Clears clarification state, fails unfinished supplier lookups, and marks the
 * run cancelled. Late-arriving managed-queue jobs treat cancelled as terminal
 * and will not flip the run back to done.
 */
final readonly class CancelSearchRun
{
    private const string LookupError = 'Cancelado pelo operador.';

    public function execute(SearchRun $run): SearchRun
    {
        throw_unless(
            $run->status->isCancellable(),
            InvalidArgumentException::class,
            'Search run cannot be cancelled in its current status.',
        );

        /** @var list<SupplierLookup> $broadcastLookups */
        $broadcastLookups = [];
        $cancelled = null;

        DB::transaction(function () use ($run, &$broadcastLookups, &$cancelled): void {
            $locked = SearchRun::query()->whereKey($run->id)->lockForUpdate()->first();

            throw_unless(
                $locked instanceof SearchRun && $locked->status->isCancellable(),
                InvalidArgumentException::class,
                'Search run cannot be cancelled in its current status.',
            );

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

            $messages = $locked->messages ?? [];
            $messages[] = [
                'role' => 'user',
                'content' => 'Operador cancelou a identificação.',
            ];

            $locked->messages = $messages;
            $locked->pending_question = null;
            $locked->status = SearchRunStatus::Cancelled;
            $locked->save();

            $cancelled = $locked;
        });

        foreach ($broadcastLookups as $lookup) {
            event(new SupplierResultReady($lookup));
        }

        throw_unless($cancelled instanceof SearchRun, InvalidArgumentException::class, 'Search run could not be cancelled.');

        event(new SearchRunAdvanced($cancelled));

        return $cancelled;
    }
}
