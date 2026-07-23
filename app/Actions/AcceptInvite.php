<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

final readonly class AcceptInvite
{
    /**
     * Set password via invite token and mark email verified on success only.
     *
     * @throws ValidationException
     */
    public function execute(
        string $email,
        string $token,
        #[SensitiveParameter] string $password,
    ): User {
        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            throw ValidationException::withMessages([
                'email' => __('passwords.user'),
            ]);
        }

        $status = Password::broker()->reset(
            [
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $password,
                'token' => $token,
            ],
            function (User $user, string $password): void {
                DB::transaction(function () use ($user, $password): void {
                    // `password` cast is `hashed` — pass the plain value.
                    $user->forceFill([
                        'password' => $password,
                        'remember_token' => Str::random(60),
                        'email_verified_at' => $user->email_verified_at ?? now(),
                    ])->save();

                    event(new PasswordReset($user));
                });
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => __(is_string($status) ? $status : 'passwords.token'),
            ]);
        }

        $user->refresh();

        return $user;
    }
}
