<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Spatie Role with a concrete integer key for Wayfinder / PHPStan.
 *
 * Spatie's stock PHPDoc uses `int|string $id`, which makes Wayfinder emit
 * string route params when the DB schema is unavailable (CI TypeScript job).
 *
 * @property int $id
 * @property string $name
 * @property string $guard_name
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 *
 * @use HasFactory<RoleFactory>
 */
final class Role extends SpatieRole
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;
}
