<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\InviteUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InviteUserRequest;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

final class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->with('roles')
            ->latest()
            ->paginate(20)
            ->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->isPendingInvite() ? 'pending' : 'active',
                'roles' => $user->getRoleNames()->values()->all(),
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'created_at' => $user->created_at->toIso8601String(),
            ]);

        $guard = config('auth.defaults.guard', 'web');
        assert(is_string($guard));

        $roles = Role::query()
            ->where('guard_name', $guard)
            ->orderBy('name')
            ->pluck('name')
            ->values()
            ->all();

        $actor = $this->user($request);

        return Inertia::render('admin/users/index', [
            'users' => $users,
            'roles' => $roles,
            'can' => [
                'invite' => $actor->can(Permissions::UsersManage),
                'manageRoles' => $actor->can(Permissions::UsersManage),
                'delete' => $actor->can(Permissions::UsersManage),
            ],
        ]);
    }

    public function store(InviteUserRequest $request, InviteUser $inviteUser): RedirectResponse
    {
        $this->authorize('invite', User::class);

        $inviteUser->execute(
            $request->string('name')->toString(),
            $request->string('email')->toString(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Convite enviado com sucesso.',
        ]);

        return to_route('admin.users.index');
    }
}
