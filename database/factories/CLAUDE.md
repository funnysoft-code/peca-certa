# Factories

## Conventions

- `final class` extending `Factory<Model>` with `@extends Factory<Model>` PHPDoc.
- `declare(strict_types=1)` at the top of every file.
- Use `fake()` helper — not `$this->faker`.
- Static password caching: `private static string $password; self::$password ??= Hash::make('password')` — prevents rehashing on every `create()` and keeps the test suite fast.
- State methods return `static` and use `$this->state(fn (array $attributes): array => [...])`.
- Role assignment via `afterCreating()` callback.
- `UserFactory` must include a `withoutTwoFactor()` state — bcrypt per call crawls the suite if 2FA keys are generated for every user.
- Naming: `{Model}Factory`.

## Patterns

UserFactory with static password cache and `withoutTwoFactor()`:
```php
declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    private static string $password;

    public function definition(): array
    {
        return [
            'name'              => fake()->name(),
            'email'             => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password'          => self::$password ??= Hash::make('password'),
            'remember_token'    => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Prevents bcrypt per-call cost when 2FA is not relevant to the test.
     */
    public function withoutTwoFactor(): static
    {
        return $this->state(fn (array $attributes): array => [
            'two_factor_secret'         => null,
            'two_factor_recovery_codes' => null,
        ]);
    }
}
```

State method pattern:
```php
public function published(): static
{
    return $this->state(fn (array $attributes): array => [
        'status'       => PostStatus::Published,
        'published_at' => now(),
    ]);
}
```

Role state via `afterCreating()`:
```php
public function admin(): static
{
    return $this->afterCreating(function (User $user): void {
        $user->assignRole('admin');
    });
}
```

## Anti-Patterns

- Do not use `$this->faker` — use `fake()` helper.
- Do not hash passwords without static caching — use `self::$password ??= Hash::make(...)`.
- Do not assign roles in `definition()` — use `afterCreating()` state methods.
- Do not omit the `@extends Factory<Model>` PHPDoc annotation.
- Do not omit `withoutTwoFactor()` state from `UserFactory` if Fortify 2FA is installed.
