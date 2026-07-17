<?php

declare(strict_types=1);

use App\Services\PartsLink24\FakePartsLink24Catalog;

it('resolves fake OE parts for a vin and category', function (): void {
    $parts = resolve(FakePartsLink24Catalog::class)->resolveOeParts('WVWZZZ1JZXW000001', 'filtro de óleo', ['óleo']);

    expect($parts)->not->toBeEmpty()
        ->and($parts[0]->oeNumber)->not->toBe('');
});

it('returns no parts when the vin is empty', function (): void {
    expect(resolve(FakePartsLink24Catalog::class)->resolveOeParts('', 'filtro de óleo', []))->toBe([]);
});
