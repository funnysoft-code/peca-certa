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

it('applies case-insensitive lower-bound likes for brand article and supplier', function (): void {
    $filter = new SearchFindingsFilter;
    /** @var Builder<Finding> $query */
    $query = Finding::query();

    $filter($query, 'Meyle', 'search');

    $sql = mb_strtolower($query->toSql());

    expect($sql)->toContain('lower(supplier)')
        ->and($sql)->toContain('lower(brand)')
        ->and($sql)->toContain('lower(article)')
        ->and($query->getBindings())->toContain('%meyle%');
});

it('escapes sql like wildcards in the user term', function (): void {
    $filter = new SearchFindingsFilter;
    $query = Finding::query();

    $filter($query, '100%', 'search');

    expect($query->getBindings())->toContain('%100\%%');
});
