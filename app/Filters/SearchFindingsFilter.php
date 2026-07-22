<?php

declare(strict_types=1);

namespace App\Filters;

use App\Enums\Supplier;
use App\Models\Finding;
use Illuminate\Database\Eloquent\Builder;
use Spatie\QueryBuilder\Filters\Filter;

/**
 * Case-insensitive multi-column OR search over supplier / brand / article.
 *
 * Uses LOWER(... ) LIKE so PostgreSQL (production) matches the same way as
 * SQLite's more forgiving LIKE, and so "Meyle" finds brand "MEYLE".
 *
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

        $needle = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';
        $matchedSuppliers = $this->matchingSupplierValues($term);

        $query->where(function (Builder $builder) use ($needle, $matchedSuppliers): void {
            $builder
                ->whereRaw('LOWER(supplier) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(brand) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(article) LIKE ?', [$needle]);

            if ($matchedSuppliers !== []) {
                $builder->orWhereIn('supplier', $matchedSuppliers);
            }
        });
    }

    /**
     * Map free-text (including UI labels like "Auto Delta") onto supplier enum values.
     *
     * @return list<string>
     */
    private function matchingSupplierValues(string $term): array
    {
        $normalized = $this->normalize($term);
        $matches = [];

        foreach (Supplier::cases() as $supplier) {
            $candidates = [
                $this->normalize($supplier->value),
                $this->normalize($this->label($supplier)),
            ];

            foreach ($candidates as $candidate) {
                if (str_contains($candidate, $normalized)) {
                    $matches[] = $supplier->value;

                    break;
                }
            }
        }

        return $matches;
    }

    private function label(Supplier $supplier): string
    {
        return match ($supplier) {
            Supplier::AutoDelta => 'Auto Delta',
            Supplier::AutoZitania => 'Auto Zitânia',
        };
    }

    private function normalize(string $value): string
    {
        $lower = mb_strtolower($value);

        // Fold common Portuguese diacritics so "Zitania" matches "Zitânia".
        $folded = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);

        return is_string($folded) ? $folded : $lower;
    }
}
