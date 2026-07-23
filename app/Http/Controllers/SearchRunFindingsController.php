<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ListSearchRunFindings;
use App\Http\Requests\ListSearchRunFindingsRequest;
use App\Models\SearchRun;
use Illuminate\Http\JsonResponse;

final class SearchRunFindingsController extends Controller
{
    public function __invoke(
        ListSearchRunFindingsRequest $request,
        SearchRun $run,
        ListSearchRunFindings $listSearchRunFindings,
    ): JsonResponse {
        $this->authorize('view', $run);

        $payload = $listSearchRunFindings->execute(
            $run,
            $request,
            $request->getLimit(),
        );

        return response()->json($payload->jsonSerialize());
    }
}
