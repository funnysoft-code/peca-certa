<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SearchRunKind;
use App\Enums\SearchRunStatus;
use Database\Factories\SearchRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read string $id
 * @property-read string $user_id
 * @property SearchRunKind $kind
 * @property string|null $request_text
 * @property string|null $vin
 * @property string|null $reference
 * @property array<string, mixed>|null $understanding
 * @property list<array<string, mixed>>|null $oe_parts
 * @property SearchRunStatus $status
 */
final class SearchRun extends Model
{
    /** @use HasFactory<SearchRunFactory> */
    use HasFactory;

    use HasUuids;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<SupplierLookup, $this> */
    public function lookups(): HasMany
    {
        return $this->hasMany(SupplierLookup::class);
    }

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'kind' => SearchRunKind::class,
            'status' => SearchRunStatus::class,
            'understanding' => 'array',
            'oe_parts' => 'array',
        ];
    }
}
