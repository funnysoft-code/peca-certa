<?php

declare(strict_types=1);

namespace App\Ai\Tools\PartsLink24;

use App\Ai\Tools\PartsLink24\Concerns\ResolvesPartsLink24Brand;
use App\Services\PartsLink24\PartsLink24Brand;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final class ResolveBrand implements Tool
{
    use ResolvesPartsLink24Brand;

    public function description(): string
    {
        return 'Resolve the PartsLink24 brand catalog for a VIN (WMI → service/group). Call first if unsure which catalog applies.';
    }

    public function handle(Request $request): string
    {
        $vin = (string) $request->string('vin');
        $brand = $this->brandForVin($vin);

        if (! $brand instanceof PartsLink24Brand) {
            return json_encode($this->unsupportedBrand(), JSON_THROW_ON_ERROR);
        }

        return json_encode([
            'ok' => true,
            'brandKey' => $brand->key,
            'service' => $brand->service,
            'group' => $brand->group,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'vin' => $schema->string()->required(),
        ];
    }
}
