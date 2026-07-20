<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SearchAutoDeltaParts;
use App\Actions\SearchAutoZitaniaParts;
use App\Data\PartSearchResult;
use App\Enums\Supplier;
use App\Http\Requests\SearchPartsRequest;
use Inertia\Inertia;
use Inertia\Response;

final class PartSearchController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('parts/index');
    }

    public function store(
        SearchPartsRequest $request,
        SearchAutoDeltaParts $autoDelta,
        SearchAutoZitaniaParts $autoZitania,
    ): PartSearchResult {
        return match ($request->supplier()) {
            Supplier::AutoDelta => $autoDelta->execute($request->reference()),
            Supplier::AutoZitania => $autoZitania->execute($request->reference()),
        };
    }
}
