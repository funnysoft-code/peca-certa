<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Permissions;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

test('admin can update role permission matrix and cache is cleared', function (): void {
    $admin = User::factory()->admin()->create();
    $operator = User::factory()->create();

    expect($operator->can(Permissions::AnalyticsView))->toBeTrue();

    $userRole = Role::query()->where('name', Permissions::RoleUser)->firstOrFail();

    // Poison cache awareness: assert permission before change.
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();

    $withoutAnalytics = array_values(array_filter(
        Permissions::forUserRole(),
        fn (string $name): bool => $name !== Permissions::AnalyticsView,
    ));

    $this->actingAs($admin)
        ->put(route('admin.roles.update', $userRole), [
            'permissions' => $withoutAnalytics,
        ])
        ->assertRedirect(route('admin.roles.index'));

    $operator->refresh();
    resolve(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($operator->can(Permissions::AnalyticsView))->toBeFalse()
        ->and($operator->can(Permissions::PartsView))->toBeTrue();
});

test('regular users cannot manage role matrix', function (): void {
    $user = User::factory()->create();
    $userRole = Role::query()->where('name', Permissions::RoleUser)->firstOrFail();

    $this->actingAs($user)
        ->put(route('admin.roles.update', $userRole), [
            'permissions' => Permissions::forUserRole(),
        ])
        ->assertForbidden();
});
