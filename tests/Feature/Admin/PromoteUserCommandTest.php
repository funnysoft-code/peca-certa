<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\Artisan;

test('user promote command promotes existing user to admin', function (): void {
    $user = User::factory()->create([
        'email' => 'bootstrap@example.com',
    ]);

    expect($user->isAdmin())->toBeFalse();

    $exit = Artisan::call('user:promote', ['email' => 'bootstrap@example.com']);

    expect($exit)->toBe(0)
        ->and($user->fresh()?->isAdmin())->toBeTrue()
        ->and($user->fresh()?->hasRole(Permissions::RoleAdmin))->toBeTrue();
});

test('user promote command fails for unknown email', function (): void {
    $exit = Artisan::call('user:promote', ['email' => 'missing@example.com']);

    expect($exit)->toBe(1);
});
