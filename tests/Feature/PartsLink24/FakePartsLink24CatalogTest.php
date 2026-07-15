<?php

declare(strict_types=1);

use App\Services\PartsLink24\Contracts\PartsLink24Catalog;

it('resolves fake OE parts for a vin and category', function (): void {
    $parts = resolve(PartsLink24Catalog::class)->resolveOeParts('WVWZZZ1JZXW000001', 'filtro de óleo', ['óleo']);

    expect($parts)->not->toBeEmpty()
        ->and($parts[0]->oeNumber)->not->toBe('');
});
