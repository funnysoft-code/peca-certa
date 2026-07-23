<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\InviteUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InviteUserRequest;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Builder;
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

        $status = $this->statusFilter($request);

        $users = $this->usersQuery($status)
            ->with('roles')
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $this->userStatus($user),
                'roles' => $user->getRoleNames()->values()->all(),
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'created_at' => $user->created_at->toIso8601String(),
                'deleted_at' => $user->deleted_at?->toIso8601String(),
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
            'filters' => [
                'status' => $status,
            ],
            'counts' => [
                'all' => User::query()->count(),
                'active' => User::query()->whereNotNull('email_verified_at')->count(),
                'pending' => User::query()->whereNull('email_verified_at')->count(),
                'deleted' => User::onlyTrashed()->count(),
            ],
            'can' => [
                'invite' => $actor->can(Permissions::UsersManage),
                'manageRoles' => $actor->can(Permissions::UsersManage),
                'delete' => $actor->can(Permissions::UsersManage),
                'restore' => $actor->can(Permissions::UsersManage),
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

    /**
     * @return Builder<User>
     */
    private function usersQuery(string $status): Builder
    {
        return match ($status) {
            'deleted' => User::onlyTrashed(),
            'pending' => User::query()->whereNull('email_verified_at'),
            'active' => User::query()->whereNotNull('email_verified_at'),
            default => User::query(),
        };
    }

    private function statusFilter(Request $request): string
    {
        $status = $request->string('status')->toString();

        return in_array($status, ['all', 'active', 'pending', 'deleted'], true)
            ? $status
            : 'all';
    }

    private function userStatus(User $user): string
    {
        if ($user->trashed()) {
            return 'deleted';
        }

        return $user->isPendingInvite() ? 'pending' : 'active';
    }
}
