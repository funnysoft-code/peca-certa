<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\StartPartsSearch;
use App\Data\SearchRunData;
use App\Enums\SearchRunKind;
use App\Http\Requests\SearchPartsRequest;
use App\Models\SearchRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PartSearchController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);

        $recentRuns = SearchRun::query()
            ->where('user_id', $user->id)
            ->where('kind', SearchRunKind::Parts)
            ->with('lookups')
            ->latest()
            ->limit(5)
            ->get()
            ->map(SearchRunData::fromModel(...));

        return Inertia::render('parts/index', [
            'recentRuns' => $recentRuns,
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
        abort_unless($run->user_id === $this->user($request)->id, 403);
        abort_unless($run->kind === SearchRunKind::Parts, 404);

        return Inertia::render('parts/show', [
            'run' => SearchRunData::fromModel($run->load('lookups')),
        ]);
    }
}
