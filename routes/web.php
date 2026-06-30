<?php

declare(strict_types=1);

use App\Http\Controllers\PartSearchController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('welcome'))->name('home');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('dashboard', fn () => Inertia::render('dashboard'))->name('dashboard');
    Route::get('parts', [PartSearchController::class, 'index'])->name('parts.index');
    Route::post('parts/search', [PartSearchController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('parts.search');
});

require __DIR__.'/settings.php';
