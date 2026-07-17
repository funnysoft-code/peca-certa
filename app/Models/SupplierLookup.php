<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Supplier;
use App\Enums\SupplierLookupStatus;
use Database\Factories\SupplierLookupFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read string $id
 * @property-read string $search_run_id
 * @property Supplier $supplier
 * @property string $query
 * @property string|null $oe_description
 * @property SupplierLookupStatus $status
 * @property array<string, mixed>|null $result
 * @property string|null $error
 */
final class SupplierLookup extends Model
{
    /** @use HasFactory<SupplierLookupFactory> */
    use HasFactory;

    use HasUuids;

    /** @return BelongsTo<SearchRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(SearchRun::class, 'search_run_id');
    }

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'supplier' => Supplier::class,
            'status' => SupplierLookupStatus::class,
            'result' => 'array',
        ];
    }
}
