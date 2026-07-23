<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Idempotent SSOT for roles and permissions.
     * Admin receives every permission on every run (re-sync).
     */
    public function run(): void
    {
        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = config('auth.defaults.guard', 'web');

        foreach (Permissions::all() as $name) {
            Permission::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => $guard,
            ]);
        }

        $admin = Role::query()->firstOrCreate([
            'name' => Permissions::RoleAdmin,
            'guard_name' => $guard,
        ]);

        $user = Role::query()->firstOrCreate([
            'name' => Permissions::RoleUser,
            'guard_name' => $guard,
        ]);

        $admin->syncPermissions(Permission::query()->where('guard_name', $guard)->get());
        $user->syncPermissions(Permissions::forUserRole());

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
