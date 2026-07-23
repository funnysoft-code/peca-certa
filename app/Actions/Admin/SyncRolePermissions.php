<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final readonly class SyncRolePermissions
{
    /**
     * @param  list<string>  $permissionNames
     */
    public function execute(Role $role, array $permissionNames): Role
    {
        $guard = config('auth.defaults.guard', 'web');
        $valid = Permissions::all();

        foreach ($permissionNames as $name) {
            if (! in_array($name, $valid, true)) {
                throw ValidationException::withMessages([
                    'permissions' => sprintf('Unknown permission [%s].', $name),
                ]);
            }
        }

        if ($role->name === Permissions::RoleAdmin && $permissionNames === []) {
            throw ValidationException::withMessages([
                'permissions' => 'Admin role cannot have an empty permission set.',
            ]);
        }

        return DB::transaction(function () use ($role, $permissionNames, $guard): Role {
            $permissions = Permission::query()
                ->where('guard_name', $guard)
                ->whereIn('name', $permissionNames)
                ->get();

            $role->syncPermissions($permissions);

            resolve(PermissionRegistrar::class)->forgetCachedPermissions();

            return $role->refresh()->load('permissions');
        });
    }
}
