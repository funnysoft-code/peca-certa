<?php

declare(strict_types=1);

use App\Models\User;

test('isAdmin is false for default user role', function (): void {
    $user = User::factory()->create();

    expect($user->isAdmin())->toBeFalse();
});

test('isAdmin is true for admin role', function (): void {
    $user = User::factory()->admin()->create();

    expect($user->isAdmin())->toBeTrue();
});
