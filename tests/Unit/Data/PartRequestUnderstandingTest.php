<?php

declare(strict_types=1);

use App\Data\PartRequestUnderstanding;

it('serialises an understanding', function (): void {
    $u = new PartRequestUnderstanding('filtro de óleo', 'oil filter', ['OC 90', 'óleo'], null, 0.9);

    expect($u->jsonSerialize())->toBe([
        'category' => 'filtro de óleo',
        'searchTerm' => 'oil filter',
        'keywords' => ['OC 90', 'óleo'],
        'clarifyingQuestion' => null,
        'confidence' => 0.9,
    ]);
});

it('flags when a clarifying question is needed', function (): void {
    $u = new PartRequestUnderstanding('', '', [], 'Qual é o motor?', 0.2);

    expect($u->needsClarification())->toBeTrue();
});

it('rebuilds an understanding from a stored array', function (): void {
    $u = PartRequestUnderstanding::fromArray([
        'category' => 'filtro de óleo',
        'searchTerm' => 'oil filter',
        'keywords' => ['OC 90', 'óleo', 42],
        'clarifyingQuestion' => null,
        'confidence' => 0.9,
    ]);

    expect($u->category)->toBe('filtro de óleo')
        ->and($u->searchTerm)->toBe('oil filter')
        ->and($u->keywords)->toBe(['OC 90', 'óleo'])
        ->and($u->clarifyingQuestion)->toBeNull()
        ->and($u->confidence)->toBe(0.9);
});

it('rebuilds an understanding from an empty or malformed array', function (): void {
    $u = PartRequestUnderstanding::fromArray([]);

    expect($u->category)->toBe('')
        ->and($u->searchTerm)->toBe('')
        ->and($u->keywords)->toBe([])
        ->and($u->clarifyingQuestion)->toBeNull()
        ->and($u->confidence)->toBe(0.0);
});

it('rebuilds an understanding with an empty keywords list when keywords is not an array', function (): void {
    $u = PartRequestUnderstanding::fromArray(['keywords' => 'not-an-array']);

    expect($u->keywords)->toBe([]);
});

it('rebuilds an understanding with a real clarifying question', function (): void {
    $u = PartRequestUnderstanding::fromArray([
        'category' => 'filtro de óleo',
        'searchTerm' => 'oil filter',
        'keywords' => ['OC 90'],
        'clarifyingQuestion' => 'Qual é o motor?',
        'confidence' => 0.4,
    ]);

    expect($u->clarifyingQuestion)->toBe('Qual é o motor?');
});
