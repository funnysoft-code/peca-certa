<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Models\User;
use App\Notifications\InviteUserNotification;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

final readonly class InviteUser
{
    public function execute(string $name, string $email): User
    {
        return DB::transaction(function () use ($name, $email): User {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Str::password(32),
                'email_verified_at' => null,
            ]);

            $user->assignRole(Permissions::RoleUser);

            $this->sendInvite($user);

            return $user;
        });
    }

    public function resend(User $user): void
    {
        abort_unless($user->isPendingInvite(), 422, 'User is not pending an invite.');

        $this->sendInvite($user);
    }

    private function sendInvite(User $user): void
    {
        $token = Password::createToken($user);

        $user->notify(new InviteUserNotification($token));
    }
}
