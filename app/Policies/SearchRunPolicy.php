<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\SearchRunKind;
use App\Models\SearchRun;
use App\Models\User;
use App\Support\Permissions;

final class SearchRunPolicy
{
    public function view(User $user, SearchRun $run): bool
    {
        if ($this->owns($user, $run)) {
            return $this->canViewKind($user, $run->kind);
        }

        // Cross-user read requires manage (admin / elevated ops).
        if ($this->canManageKind($user, $run->kind)) {
            return true;
        }

        return $user->can(Permissions::SearchRunsManage);
    }

    public function createParts(User $user): bool
    {
        return $user->can(Permissions::PartsCreate);
    }

    public function createIdentify(User $user): bool
    {
        return $user->can(Permissions::IdentifyCreate);
    }

    public function update(User $user, SearchRun $run): bool
    {
        if ($this->owns($user, $run)) {
            return $this->canViewKind($user, $run->kind);
        }

        if ($this->canManageKind($user, $run->kind)) {
            return true;
        }

        return $user->can(Permissions::SearchRunsManage);
    }

    public function expandFindings(User $user, SearchRun $run): bool
    {
        if (! $this->update($user, $run)) {
            return false;
        }

        if ($user->can(Permissions::FindingsView)) {
            return true;
        }

        return $user->can(Permissions::FindingsManage);
    }

    private function owns(User $user, SearchRun $run): bool
    {
        return $run->user_id === $user->id;
    }

    private function canViewKind(User $user, SearchRunKind $kind): bool
    {
        return match ($kind) {
            SearchRunKind::Parts => $user->can(Permissions::PartsView),
            SearchRunKind::Identify => $user->can(Permissions::IdentifyView),
        };
    }

    private function canManageKind(User $user, SearchRunKind $kind): bool
    {
        return match ($kind) {
            SearchRunKind::Parts => $user->can(Permissions::PartsManage),
            SearchRunKind::Identify => $user->can(Permissions::IdentifyManage),
        };
    }
}
