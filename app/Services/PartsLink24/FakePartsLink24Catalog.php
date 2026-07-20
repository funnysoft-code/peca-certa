<?php

declare(strict_types=1);

namespace App\Services\PartsLink24;

use App\Data\OePart;
use App\Services\PartsLink24\Contracts\PartsLink24Catalog;

/**
 * Placeholder until the real HTTP client lands in Plan 2. Returns a single
 * deterministic OE part so the identify flow is exercisable end-to-end.
 */
final readonly class FakePartsLink24Catalog implements PartsLink24Catalog
{
    public function resolveOeParts(string $vin, string $category, array $keywords): array
    {
        if ($vin === '') {
            return [];
        }

        return [new OePart('OC 90', $category !== '' ? $category : 'peça', 'OE')];
    }
}
