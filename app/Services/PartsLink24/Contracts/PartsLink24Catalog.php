<?php

declare(strict_types=1);

namespace App\Services\PartsLink24\Contracts;

use App\Data\OePart;

interface PartsLink24Catalog
{
    /**
     * Resolve a VIN + part category to genuine (OE) part references.
     *
     * @param  list<string>  $keywords
     * @return list<OePart>
     */
    public function resolveOeParts(string $vin, string $category, array $keywords): array;
}
