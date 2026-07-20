<?php

declare(strict_types=1);

use App\Data\OePart;

it('serialises an OE part', function (): void {
    expect(new OePart('06A115561B', 'Filtro de óleo', 'VAG')->jsonSerialize())->toBe([
        'oeNumber' => '06A115561B',
        'description' => 'Filtro de óleo',
        'brand' => 'VAG',
    ]);
});

it('rebuilds an OE part from a stored array', function (): void {
    $part = OePart::fromArray([
        'oeNumber' => '06A115561B',
        'description' => 'Filtro de óleo',
        'brand' => 'VAG',
    ]);

    expect($part->oeNumber)->toBe('06A115561B')
        ->and($part->description)->toBe('Filtro de óleo')
        ->and($part->brand)->toBe('VAG');
});

it('rebuilds an OE part from an empty or malformed array', function (): void {
    $part = OePart::fromArray([]);

    expect($part->oeNumber)->toBe('')
        ->and($part->description)->toBe('')
        ->and($part->brand)->toBe('');
});
