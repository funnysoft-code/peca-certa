<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    /**
     * Cached bcrypt hash of 'password'.
     *
     * Re-hashing on every factory call is the single biggest test-suite
     * performance killer (bcrypt is intentionally slow). One hash per
     * process is sufficient — test isolation is provided by unique email
     * addresses and RefreshDatabase, not distinct passwords.
     */
    private static string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => self::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            if ($user->roles()->exists()) {
                return;
            }

            $this->ensureRolesSeeded();
            $user->assignRole(Permissions::RoleUser);
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Invited user who has not set a password yet.
     */
    public function pendingInvite(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
            'password' => self::$password ??= Hash::make(Str::password(32)),
        ]);
    }

    /**
     * Assign the admin role after create.
     */
    public function admin(): static
    {
        return $this->afterCreating(function (User $user): void {
            $this->ensureRolesSeeded();
            $user->syncRoles([Permissions::RoleAdmin]);
        });
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes): array => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * Indicate that the model does not have two-factor authentication configured.
     *
     * Use this state in browser tests and any test that fills a login form —
     * 2FA challenge pages require separate handling.
     */
    public function withoutTwoFactor(): static
    {
        return $this->state(fn (array $attributes): array => [
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);
    }

    private function ensureRolesSeeded(): void
    {
        // RefreshDatabase wipes tables between tests; re-seed when roles are gone.
        if (Role::query()->exists()) {
            return;
        }

        (new RolesAndPermissionsSeeder)->run();
    }
}
