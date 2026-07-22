<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Concerns\Filterable;
use App\Concerns\HandlesPagination;
use App\Concerns\Sortable;
use App\Models\SearchRun;
use App\Queries\ListSearchRunFindingsQuery;

final class ListSearchRunFindingsRequest extends Request
{
    use Filterable;
    use HandlesPagination;
    use Sortable;

    public function authorize(): bool
    {
        $run = $this->route('run');

        return $run instanceof SearchRun
            && $run->user_id === $this->user()->id;
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
