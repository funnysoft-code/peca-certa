<?php

declare(strict_types=1);

namespace App\Services\PartsLink24;

use Illuminate\Support\Str;

final readonly class VinBrandResolver
{
    public function resolve(string $vin, ?string $brandKeyOverride = null): ?PartsLink24Brand
    {
        if ($brandKeyOverride !== null && $brandKeyOverride !== '') {
            return $this->fromCatalogKey($brandKeyOverride);
        }

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

        return $this->fromCatalogKey($key);
    }

    public function fromCatalogKey(string $key): ?PartsLink24Brand
    {
        /** @var array<string, array{service: string, group: string}> $catalogs */
        $catalogs = config('suppliers.partslink24.brands.catalogs');
        $catalog = $catalogs[$key] ?? null;

        if ($catalog === null) {
            return null;
        }

        return new PartsLink24Brand($key, $catalog['service'], $catalog['group']);
    }

    /**
     * @return list<string>
     */
    public function availableBrandKeys(): array
    {
        /** @var array<string, array{service: string, group: string}> $catalogs */
        $catalogs = config('suppliers.partslink24.brands.catalogs');

        return array_keys($catalogs);
    }

    /**
     * Sibling catalog keys that share a platform family (for careful multi-catalog decode).
     * Excludes the primary key. Empty when no family is configured.
     *
     * @return list<string>
     */
    public function familyFallbackKeys(string $primaryKey): array
    {
        /** @var array<string, list<string>> $families */
        $families = config('suppliers.partslink24.brands.families', []);

        foreach ($families as $members) {
            if (! in_array($primaryKey, $members, true)) {
                continue;
            }

            return array_values(array_filter(
                $members,
                fn (string $key): bool => $key !== $primaryKey,
            ));
        }

        return [];
    }
}
