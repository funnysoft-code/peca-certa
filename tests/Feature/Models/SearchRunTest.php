<?php

declare(strict_types=1);

use App\Enums\SearchRunKind;
use App\Enums\SupplierLookupStatus;
use App\Models\Finding;
use App\Models\SearchRun;
use App\Models\SupplierLookup;
use App\Models\User;

it('persists a run with lookups and casts enums + json', function (): void {
    $user = User::factory()->create();
    $run = SearchRun::factory()->for($user)->create(['kind' => SearchRunKind::Identify, 'oe_parts' => [['oeNumber' => 'OC 90']]]);
    $lookup = SupplierLookup::factory()->for($run, 'run')->create(['status' => SupplierLookupStatus::Done, 'result' => ['query' => 'OC 90']]);

    expect($run->kind)->toBe(SearchRunKind::Identify)
        ->and($run->oe_parts)->toBe([['oeNumber' => 'OC 90']])
        ->and($run->lookups)->toHaveCount(1)
        ->and($run->user->is($user))->toBeTrue()
        ->and($lookup->status)->toBe(SupplierLookupStatus::Done)
        ->and($lookup->run->is($run))->toBeTrue();
});

it('relates findings to the run and lookup', function (): void {
    $run = SearchRun::factory()->create();
    $lookup = SupplierLookup::factory()->for($run, 'run')->create();
    $finding = Finding::factory()->forLookup($lookup)->create();

    expect($run->findings)->toHaveCount(1)
        ->and($finding->run->is($run))->toBeTrue()
        ->and($finding->lookup->is($lookup))->toBeTrue();
});
