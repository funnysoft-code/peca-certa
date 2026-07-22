<?php

declare(strict_types=1);

use App\Actions\ReapOrphanedSearchRuns;
use App\Enums\SearchRunStatus;
use App\Enums\Supplier;
use App\Enums\SupplierLookupStatus;
use App\Events\SearchRunAdvanced;
use App\Events\SupplierResultReady;
use App\Models\SearchRun;
use App\Models\SupplierLookup;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schedule;

it('fails stuck pending lookups and marks the run done', function (): void {
    Event::fake([SearchRunAdvanced::class, SupplierResultReady::class]);

    $run = SearchRun::factory()->create([
        'status' => SearchRunStatus::Running,
        'updated_at' => now()->subHours(2),
    ]);

    $pending = SupplierLookup::factory()->for($run, 'run')->create([
        'supplier' => Supplier::AutoDelta,
        'query' => '11427622446',
        'status' => SupplierLookupStatus::Pending,
        'updated_at' => now()->subHours(2),
    ]);

    SupplierLookup::factory()->for($run, 'run')->create([
        'supplier' => Supplier::AutoZitania,
        'query' => '11427622446',
        'status' => SupplierLookupStatus::Done,
        'updated_at' => now()->subHours(2),
    ]);

    $closed = resolve(ReapOrphanedSearchRuns::class)->execute(30);

    expect($closed)->toBe(1)
        ->and($run->refresh()->status)->toBe(SearchRunStatus::Done)
        ->and($pending->refresh()->status)->toBe(SupplierLookupStatus::Failed)
        ->and($pending->error)->toContain('orphaned');

    Event::assertDispatched(SupplierResultReady::class);
    Event::assertDispatched(SearchRunAdvanced::class);
});

it('fails a running run with no lookups as failed', function (): void {
    Event::fake([SearchRunAdvanced::class, SupplierResultReady::class]);

    $run = SearchRun::factory()->create([
        'status' => SearchRunStatus::Running,
        'updated_at' => now()->subHours(2),
    ]);

    $closed = resolve(ReapOrphanedSearchRuns::class)->execute(30);

    expect($closed)->toBe(1)
        ->and($run->refresh()->status)->toBe(SearchRunStatus::Failed);

    Event::assertDispatched(SearchRunAdvanced::class);
    Event::assertNotDispatched(SupplierResultReady::class);
});

it('ignores fresh running runs', function (): void {
    Event::fake([SearchRunAdvanced::class]);

    $run = SearchRun::factory()->create([
        'status' => SearchRunStatus::Running,
        'updated_at' => now()->subMinutes(5),
    ]);

    SupplierLookup::factory()->for($run, 'run')->create([
        'status' => SupplierLookupStatus::Pending,
        'updated_at' => now()->subMinutes(5),
    ]);

    $closed = resolve(ReapOrphanedSearchRuns::class)->execute(30);

    expect($closed)->toBe(0)
        ->and($run->refresh()->status)->toBe(SearchRunStatus::Running);

    Event::assertNotDispatched(SearchRunAdvanced::class);
});

it('ignores needs_input and done runs', function (): void {
    Event::fake([SearchRunAdvanced::class]);

    SearchRun::factory()->create([
        'status' => SearchRunStatus::NeedsInput,
        'updated_at' => now()->subHours(2),
    ]);

    SearchRun::factory()->create([
        'status' => SearchRunStatus::Done,
        'updated_at' => now()->subHours(2),
    ]);

    expect(resolve(ReapOrphanedSearchRuns::class)->execute(30))->toBe(0);
    Event::assertNotDispatched(SearchRunAdvanced::class);
});

it('reapRun returns false for missing or already-terminal runs', function (): void {
    Event::fake([SearchRunAdvanced::class, SupplierResultReady::class]);

    $action = resolve(ReapOrphanedSearchRuns::class);
    $reapRun = new ReflectionMethod(ReapOrphanedSearchRuns::class, 'reapRun');

    $done = SearchRun::factory()->create([
        'status' => SearchRunStatus::Done,
        'updated_at' => now()->subHours(2),
    ]);

    expect($reapRun->invoke($action, '00000000-0000-4000-8000-000000000001'))->toBeFalse()
        ->and($reapRun->invoke($action, $done->id))->toBeFalse();

    Event::assertNotDispatched(SearchRunAdvanced::class);
});

it('command closes orphans and accepts minutes override', function (): void {
    Event::fake([SearchRunAdvanced::class, SupplierResultReady::class]);

    $run = SearchRun::factory()->create([
        'status' => SearchRunStatus::Pending,
        'updated_at' => now()->subMinutes(20),
    ]);

    $this->artisan('search-runs:reap-orphaned', ['--minutes' => 10])
        ->expectsOutputToContain('Closed 1 orphaned search run(s).')
        ->assertSuccessful();

    expect($run->refresh()->status)->toBe(SearchRunStatus::Failed);
});

it('command rejects non-positive minutes', function (): void {
    $this->artisan('search-runs:reap-orphaned', ['--minutes' => 0])
        ->assertFailed();
});

it('schedules the reaper every five minutes', function (): void {
    $events = Schedule::events();

    $match = collect($events)->first(
        fn ($event): bool => str_contains($event->command ?? '', 'search-runs:reap-orphaned')
            || str_contains($event->description ?? '', 'search-runs:reap-orphaned'),
    );

    expect($match)->not->toBeNull();
});
