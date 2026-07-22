<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\SearchRunKind;
use App\Models\SearchRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

final class ListSearchRunsQuery
{
    public const int PER_PAGE = 10;

    /**
     * @return LengthAwarePaginator<int, SearchRun>
     */
    public function paginate(
        Request $request,
        SearchRunKind $kind,
        User $user,
        int $perPage = self::PER_PAGE,
    ): LengthAwarePaginator {
        $scope = $this->scope($request);
        $q = $this->searchTerm($request);

        $query = SearchRun::query()
            ->where('kind', $kind)
            ->with(['lookups', 'user'])
            ->latest();

        if ($scope === 'mine') {
            $query->where('user_id', $user->id);
        }

        if ($q !== '') {
            $like = '%'.addcslashes(mb_strtolower($q), '%_\\').'%';

            $query->where(function (Builder $builder) use ($like): void {
                $builder
                    ->whereRaw("LOWER(COALESCE(request_text, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(reference, '')) LIKE ?", [$like])
                    ->orWhereRaw("LOWER(COALESCE(vin, '')) LIKE ?", [$like])
                    ->orWhereHas('user', function (Builder $userQuery) use ($like): void {
                        $userQuery->whereRaw('LOWER(name) LIKE ?', [$like]);
                    });
            });
        }

        /** @var LengthAwarePaginator<int, SearchRun> $paginator */
        $paginator = $query
            ->paginate(perPage: $perPage)
            ->appends($request->query());

        return $paginator;
    }

    public function scope(Request $request): string
    {
        return $request->query('scope') === 'mine' ? 'mine' : 'everyone';
    }

    public function searchTerm(Request $request): string
    {
        $raw = $request->query('q', '');

        return is_string($raw) ? mb_trim($raw) : '';
    }
}
