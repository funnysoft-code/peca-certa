<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Backstop for search runs whose managed-queue pricing jobs never completed.
// Laravel Cloud runs schedule:run; withoutOverlapping avoids concurrent sweeps.
Schedule::command('search-runs:reap-orphaned')
    ->everyFiveMinutes()
    ->withoutOverlapping();
