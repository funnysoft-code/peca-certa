<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ExpandUnavailableFindings;
use App\Data\SearchRunData;
use App\Models\SearchRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ExpandUnavailableFindingsController extends Controller
{
    public function __invoke(
        Request $request,
        SearchRun $run,
        ExpandUnavailableFindings $expandUnavailableFindings,
    ): JsonResponse {
        $this->authorize('expandFindings', $run);

        $started = $expandUnavailableFindings->execute($run->load('lookups'));

        $run->refresh()->load(['lookups', 'user']);

        return response()->json([
            'started' => $started,
            'run' => SearchRunData::fromModel($run)->jsonSerialize(),
        ]);
    }
}
