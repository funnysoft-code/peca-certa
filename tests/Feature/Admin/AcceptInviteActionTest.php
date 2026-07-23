<?php

declare(strict_types=1);

use App\Actions\AcceptInvite;
use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

test('accept invite rejects unknown email', function (): void {
    expect(fn () => resolve(AcceptInvite::class)->execute(
        'missing@example.com',
        'token',
        'password-ok',
    ))->toThrow(ValidationException::class);
});

test('accept invite rejects invalid token', function (): void {
    $user = User::factory()->pendingInvite()->create([
        'email' => 'badtoken@example.com',
    ]);

    expect(fn () => resolve(AcceptInvite::class)->execute(
        $user->email,
        'not-a-valid-token',
        'password-ok',
    ))->toThrow(ValidationException::class);

    expect($user->fresh()?->email_verified_at)->toBeNull();
});

test('accept invite with valid token is covered via password broker', function (): void {
    $user = User::factory()->pendingInvite()->create([
        'email' => 'goodtoken@example.com',
    ]);
    $token = Password::broker()->createToken($user);

    $accepted = resolve(AcceptInvite::class)->execute(
        $user->email,
        $token,
        'password-ok',
    );

    expect($accepted->email_verified_at)->not->toBeNull();
});
