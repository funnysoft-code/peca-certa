<?php

declare(strict_types=1);

use App\Data\IdentifyResult;
use App\Data\OePart;
use App\Data\PartRequestUnderstanding;
use App\Data\PartSearchResult;

it('serialises an identify result', function (): void {
    $understanding = new PartRequestUnderstanding('filtro de óleo', 'oil filter', ['óleo'], null, 0.9);
    $oePart = new OePart('OC 90', 'Filtro de óleo', 'OE');
    $delta = new PartSearchResult('OC 90', []);

    $result = new IdentifyResult($understanding, [$oePart], [$delta], []);

    expect($result->jsonSerialize())->toBe([
        'understanding' => $understanding,
        'oeParts' => [$oePart],
        'autoDeltaResults' => [$delta],
        'autoZitaniaResults' => [],
    ]);
});
