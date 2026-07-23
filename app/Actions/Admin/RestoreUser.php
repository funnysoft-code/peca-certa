<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class RestoreUser
{
    public function execute(User $target): User
    {
        if (! $target->trashed()) {
            throw ValidationException::withMessages([
                'user' => 'Este utilizador não está removido.',
            ]);
        }

        $emailTaken = User::query()
            ->where('email', $target->email)
            ->whereKeyNot($target->id)
            ->exists();

        if ($emailTaken) {
            throw ValidationException::withMessages([
                'user' => 'Já existe um utilizador ativo com este email. Remova ou altere o email do utilizador atual antes de restaurar.',
            ]);
        }

        return DB::transaction(function () use ($target): User {
            $target->restore();

            return $target->refresh()->load('roles');
        });
    }
}
