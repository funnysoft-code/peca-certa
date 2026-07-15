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
