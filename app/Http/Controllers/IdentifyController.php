<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\SearchRunData;
use App\Enums\SearchRunKind;
use App\Enums\SearchRunStatus;
use App\Http\Requests\IdentifyRequest;
use App\Jobs\IdentifyAgentJob;
use App\Models\SearchRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class IdentifyController extends Controller
{
    public function create(Request $request): Response
    {
        $user = $this->user($request);

        $recentRuns = SearchRun::query()
            ->where('user_id', $user->id)
            ->where('kind', SearchRunKind::Identify)
            ->with('lookups')
            ->latest()
            ->limit(5)
            ->get()
            ->map(SearchRunData::fromModel(...));

        return Inertia::render('identify/index', [
            'recentRuns' => $recentRuns,
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
        abort_unless($run->user_id === $this->user($request)->id, 403);

        return Inertia::render('identify/show', [
            'run' => SearchRunData::fromModel($run->load('lookups')),
        ]);
    }
}
