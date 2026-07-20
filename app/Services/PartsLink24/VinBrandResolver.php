<?php

declare(strict_types=1);

namespace App\Services\PartsLink24;

use Illuminate\Support\Str;

final readonly class VinBrandResolver
{
    public function resolve(string $vin): ?PartsLink24Brand
    {
        if (Str::length($vin) < 3) {
            return null;
        }

        $wmi = Str::upper(Str::substr($vin, 0, 3));

        /** @var array<string, string> $wmiMap */
        $wmiMap = config('suppliers.partslink24.brands.wmi');
        $key = $wmiMap[$wmi] ?? null;

        if ($key === null) {
            return null;
        }

        /** @var array<string, array{service: string, group: string}> $catalogs */
        $catalogs = config('suppliers.partslink24.brands.catalogs');
        $catalog = $catalogs[$key] ?? null;

        if ($catalog === null) {
            return null;
        }

        return new PartsLink24Brand($key, $catalog['service'], $catalog['group']);
    }
}
