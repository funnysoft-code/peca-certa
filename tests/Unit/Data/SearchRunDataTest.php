<?php

declare(strict_types=1);

use App\Data\SearchRunData;
use App\Enums\SearchRunStatus;
use App\Models\SearchRun;
use App\Models\SupplierLookup;

it('builds SearchRunData from a run with lookups', function (): void {
    $run = SearchRun::factory()->create([
        'status' => SearchRunStatus::Running,
        'understanding' => ['category' => 'filtro de óleo', 'searchTerm' => 'oil filter', 'keywords' => [], 'clarifyingQuestion' => null, 'confidence' => 0.9],
        'oe_parts' => [['oeNumber' => '11427622446', 'description' => 'oil filter element', 'brand' => 'OE']],
    ]);
    SupplierLookup::factory()->for($run, 'run')->create();

    $data = SearchRunData::fromModel($run->load('lookups'));

    expect($data->status)->toBe(SearchRunStatus::Running)
        ->and($data->understanding?->searchTerm)->toBe('oil filter')
        ->and($data->oeParts)->toHaveCount(1)
        ->and($data->oeParts[0]->oeNumber)->toBe('11427622446')
        ->and($data->lookups)->toHaveCount(1)
        ->and($data->jsonSerialize())->toHaveKeys(['id', 'kind', 'status', 'understanding', 'oeParts', 'lookups']);
});

it('builds SearchRunData from a run with no understanding, oe_parts or lookups', function (): void {
    $run = SearchRun::factory()->create([
        'understanding' => null,
        'oe_parts' => null,
    ]);

    $data = SearchRunData::fromModel($run->load('lookups'));

    expect($data->understanding)->toBeNull()
        ->and($data->oeParts)->toBe([])
        ->and($data->lookups)->toBe([]);
});
