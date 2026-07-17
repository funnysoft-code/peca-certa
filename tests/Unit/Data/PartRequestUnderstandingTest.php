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
