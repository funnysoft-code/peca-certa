<?php

declare(strict_types=1);

use App\Data\SearchRunData;
use App\Enums\SearchRunStatus;
use App\Models\SearchRun;
use App\Models\SupplierLookup;
use Illuminate\Support\Facades\Storage;

it('builds SearchRunData from a run with lookups', function (): void {
    $run = SearchRun::factory()->create([
        'status' => SearchRunStatus::Running,
        'understanding' => ['category' => 'filtro de óleo', 'searchTerm' => 'oil filter', 'keywords' => [], 'clarifyingQuestion' => null, 'confidence' => 0.9],
        'oe_parts' => [['oeNumber' => '11427622446', 'description' => 'oil filter element', 'brand' => 'OE']],
    ]);
    SupplierLookup::factory()->for($run, 'run')->create();

    $data = SearchRunData::fromModel($run->load(['lookups', 'user']));

    expect($data->status)->toBe(SearchRunStatus::Running)
        ->and($data->understanding?->searchTerm)->toBe('oil filter')
        ->and($data->oeParts)->toHaveCount(1)
        ->and($data->oeParts[0]->oeNumber)->toBe('11427622446')
        ->and($data->lookups)->toHaveCount(1)
        ->and($data->createdAt)->toBe($run->created_at?->toISOString())
        ->and($data->createdAt)->not->toBeEmpty()
        ->and($data->authorName)->toBe($run->user?->name)
        ->and($data->agentSteps)->toBe([])
        ->and($data->unavailableIncluded)->toBeFalse()
        ->and($data->jsonSerialize())->toHaveKeys(['id', 'kind', 'status', 'understanding', 'oeParts', 'lookups', 'agentSteps', 'createdAt', 'authorName', 'unavailableIncluded']);
});

it('builds SearchRunData from a run with no understanding, oe_parts or lookups', function (): void {
    $run = SearchRun::factory()->create([
        'understanding' => null,
        'oe_parts' => null,
    ]);

    $data = SearchRunData::fromModel($run->load(['lookups', 'user']));

    expect($data->understanding)->toBeNull()
        ->and($data->oeParts)->toBe([])
        ->and($data->lookups)->toBe([]);
});

it('falls back to an empty string when the run has no created_at', function (): void {
    $run = SearchRun::factory()->create();
    $run->created_at = null;

    $data = SearchRunData::fromModel($run->load(['lookups', 'user']));

    expect($data->createdAt)->toBe('');
});

it('includes the author display name from the related user', function (): void {
    $run = SearchRun::factory()->create();
    $run->user?->update(['name' => 'Workshop Tech']);

    $data = SearchRunData::fromModel($run->fresh()->load(['lookups', 'user']));

    expect($data->authorName)->toBe('Workshop Tech')
        ->and($data->jsonSerialize()['authorName'])->toBe('Workshop Tech');
});

it('mints an auth-gated diagram url from the private disk path', function (): void {
    config()->set('suppliers.partslink24.diagrams_disk', 'pl24_diagrams');
    Storage::fake('pl24_diagrams');
    Storage::disk('pl24_diagrams')->put('diagrams/test.png', 'png');

    $run = SearchRun::factory()->create([
        'oe_parts' => [[
            'oeNumber' => '25117605282',
            'description' => 'Knob',
            'brand' => 'OE',
            'factoryFit' => true,
            'pos' => '01',
            'diagramPath' => 'diagrams/test.png',
            'diagramUrl' => null,
        ]],
    ]);

    $data = SearchRunData::fromModel($run->load(['lookups', 'user']));

    expect($data->oeParts[0]->diagramPath)->toBe('diagrams/test.png')
        ->and($data->oeParts[0]->factoryFit)->toBeTrue()
        ->and($data->oeParts[0]->pos)->toBe('01')
        ->and($data->oeParts[0]->diagramUrl)->toBe(route('identify.diagram', [
            'run' => $run,
            'filename' => 'test.png',
        ]))
        ->and($data->oeParts[0]->diagramUrl)->not->toContain('/storage/');
});
