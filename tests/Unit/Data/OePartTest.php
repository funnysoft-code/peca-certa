<?php

declare(strict_types=1);

use App\Data\OePart;

it('serialises an OE part', function (): void {
    expect(new OePart('06A115561B', 'Filtro de óleo', 'VAG')->jsonSerialize())->toBe([
        'oeNumber' => '06A115561B',
        'description' => 'Filtro de óleo',
        'brand' => 'VAG',
        'factoryFit' => null,
        'pos' => null,
        'mainGroupId' => null,
        'btnr' => null,
        'diagramPath' => null,
        'diagramUrl' => null,
        'applicability' => null,
    ]);
});

it('rebuilds an OE part from a stored array', function (): void {
    $part = OePart::fromArray([
        'oeNumber' => '06A115561B',
        'description' => 'Filtro de óleo',
        'brand' => 'VAG',
        'factoryFit' => true,
        'pos' => '01',
        'diagramPath' => 'diagrams/abc.png',
    ]);

    expect($part->oeNumber)->toBe('06A115561B')
        ->and($part->description)->toBe('Filtro de óleo')
        ->and($part->brand)->toBe('VAG')
        ->and($part->factoryFit)->toBeTrue()
        ->and($part->pos)->toBe('01')
        ->and($part->diagramPath)->toBe('diagrams/abc.png');
});

it('rebuilds an OE part from an empty or malformed array', function (): void {
    $part = OePart::fromArray([]);

    expect($part->oeNumber)->toBe('')
        ->and($part->description)->toBe('')
        ->and($part->brand)->toBe('')
        ->and($part->factoryFit)->toBeNull();
});
