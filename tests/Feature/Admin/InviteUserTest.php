<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\InviteUserNotification;
use App\Support\Permissions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;

test('admin can invite user with name and email only', function (): void {
    Notification::fake();

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post(route('admin.users.store'), [
            'name' => 'Operador Novo',
            'email' => 'operador@example.com',
        ])
        ->assertRedirect(route('admin.users.index'));

    $invited = User::query()->where('email', 'operador@example.com')->first();

    expect($invited)->not->toBeNull()
        ->and($invited?->name)->toBe('Operador Novo')
        ->and($invited?->email_verified_at)->toBeNull()
        ->and($invited?->hasRole(Permissions::RoleUser))->toBeTrue()
        ->and($invited?->hasRole(Permissions::RoleAdmin))->toBeFalse();

    Notification::assertSentTo($invited, InviteUserNotification::class);
});

test('regular users cannot invite', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('admin.users.store'), [
            'name' => 'X',
            'email' => 'x@example.com',
        ])
        ->assertForbidden();

    Notification::assertNothingSent();
});

test('admin can resend invite for pending users', function (): void {
    Notification::fake();

    $admin = User::factory()->admin()->create();
    $pending = User::factory()->pendingInvite()->create();

    $this->actingAs($admin)
        ->post(route('admin.users.resend-invite', $pending))
        ->assertRedirect(route('admin.users.index'));

    Notification::assertSentTo($pending, InviteUserNotification::class);
});

test('set-password get does not verify email', function (): void {
    $user = User::factory()->pendingInvite()->create([
        'email' => 'pending@example.com',
    ]);

    $token = Password::broker()->createToken($user);

    $this->get(route('invite.set-password', [
        'token' => $token,
        'email' => $user->email,
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('auth/set-password')
            ->where('email', $user->email)
        );

    expect($user->fresh()?->email_verified_at)->toBeNull();
});

test('set-password with valid token sets password and verifies email', function (): void {
    $user = User::factory()->pendingInvite()->create([
        'email' => 'invitee@example.com',
    ]);

    $token = Password::broker()->createToken($user);

    $this->post(route('invite.set-password.store'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password-ok',
        'password_confirmation' => 'new-password-ok',
    ])
        ->assertRedirect(route('identify.create'));

    $user->refresh();

    expect($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('new-password-ok', $user->password))->toBeTrue();

    $this->assertAuthenticatedAs($user);
});

test('admin can assign roles after invite', function (): void {
    $admin = User::factory()->admin()->create();
    $target = User::factory()->create();

    $this->actingAs($admin)
        ->put(route('admin.users.update-role', $target), [
            'role' => Permissions::RoleAdmin,
        ])
        ->assertRedirect(route('admin.users.index'));

    expect($target->fresh()?->isAdmin())->toBeTrue();
});
