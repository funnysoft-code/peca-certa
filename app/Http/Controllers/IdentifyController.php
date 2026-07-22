<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ListSearchRuns;
use App\Data\SearchRunData;
use App\Enums\SearchRunKind;
use App\Enums\SearchRunStatus;
use App\Http\Requests\IdentifyRequest;
use App\Jobs\IdentifyAgentJob;
use App\Models\SearchRun;
use App\Queries\ListSearchRunsQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class IdentifyController extends Controller
{
    public function create(Request $request, ListSearchRuns $listSearchRuns, ListSearchRunsQuery $listQuery): Response
    {
        $user = $this->user($request);

        return Inertia::render('identify/index', [
            'runs' => $listSearchRuns->execute($request, SearchRunKind::Identify, $user),
            'filters' => [
                'scope' => $listQuery->scope($request),
                'q' => $listQuery->searchTerm($request),
            ],
        ]);
    }

    public function store(IdentifyRequest $request): RedirectResponse
    {
        $run = SearchRun::query()->create([
            'user_id' => $this->user($request)->id,
            'kind' => SearchRunKind::Identify,
            'request_text' => $request->requestText(),
            'vin' => $request->vin(),
            'status' => SearchRunStatus::Pending,
            'messages' => [],
        ]);

        dispatch(new IdentifyAgentJob($run));

        return to_route('identify.show', $run);
    }

    public function show(Request $request, SearchRun $run): Response
    {
        $this->user($request);

        return Inertia::render('identify/show', [
            'run' => SearchRunData::fromModel($run->load(['lookups', 'user'])),
        ]);
    }
}
