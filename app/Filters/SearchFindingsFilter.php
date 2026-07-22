<?php

declare(strict_types=1);

namespace App\Filters;

use App\Models\Finding;
use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * @implements Filter<Finding>
 */
final class SearchFindingsFilter implements Filter
{
    /**
     * @param  Builder<Finding>  $query
     */
    public function __invoke(Builder $query, mixed $value, string $property): void
    {
        $raw = is_array($value) ? ($value[0] ?? '') : $value;

        if (! is_string($raw) && ! is_numeric($raw)) {
            return;
        }

        $term = mb_trim((string) $raw);

        if ($term === '') {
            return;
        }

        $like = '%'.$term.'%';

        $query->where(function (Builder $builder) use ($like): void {
            $builder
                ->where('supplier', 'like', $like)
                ->orWhere('brand', 'like', $like)
                ->orWhere('article', 'like', $like);
        });
    }
}
