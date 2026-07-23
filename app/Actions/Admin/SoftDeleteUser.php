<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class SoftDeleteUser
{
    public function execute(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            throw ValidationException::withMessages([
                'user' => 'Não pode remover a sua própria conta.',
            ]);
        }

        if (
            $target->hasRole(Permissions::RoleAdmin)
            && User::query()->role(Permissions::RoleAdmin)->count() <= 1
        ) {
            throw ValidationException::withMessages([
                'user' => 'Não pode remover o último administrador. Promova outro utilizador primeiro.',
            ]);
        }

        DB::transaction(function () use ($target): void {
            $target->delete();
        });
    }
}
