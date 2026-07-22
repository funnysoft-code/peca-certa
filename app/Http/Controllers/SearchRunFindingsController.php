<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ListSearchRunFindings;
use App\Http\Requests\ListSearchRunFindingsRequest;
use App\Models\SearchRun;
use Illuminate\Http\JsonResponse;

final readonly class SearchRunFindingsController
{
    public function __invoke(
        ListSearchRunFindingsRequest $request,
        SearchRun $run,
        ListSearchRunFindings $listSearchRunFindings,
    ): JsonResponse {
        $payload = $listSearchRunFindings->execute(
            $run,
            $request,
            $request->getLimit(),
        );

        return response()->json($payload->jsonSerialize());
    }
}
