<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Supplier;
use Database\Factories\FindingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read string $id
 * @property-read string $search_run_id
 * @property-read string $supplier_lookup_id
 * @property Supplier $supplier
 * @property string $brand
 * @property string $article
 * @property string $trader_article_number
 * @property string|null $price
 * @property string $currency
 * @property int $available_quantity
 * @property bool $in_stock
 * @property string $warehouse
 */
final class Finding extends Model
{
    /** @use HasFactory<FindingFactory> */
    use HasFactory;

    use HasUuids;

    /** @return BelongsTo<SearchRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(SearchRun::class, 'search_run_id');
    }

    /** @return BelongsTo<SupplierLookup, $this> */
    public function lookup(): BelongsTo
    {
        return $this->belongsTo(SupplierLookup::class, 'supplier_lookup_id');
    }

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'supplier' => Supplier::class,
            'price' => 'decimal:2',
            'available_quantity' => 'integer',
            'in_stock' => 'boolean',
        ];
    }
}
