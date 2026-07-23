<?php

declare(strict_types=1);

use App\Http\Controllers\CancelIdentifyController;
use App\Http\Controllers\ExpandUnavailableFindingsController;
use App\Http\Controllers\IdentifyController;
use App\Http\Controllers\PartSearchController;
use App\Http\Controllers\ProcurementAnalyticsController;
use App\Http\Controllers\ResumeIdentifyController;
use App\Http\Controllers\SearchRunFindingsController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    if (auth()->check()) {
        return to_route('identify.create');
    }

    return Inertia::render('welcome');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::redirect('dashboard', '/identify')->name('dashboard');

    Route::get('parts', [PartSearchController::class, 'index'])->name('parts.index');
    Route::post('parts', [PartSearchController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('parts.store');
    Route::get('parts/{run}', [PartSearchController::class, 'show'])->name('parts.show');
    Route::get('identify', [IdentifyController::class, 'create'])->name('identify.create');
    Route::post('identify', [IdentifyController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('identify.store');
    Route::get('identify/{run}', [IdentifyController::class, 'show'])->name('identify.show');
    Route::post('identify/{run}/resume', ResumeIdentifyController::class)
        ->middleware('throttle:30,1')
        ->name('identify.resume');
    Route::post('identify/{run}/cancel', CancelIdentifyController::class)
        ->middleware('throttle:30,1')
        ->name('identify.cancel');
    Route::get('search-runs/{run}/findings', SearchRunFindingsController::class)
        ->name('search-runs.findings.index');
    Route::post('search-runs/{run}/findings/unavailable', ExpandUnavailableFindingsController::class)
        ->middleware('throttle:20,1')
        ->name('search-runs.findings.unavailable');
    Route::get('analytics', ProcurementAnalyticsController::class)
        ->name('analytics.index');
});

require __DIR__.'/settings.php';
