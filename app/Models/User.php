<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Permissions;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

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
 * @property-read CarbonInterface|null $deleted_at
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
#[Hidden([
    'password',
    'remember_token',
    'two_factor_secret',
    'two_factor_recovery_codes',
])]
final class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use HasUuids;
    use Notifiable;
    use PasskeyAuthenticatable;
    use SoftDeletes;
    use TwoFactorAuthenticatable;

    public function isAdmin(): bool
    {
        return $this->hasRole(Permissions::RoleAdmin);
    }

    /**
     * Pending invite: never completed set-password (email still unverified).
     */
    public function isPendingInvite(): bool
    {
        return $this->email_verified_at === null;
    }

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }
}
