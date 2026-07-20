<?php

declare(strict_types=1);

use App\Actions\IdentifyOeParts;
use App\Data\OePart;
use App\Services\PartsLink24\Contracts\PartsLink24Catalog;

it('delegates identification to the catalog', function (): void {
    $this->mock(PartsLink24Catalog::class)
        ->shouldReceive('resolveOeParts')->once()
        ->with('VIN1', 'filtro de óleo', ['óleo'])
        ->andReturn([new OePart('06A115561B', 'Filtro', 'VAG')]);

    $parts = resolve(IdentifyOeParts::class)->execute('VIN1', 'filtro de óleo', ['óleo']);

    expect($parts)->toHaveCount(1)->and($parts[0]->oeNumber)->toBe('06A115561B');
});
