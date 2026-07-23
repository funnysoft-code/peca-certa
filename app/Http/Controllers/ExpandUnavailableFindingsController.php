<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ExpandUnavailableFindings;
use App\Data\SearchRunData;
use App\Models\SearchRun;
use Illuminate\Http\JsonResponse;

final readonly class ExpandUnavailableFindingsController
{
    public function __invoke(
        SearchRun $run,
        ExpandUnavailableFindings $expandUnavailableFindings,
    ): JsonResponse {
        $started = $expandUnavailableFindings->execute($run->load('lookups'));

        $run->refresh()->load(['lookups', 'user']);

        return response()->json([
            'started' => $started,
            'run' => SearchRunData::fromModel($run)->jsonSerialize(),
        ]);
    }
}
