<?php

declare(strict_types=1);

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use Illuminate\Validation\ValidationException;

test('it creates a user from valid input', function (): void {
    $user = (new CreateNewUser)->create([
        'name' => 'Operador R2CZ',
        'email' => 'operador@r2cz.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->name)->toBe('Operador R2CZ')
        ->and($user->email)->toBe('operador@r2cz.test')
        ->and(User::query()->where('email', 'operador@r2cz.test')->exists())->toBeTrue();
});

test('it rejects invalid registration input', function (): void {
    (new CreateNewUser)->create([
        'name' => '',
        'email' => 'not-an-email',
        'password' => 'short',
        'password_confirmation' => 'mismatch',
    ]);
})->throws(ValidationException::class);
