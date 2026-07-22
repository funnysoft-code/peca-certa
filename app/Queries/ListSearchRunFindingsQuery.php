<?php

declare(strict_types=1);

namespace App\Queries;

use App\Filters\SearchFindingsFilter;
use App\Models\Finding;
use App\Models\SearchRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

final class ListSearchRunFindingsQuery
{
    /** @var list<string> */
    public const array ALLOWED_SORTS = [
        'brand',
        'article',
        'price',
        'supplier',
        'available_quantity',
        'in_stock',
    ];

    /**
     * @return LengthAwarePaginator<int, Finding>
     */
    public function paginate(SearchRun $run, Request $request, int $perPage): LengthAwarePaginator
    {
        // Stock default is UI-driven: clients send filter[in_stock]=true by default.
        // "Mostrar indisponíveis" removes that key so this list returns every row.
        $query = QueryBuilder::for(
            Finding::query()->where('search_run_id', $run->id),
            $request,
        )
            ->allowedFilters(
                AllowedFilter::custom('search', new SearchFindingsFilter),
                AllowedFilter::callback('in_stock', function (Builder $query, mixed $value): void {
                    $raw = is_array($value) ? ($value[0] ?? null) : $value;
                    $query->where(
                        'in_stock',
                        filter_var($raw, FILTER_VALIDATE_BOOLEAN),
                    );
                }),
            )
            ->allowedSorts(...self::ALLOWED_SORTS)
            ->defaultSort('brand');

        /** @var LengthAwarePaginator<int, Finding> $paginator */
        $paginator = $query
            ->paginate(perPage: $perPage)
            ->appends($request->query());

        return $paginator;
    }
}
