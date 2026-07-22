<?php

declare(strict_types=1);

namespace App\Ai\Tools\PartsLink24\Concerns;

use App\Services\PartsLink24\PartsLink24Brand;
use App\Services\PartsLink24\VinBrandResolver;

trait ResolvesPartsLink24Brand
{
    private function brandForVin(string $vin): ?PartsLink24Brand
    {
        return resolve(VinBrandResolver::class)->resolve($vin);
    }

    /**
     * @return array{ok: false, error: string}
     */
    private function unsupportedBrand(): array
    {
        return ['ok' => false, 'error' => 'unsupported_brand'];
    }
}
