<?php

declare(strict_types=1);

use App\Enums\Supplier;
use App\Jobs\IdentifyOePartsJob;
use App\Jobs\PriceSupplierJob;
use App\Jobs\UnderstandRequestJob;
use App\Models\SearchRun;
use App\Models\SupplierLookup;

it('routes identify pipeline jobs to the managed queue names', function (): void {
    $run = SearchRun::factory()->create();
    $autoDeltaLookup = SupplierLookup::factory()->for($run, 'run')->create([
        'supplier' => Supplier::AutoDelta,
    ]);
    $zitaniaLookup = SupplierLookup::factory()->for($run, 'run')->create([
        'supplier' => Supplier::AutoZitania,
    ]);

    expect((new UnderstandRequestJob($run))->queue)->toBe('ai')
        ->and((new IdentifyOePartsJob($run))->queue)->toBe('partslink24')
        ->and((new PriceSupplierJob($autoDeltaLookup))->queue)->toBe('autodelta')
        ->and((new PriceSupplierJob($zitaniaLookup))->queue)->toBe('zitania');
});
