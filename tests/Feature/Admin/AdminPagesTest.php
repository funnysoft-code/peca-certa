<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Inertia\Testing\AssertableInertia as Assert;

test('admin can view users index', function (): void {
    $admin = User::factory()->admin()->create();
    User::factory()->count(2)->create();

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('admin/users/index')
            ->has('users.data')
            ->has('roles')
            ->where('can.invite', true)
            ->where('can.manageRoles', true)
        );
});

test('admin can view roles matrix index', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.roles.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('admin/roles/index')
            ->has('roles')
            ->has('permissions')
            ->where('can.manage', true)
        );
});

test('admin can manage role matrix for user role', function (): void {
    $admin = User::factory()->admin()->create();
    $userRole = Role::query()->where('name', Permissions::RoleUser)->firstOrFail();

    $this->actingAs($admin)
        ->put(route('admin.roles.update', $userRole), [
            'permissions' => Permissions::forUserRole(),
        ])
        ->assertRedirect(route('admin.roles.index'));
});
