<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\PaginatedSearchRunsData;
use App\Enums\SearchRunKind;
use App\Models\User;
use App\Queries\ListSearchRunsQuery;
use Illuminate\Http\Request;

final readonly class ListSearchRuns
{
    public function __construct(
        private ListSearchRunsQuery $query,
    ) {}

    public function execute(Request $request, SearchRunKind $kind, User $user): PaginatedSearchRunsData
    {
        $paginator = $this->query->paginate($request, $kind, $user);

        return PaginatedSearchRunsData::fromPaginator($paginator);
    }
}
