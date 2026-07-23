<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final readonly class AssignUserRole
{
    public function execute(User $actor, User $target, string $roleName): User
    {
        $allowed = [Permissions::RoleAdmin, Permissions::RoleUser];

        if (! in_array($roleName, $allowed, true)) {
            throw ValidationException::withMessages([
                'role' => 'Invalid role.',
            ]);
        }

        if (
            $actor->is($target)
            && $target->hasRole(Permissions::RoleAdmin)
            && $roleName !== Permissions::RoleAdmin
            && $this->adminCount() <= 1
        ) {
            throw ValidationException::withMessages([
                'role' => 'Cannot demote the last admin. Promote another user first or use user:promote.',
            ]);
        }

        $role = Role::query()
            ->where('name', $roleName)
            ->where('guard_name', config('auth.defaults.guard', 'web'))
            ->firstOrFail();

        $target->syncRoles([$role]);

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        return $target->refresh()->load('roles');
    }

    private function adminCount(): int
    {
        return User::query()->role(Permissions::RoleAdmin)->count();
    }
}
