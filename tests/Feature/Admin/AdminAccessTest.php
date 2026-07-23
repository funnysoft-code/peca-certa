<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Permissions;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected from admin routes', function (): void {
    $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    $this->get(route('admin.users.index'))->assertRedirect(route('login'));
    $this->get(route('admin.roles.index'))->assertRedirect(route('login'));
});

test('regular users receive 403 on admin routes', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('admin.users.index'))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('admin.roles.index'))
        ->assertForbidden();
});

test('admins can access admin shell', function (): void {
    $admin = User::factory()->admin()->create();

    expect($admin->can(Permissions::AdminAccess))->toBeTrue();

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('admin/dashboard')
            ->has('stats')
            ->has('auth.can')
        );
});

test('inertia shares auth can map for authenticated users', function (): void {
    $user = User::factory()->create();

    expect($user->can(Permissions::PartsView))->toBeTrue()
        ->and($user->can(Permissions::AdminAccess))->toBeFalse();

    $response = $this->actingAs($user)
        ->get(route('identify.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('auth.can')
            ->where('auth.roles', [Permissions::RoleUser])
        );

    /** @var array<string, mixed> $page */
    $page = $response->viewData('page');
    $can = data_get($page, 'props.auth.can');

    // JSON props may decode as object; normalize to array.
    $map = is_array($can) ? $can : (array) $can;

    expect($map[Permissions::AdminAccess] ?? null)->toBeFalse()
        ->and($map[Permissions::PartsView] ?? null)->toBeTrue();
});
