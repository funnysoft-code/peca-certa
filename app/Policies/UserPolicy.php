<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Permissions;

final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permissions::UsersView);
    }

    public function view(User $user): bool
    {
        return $user->can(Permissions::UsersView);
    }

    public function invite(User $user): bool
    {
        return $user->can(Permissions::UsersManage);
    }

    public function updateRole(User $user): bool
    {
        return $user->can(Permissions::UsersManage);
    }

    public function resendInvite(User $user, User $model): bool
    {
        return $user->can(Permissions::UsersManage) && $model->isPendingInvite();
    }

    public function delete(User $user, User $model): bool
    {
        return $user->can(Permissions::UsersManage) && ! $user->is($model);
    }
}
