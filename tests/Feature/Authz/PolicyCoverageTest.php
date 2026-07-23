<?php

declare(strict_types=1);

use App\Enums\SearchRunKind;
use App\Enums\SearchRunStatus;
use App\Models\SearchRun;
use App\Models\User;
use App\Policies\SearchRunPolicy;
use App\Policies\UserPolicy;
use App\Queries\ListSearchRunsQuery;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia;

test('user policy gates invite and role actions', function (): void {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $pending = User::factory()->pendingInvite()->create();
    $policy = new UserPolicy;

    expect($policy->viewAny($admin))->toBeTrue()
        ->and($policy->viewAny($user))->toBeFalse()
        ->and($policy->view($admin))->toBeTrue()
        ->and($policy->invite($admin))->toBeTrue()
        ->and($policy->invite($user))->toBeFalse()
        ->and($policy->updateRole($admin))->toBeTrue()
        ->and($policy->resendInvite($admin, $pending))->toBeTrue()
        ->and($policy->resendInvite($admin, $user))->toBeFalse();
});

test('search run policy expands findings for owner with findings view', function (): void {
    $owner = User::factory()->create();
    $run = SearchRun::factory()->create([
        'user_id' => $owner->id,
        'kind' => SearchRunKind::Parts,
        'status' => SearchRunStatus::Done,
    ]);
    $policy = new SearchRunPolicy;

    expect($policy->expandFindings($owner, $run))->toBeTrue()
        ->and($policy->createParts($owner))->toBeTrue()
        ->and($policy->createIdentify($owner))->toBeTrue();
});

test('search run policy denies expand for non-owner without manage', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $run = SearchRun::factory()->create([
        'user_id' => $owner->id,
        'kind' => SearchRunKind::Identify,
        'status' => SearchRunStatus::Done,
    ]);
    $policy = new SearchRunPolicy;

    expect($policy->expandFindings($other, $run))->toBeFalse()
        ->and($policy->view($other, $run))->toBeFalse();
});

test('search run policy allows manage-kind users to update others runs', function (): void {
    $owner = User::factory()->create();
    $manager = User::factory()->create();
    // Drop role-inherited perms so FindingsManage-only path is exercised.
    $manager->syncRoles([]);
    $manager->syncPermissions([
        Permissions::IdentifyManage,
        Permissions::FindingsManage,
    ]);

    $run = SearchRun::factory()->create([
        'user_id' => $owner->id,
        'kind' => SearchRunKind::Identify,
        'status' => SearchRunStatus::Running,
    ]);
    $policy = new SearchRunPolicy;

    expect($manager->can(Permissions::FindingsView))->toBeFalse()
        ->and($policy->update($manager, $run))->toBeTrue()
        ->and($policy->view($manager, $run))->toBeTrue()
        ->and($policy->expandFindings($manager, $run))->toBeTrue();
});

test('list search runs query falls back scope without user context', function (): void {
    $query = resolve(ListSearchRunsQuery::class);
    $request = Request::create('/parts', 'GET', ['scope' => 'everyone']);

    expect($query->scope($request))->toBe('everyone')
        ->and($query->scope($request, User::factory()->create(), SearchRunKind::Parts))->toBe('mine');
});

test('admin everyone scope lists other users runs', function (): void {
    $admin = User::factory()->admin()->create();
    $owner = User::factory()->create();
    SearchRun::factory()->for($owner)->parts()->create(['reference' => 'ADMIN-SEES']);

    $this->actingAs($admin)
        ->get('/parts')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('filters.scope', 'everyone')
            ->has('runs.data', 1)
            ->where('runs.data.0.reference', 'ADMIN-SEES')
        );
});

test('guest inertia share has empty can map', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('auth.user', null)
            ->where('auth.can', [])
            ->where('auth.roles', [])
        );
});
