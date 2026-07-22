<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Concerns\Filterable;
use App\Concerns\HandlesPagination;
use App\Concerns\Sortable;
use App\Queries\ListSearchRunFindingsQuery;

final class ListSearchRunFindingsRequest extends Request
{
    use Filterable;
    use HandlesPagination;
    use Sortable;

    public function authorize(): bool
    {
        // Shared workshop history: route is behind auth; any authenticated user
        // may load findings for any run (same as identify/parts show + channel).
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(
            $this->paginationRules(),
            $this->allowedFilters(['search', 'in_stock']),
            $this->allowedSorts(ListSearchRunFindingsQuery::ALLOWED_SORTS),
        );
    }
}
