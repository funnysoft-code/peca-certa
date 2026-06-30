<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SearchAutoDeltaParts;
use App\Data\PartSearchResult;
use App\Http\Requests\SearchPartsRequest;
use Inertia\Inertia;
use Inertia\Response;

final class PartSearchController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('parts/index');
    }

    public function search(SearchPartsRequest $request, SearchAutoDeltaParts $action): PartSearchResult
    {
        return $action->execute($request->reference());
    }
}
