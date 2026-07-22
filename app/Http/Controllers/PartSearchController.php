<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ListSearchRuns;
use App\Actions\StartPartsSearch;
use App\Data\SearchRunData;
use App\Enums\SearchRunKind;
use App\Http\Requests\SearchPartsRequest;
use App\Models\SearchRun;
use App\Queries\ListSearchRunsQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PartSearchController extends Controller
{
    public function index(Request $request, ListSearchRuns $listSearchRuns, ListSearchRunsQuery $listQuery): Response
    {
        $user = $this->user($request);

        return Inertia::render('parts/index', [
            'runs' => $listSearchRuns->execute($request, SearchRunKind::Parts, $user),
            'filters' => [
                'scope' => $listQuery->scope($request),
                'q' => $listQuery->searchTerm($request),
            ],
        ]);
    }

    public function store(
        SearchPartsRequest $request,
        StartPartsSearch $startPartsSearch,
    ): RedirectResponse {
        $run = $startPartsSearch->execute(
            $this->user($request),
            $request->reference(),
        );

        return to_route('parts.show', $run);
    }

    public function show(Request $request, SearchRun $run): Response
    {
        $this->user($request);
        abort_unless($run->kind === SearchRunKind::Parts, 404);

        return Inertia::render('parts/show', [
            'run' => SearchRunData::fromModel($run->load(['lookups', 'user'])),
        ]);
    }
}
