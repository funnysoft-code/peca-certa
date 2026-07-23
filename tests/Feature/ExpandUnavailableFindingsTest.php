<?php

declare(strict_types=1);

use App\Enums\SearchRunStatus;
use App\Enums\Supplier;
use App\Enums\SupplierLookupStatus;
use App\Jobs\PriceSupplierJob;
use App\Models\SearchRun;
use App\Models\SupplierLookup;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('dispatches include-unavailable pricing jobs and marks the run running', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $run = SearchRun::factory()->for($user)->create([
        'status' => SearchRunStatus::Done,
        'unavailable_included' => false,
    ]);
    $delta = SupplierLookup::factory()->for($run, 'run')->create([
        'supplier' => Supplier::AutoDelta,
        'status' => SupplierLookupStatus::Done,
    ]);
    $zitania = SupplierLookup::factory()->for($run, 'run')->create([
        'supplier' => Supplier::AutoZitania,
        'status' => SupplierLookupStatus::Done,
    ]);

    $this->actingAs($user)
        ->postJson(route('search-runs.findings.unavailable', $run))
        ->assertOk()
        ->assertJsonPath('started', true)
        ->assertJsonPath('run.unavailableIncluded', false);

    expect($run->refresh()->status)->toBe(SearchRunStatus::Running)
        ->and($delta->refresh()->status)->toBe(SupplierLookupStatus::Pending)
        ->and($zitania->refresh()->status)->toBe(SupplierLookupStatus::Pending);

    Queue::assertPushed(PriceSupplierJob::class, 2);
    Queue::assertPushed(
        PriceSupplierJob::class,
        fn (PriceSupplierJob $job): bool => $job->includeUnavailable,
    );
});

it('is a no-op when unavailable findings were already included', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $run = SearchRun::factory()->for($user)->create([
        'status' => SearchRunStatus::Done,
        'unavailable_included' => true,
    ]);
    SupplierLookup::factory()->for($run, 'run')->create([
        'status' => SupplierLookupStatus::Done,
    ]);

    $this->actingAs($user)
        ->postJson(route('search-runs.findings.unavailable', $run))
        ->assertOk()
        ->assertJsonPath('started', false);

    Queue::assertNothingPushed();
});

it('is a no-op while supplier lookups are still busy', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $run = SearchRun::factory()->for($user)->create([
        'status' => SearchRunStatus::Running,
        'unavailable_included' => false,
    ]);
    SupplierLookup::factory()->for($run, 'run')->create([
        'status' => SupplierLookupStatus::Pending,
    ]);

    $this->actingAs($user)
        ->postJson(route('search-runs.findings.unavailable', $run))
        ->assertOk()
        ->assertJsonPath('started', false);

    Queue::assertNothingPushed();
});

it('requires authentication', function (): void {
    $run = SearchRun::factory()->create();

    $this->postJson(route('search-runs.findings.unavailable', $run))
        ->assertUnauthorized();
});

it('is a no-op when the run has no supplier lookups', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $run = SearchRun::factory()->for($user)->create([
        'status' => SearchRunStatus::Done,
        'unavailable_included' => false,
    ]);

    $this->actingAs($user)
        ->postJson(route('search-runs.findings.unavailable', $run))
        ->assertOk()
        ->assertJsonPath('started', false);

    Queue::assertNothingPushed();
});
