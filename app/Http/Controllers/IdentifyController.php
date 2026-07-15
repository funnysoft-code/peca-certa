<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\IdentifyAndSourceParts;
use App\Data\IdentifyResult;
use App\Http\Requests\IdentifyRequest;
use Inertia\Inertia;
use Inertia\Response;

final class IdentifyController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('identify/index');
    }

    public function store(IdentifyRequest $request, IdentifyAndSourceParts $action): IdentifyResult
    {
        return $action->execute($request->requestText(), $request->vin());
    }
}
