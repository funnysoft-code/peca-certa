<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Validation\Rules\Password;

test('users are not admins by default', function (): void {
    expect(User::factory()->make()->isAdmin())->toBeFalse();
});

test('password defaults are strict in production', function (): void {
    $this->app->detectEnvironment(fn (): string => 'production');

    expect(Password::defaults())->toBeInstanceOf(Password::class);
});
