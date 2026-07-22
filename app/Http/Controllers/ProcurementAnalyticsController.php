<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BuildProcurementAnalytics;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final readonly class ProcurementAnalyticsController
{
    public function __invoke(
        Request $request,
        BuildProcurementAnalytics $buildProcurementAnalytics,
    ): Response {
        $range = $request->integer('range', BuildProcurementAnalytics::DefaultRange);

        return Inertia::render('analytics/index', [
            'analytics' => $buildProcurementAnalytics->execute($range),
            'range' => in_array($range, BuildProcurementAnalytics::AllowedRanges, true)
                ? $range
                : BuildProcurementAnalytics::DefaultRange,
            'ranges' => BuildProcurementAnalytics::AllowedRanges,
        ]);
    }
}
