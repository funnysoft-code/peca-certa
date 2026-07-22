<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\PaginatedFindingsData;
use App\Models\SearchRun;
use App\Queries\ListSearchRunFindingsQuery;
use Illuminate\Http\Request;

final readonly class ListSearchRunFindings
{
    public function __construct(
        private ListSearchRunFindingsQuery $query,
    ) {}

    public function execute(SearchRun $run, Request $request, int $perPage): PaginatedFindingsData
    {
        $paginator = $this->query->paginate($run, $request, $perPage);

        return PaginatedFindingsData::fromPaginator($paginator);
    }
}
