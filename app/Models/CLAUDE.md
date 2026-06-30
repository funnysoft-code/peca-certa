# Eloquent Models

## Conventions

- `final class` extending `Model` (or `Authenticatable` for User).
- **`User` model specifically:** `HasUuids` trait; `#[Hidden([...])]` attribute (not the legacy `$hidden` array); `casts()` must include `id => 'string'`, `email_verified_at => 'datetime'`, `password => 'hashed'`; migration uses `$table->uuid('id')->primary()`.
- Annotate `HasFactory` with generic: `/** @use HasFactory<ModelFactory> */`.
- Class-level PHPDoc block with `@property`, `@property-read` for accessors and key relationships.
- Every relationship method must have a generic PHPDoc return type: `@return HasMany<Model, $this>` or `@phpstan-return BelongsTo<Model, $this>`.
- Casts defined via `casts()` method, not the `$casts` property.
- Models with slugs override `getRouteKeyName()` returning `'slug'`.
- Auto-slug generation in `booted()` with collision handling via incrementing suffix.
- Boolean checkers (`isOwner()`, `isMember()`) don't need PHPDoc — method name and return type are sufficient.
- Models are unguarded via `nunomaduro/essentials` — do not use `$fillable` or `$guarded`.

## Patterns

Standard model with UUID primary key (User):
```php
declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property-read string $id
 * @property-read string $name
 * @property-read string $email
 * @property-read CarbonInterface|null $email_verified_at
 * @property-read string $password
 * @property-read string|null $remember_token
 * @property-read string|null $two_factor_secret
 * @property-read string|null $two_factor_recovery_codes
 * @property-read CarbonInterface|null $two_factor_confirmed_at
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
#[Hidden([
    'password',
    'remember_token',
    'two_factor_secret',
    'two_factor_recovery_codes',
])]
final class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuids;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
```

Standard model with slug route binding:
```php
/**
 * @property Carbon|null $published_at
 * @property PostStatus $status
 */
final class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    /** @phpstan-return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'status'       => PostStatus::class,
        ];
    }
}
```

Attribute accessor (get-only):
```php
/** @return Attribute<string, never> */
protected function avatarUrl(): Attribute
{
    return Attribute::make(get: fn (?string $value): string => $value
        ? Storage::url($value)
        : '/images/defaults/avatar.webp');
}
```

## Anti-Patterns

- Do not use `$casts` property — use `casts()` method.
- Do not use `$fillable` or `$guarded` — models are unguarded via `nunomaduro/essentials`.
- Do not use `$hidden` array — use `#[Hidden([...])]` attribute.
- Do not use bare `Collection` in PHPDoc — always include generics.
- Do not use `DB::` for simple queries — prefer `Model::query()`.
- Do not skip the `HasFactory` generic annotation.
- Do not use integer primary keys for User — use `HasUuids` and `$table->uuid('id')->primary()`.
