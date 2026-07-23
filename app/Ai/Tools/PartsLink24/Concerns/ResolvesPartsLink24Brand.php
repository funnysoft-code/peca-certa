<?php

declare(strict_types=1);

namespace App\Ai\Tools\PartsLink24\Concerns;

use App\Services\PartsLink24\PartsLink24Brand;
use App\Services\PartsLink24\VinBrandResolver;
use Illuminate\Support\Facades\Context;

trait ResolvesPartsLink24Brand
{
    private function brandForVin(string $vin): ?PartsLink24Brand
    {
        $override = Context::get('identify.brand_override');
        $brandKeyOverride = is_string($override) && $override !== '' ? $override : null;

        return resolve(VinBrandResolver::class)->resolve($vin, $brandKeyOverride);
    }

    /**
     * @return array{ok: false, error: string, message: string, availableBrands: list<string>}
     */
    private function unsupportedBrand(): array
    {
        return [
            'ok' => false,
            'error' => 'unsupported_brand',
            'message' => 'WMI unknown or PartsLink24 catalog not configured for this VIN. Operator must pick a brand catalog override; do not ask model or year.',
            'availableBrands' => resolve(VinBrandResolver::class)->availableBrandKeys(),
        ];
    }
}
