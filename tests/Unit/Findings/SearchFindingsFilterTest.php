<?php

declare(strict_types=1);

use App\Filters\SearchFindingsFilter;
use App\Models\Finding;
use Illuminate\Database\Eloquent\Builder;

it('ignores empty and non-scalar search values', function (): void {
    $filter = new SearchFindingsFilter;
    $query = Finding::query();

    $filter($query, '', 'search');
    $filter($query, ['  '], 'search');
    $filter($query, new stdClass, 'search');

    expect($query->toSql())->not->toContain('like');
});

it('applies a multi-column like clause for a scalar term', function (): void {
    $filter = new SearchFindingsFilter;
    /** @var Builder<Finding> $query */
    $query = Finding::query();

    $filter($query, 'OC90', 'search');

    expect($query->toSql())->toContain('like');
});
