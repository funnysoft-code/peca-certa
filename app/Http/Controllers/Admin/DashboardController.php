<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $this->user($request);
        abort_unless($user->can(Permissions::AdminAccess), 403);

        $pendingInvites = User::query()
            ->whereNull('email_verified_at')
            ->count();

        $userCount = User::query()->count();
        $roleCount = Role::query()->count();

        return Inertia::render('admin/dashboard', [
            'stats' => [
                'users' => $userCount,
                'pending_invites' => $pendingInvites,
                'roles' => $roleCount,
            ],
        ]);
    }
}
