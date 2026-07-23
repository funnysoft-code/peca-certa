<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Permissions;

test('admin can soft delete another user', function (): void {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create([
        'email' => 'remove-me@example.com',
    ]);

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $target))
        ->assertRedirect(route('admin.users.index'));

    expect(User::query()->whereKey($target->id)->exists())->toBeFalse()
        ->and(User::withTrashed()->whereKey($target->id)->exists())->toBeTrue()
        ->and(User::withTrashed()->find($target->id)?->trashed())->toBeTrue();
});

test('admin cannot soft delete themselves', function (): void {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $admin))
        ->assertForbidden();

    expect(User::query()->whereKey($admin->id)->exists())->toBeTrue();
});

test('cannot soft delete the last admin even with manage permission', function (): void {
    $admin = User::factory()->admin()->create();
    $operator = User::factory()->create();

    // Operator elevated only with users.manage (not admin role).
    $operator->givePermissionTo([
        Permissions::UsersManage,
        Permissions::AdminAccess,
        Permissions::UsersView,
    ]);

    expect(User::query()->role(Permissions::RoleAdmin)->count())->toBe(1);

    $this->actingAs($operator)
        ->from(route('admin.users.index'))
        ->delete(route('admin.users.destroy', $admin))
        ->assertRedirect(route('admin.users.index'))
        ->assertSessionHasErrors('user');

    expect(User::query()->whereKey($admin->id)->exists())->toBeTrue()
        ->and($admin->fresh()?->isAdmin())->toBeTrue();
});

test('admin can soft delete another admin when more than one remains', function (): void {
    $admin = User::factory()->admin()->create();
    $otherAdmin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $otherAdmin))
        ->assertRedirect(route('admin.users.index'));

    expect(User::withTrashed()->find($otherAdmin->id)?->trashed())->toBeTrue()
        ->and(User::query()->role(Permissions::RoleAdmin)->count())->toBe(1);
});

test('regular user cannot soft delete users', function (): void {
    $user = User::factory()->create();
    $target = User::factory()->create();

    $this->actingAs($user)
        ->delete(route('admin.users.destroy', $target))
        ->assertForbidden();
});

test('email can be re-invited after soft delete', function (): void {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create([
        'email' => 'reinvite@example.com',
        'name' => 'Old Name',
    ]);

    $this->actingAs($admin)
        ->delete(route('admin.users.destroy', $target))
        ->assertRedirect(route('admin.users.index'));

    $this->actingAs($admin)
        ->post(route('admin.users.store'), [
            'name' => 'New Name',
            'email' => 'reinvite@example.com',
        ])
        ->assertRedirect(route('admin.users.index'));

    expect(User::query()->where('email', 'reinvite@example.com')->exists())->toBeTrue()
        ->and(User::withTrashed()->where('email', 'reinvite@example.com')->count())->toBe(2);
});

test('soft deleted users are hidden from the admin list', function (): void {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create([
        'name' => 'Gone User',
        'email' => 'gone@example.com',
    ]);

    $target->delete();

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/users/index')
            ->has('users.data')
            ->where('users.data', fn ($data): bool => collect($data)->every(
                fn (array $row): bool => $row['email'] !== 'gone@example.com',
            ))
        );
});
