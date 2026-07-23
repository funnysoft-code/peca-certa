<?php

declare(strict_types=1);

use App\Actions\Admin\AssignUserRole;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Validation\ValidationException;

test('assign user role rejects unknown role names', function (): void {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    expect(fn () => resolve(AssignUserRole::class)->execute($admin, $target, 'superuser'))
        ->toThrow(ValidationException::class);
});

test('last admin cannot demote themselves', function (): void {
    $admin = User::factory()->admin()->create();

    expect(User::query()->role(Permissions::RoleAdmin)->count())->toBe(1);

    expect(fn () => resolve(AssignUserRole::class)->execute($admin, $admin, Permissions::RoleUser))
        ->toThrow(ValidationException::class);

    expect($admin->fresh()?->isAdmin())->toBeTrue();
});

test('admin can demote self when another admin exists', function (): void {
    $adminA = User::factory()->admin()->create();
    $adminB = User::factory()->admin()->create();

    resolve(AssignUserRole::class)->execute($adminA, $adminA, Permissions::RoleUser);

    expect($adminA->fresh()?->isAdmin())->toBeFalse()
        ->and($adminB->fresh()?->isAdmin())->toBeTrue();
});
