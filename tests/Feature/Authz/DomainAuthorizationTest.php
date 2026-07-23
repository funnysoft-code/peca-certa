<?php

declare(strict_types=1);

use App\Enums\SearchRunKind;
use App\Enums\SearchRunStatus;
use App\Models\SearchRun;
use App\Models\User;

test('owner can view own identify run', function (): void {
    $owner = User::factory()->create();
    $run = SearchRun::factory()->create([
        'user_id' => $owner->id,
        'kind' => SearchRunKind::Identify,
        'status' => SearchRunStatus::Pending,
    ]);

    $this->actingAs($owner)
        ->get(route('identify.show', $run))
        ->assertOk();
});

test('non-owner cannot view another users identify run', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $run = SearchRun::factory()->create([
        'user_id' => $owner->id,
        'kind' => SearchRunKind::Identify,
        'status' => SearchRunStatus::Pending,
    ]);

    $this->actingAs($other)
        ->get(route('identify.show', $run))
        ->assertForbidden();
});

test('admin can view another users identify run via manage permission', function (): void {
    $owner = User::factory()->create();
    $admin = User::factory()->admin()->create();
    $run = SearchRun::factory()->create([
        'user_id' => $owner->id,
        'kind' => SearchRunKind::Identify,
        'status' => SearchRunStatus::Pending,
    ]);

    $this->actingAs($admin)
        ->get(route('identify.show', $run))
        ->assertOk();
});

test('non-owner cannot cancel another users identify run', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $run = SearchRun::factory()->create([
        'user_id' => $owner->id,
        'kind' => SearchRunKind::Identify,
        'status' => SearchRunStatus::Running,
    ]);

    $this->actingAs($other)
        ->post(route('identify.cancel', $run))
        ->assertForbidden();
});

test('owner can cancel own identify run', function (): void {
    $owner = User::factory()->create();
    $run = SearchRun::factory()->create([
        'user_id' => $owner->id,
        'kind' => SearchRunKind::Identify,
        'status' => SearchRunStatus::Running,
    ]);

    $this->actingAs($owner)
        ->post(route('identify.cancel', $run))
        ->assertRedirect(route('identify.show', $run));
});

test('non-owner cannot view another users parts run', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $run = SearchRun::factory()->create([
        'user_id' => $owner->id,
        'kind' => SearchRunKind::Parts,
        'status' => SearchRunStatus::Done,
    ]);

    $this->actingAs($other)
        ->get(route('parts.show', $run))
        ->assertForbidden();
});
