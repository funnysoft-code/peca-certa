<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\SyncRolePermissions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SyncRolePermissionsRequest;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

final class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        abort_unless($user->can(Permissions::RolesView), 403);

        $guard = config('auth.defaults.guard', 'web');
        assert(is_string($guard));

        $roles = Role::query()
            ->where('guard_name', $guard)
            ->with('permissions')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->values()->all(),
            ]);

        $permissions = Permission::query()
            ->where('guard_name', $guard)
            ->orderBy('name')
            ->pluck('name')
            ->values()
            ->all();

        return Inertia::render('admin/roles/index', [
            'roles' => $roles,
            'permissions' => $permissions,
            'can' => [
                'manage' => $user->can(Permissions::RolesManage),
            ],
        ]);
    }

    public function update(
        SyncRolePermissionsRequest $request,
        Role $role,
        SyncRolePermissions $syncRolePermissions,
    ): RedirectResponse {
        abort_unless($request->user()->can(Permissions::RolesManage), 403);

        /** @var list<string> $permissionNames */
        $permissionNames = $request->validated('permissions');

        $syncRolePermissions->execute($role, $permissionNames);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Permissões da função atualizadas.',
        ]);

        return to_route('admin.roles.index');
    }
}
