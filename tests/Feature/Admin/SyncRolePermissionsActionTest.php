<?php

declare(strict_types=1);

use App\Actions\Admin\SyncRolePermissions;
use App\Support\Permissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    (new RolesAndPermissionsSeeder)->run();
});

test('sync role permissions rejects unknown permission names', function (): void {
    $role = Role::query()->where('name', Permissions::RoleUser)->firstOrFail();

    expect(fn () => resolve(SyncRolePermissions::class)->execute($role, ['not.a.permission']))
        ->toThrow(ValidationException::class);
});

test('admin role cannot be synced to empty permissions', function (): void {
    $role = Role::query()->where('name', Permissions::RoleAdmin)->firstOrFail();

    expect(fn () => resolve(SyncRolePermissions::class)->execute($role, []))
        ->toThrow(ValidationException::class);
});
