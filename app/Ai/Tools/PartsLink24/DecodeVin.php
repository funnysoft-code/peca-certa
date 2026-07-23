<?php

declare(strict_types=1);

namespace App\Ai\Tools\PartsLink24;

use App\Ai\Tools\PartsLink24\Concerns\ResolvesPartsLink24Brand;
use App\Ai\Tools\PartsLink24\Concerns\SoftFailsPartsLink24Http;
use App\Services\PartsLink24\PartsLink24Brand;
use App\Services\PartsLink24\PartsLink24Client;
use App\Services\PartsLink24\VinBrandResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final class DecodeVin implements Tool
{
    use ResolvesPartsLink24Brand;
    use SoftFailsPartsLink24Http;

    public function __construct(
        private PartsLink24Client $client,
        private VinBrandResolver $vinBrandResolver,
    ) {}

    public function name(): string
    {
        return 'decode_vin';
    }

    public function description(): string
    {
        return 'Decode a VIN via PartsLink24 (model, production date, color, equipment summary).';
    }

    public function handle(Request $request): string
    {
        return $this->withSoftHttp(function () use ($request): string {
            $vin = (string) $request->string('vin');
            $brand = $this->brandForVin($vin);

            if (! $brand instanceof PartsLink24Brand) {
                return json_encode($this->unsupportedBrand(), JSON_THROW_ON_ERROR);
            }

            $vehicle = $this->client->decodeVin($brand, $vin);

            if ($vehicle !== null) {
                return json_encode([
                    'ok' => true,
                    'brandKey' => $brand->key,
                    ...$vehicle,
                ], JSON_THROW_ON_ERROR);
            }

            // Careful family try: same session cost guarded by short sibling list.
            foreach ($this->vinBrandResolver->familyFallbackKeys($brand->key) as $siblingKey) {
                $sibling = $this->vinBrandResolver->fromCatalogKey($siblingKey);

                if (! $sibling instanceof PartsLink24Brand) {
                    continue;
                }

                $retry = $this->client->decodeVin($sibling, $vin);

                if ($retry !== null) {
                    return json_encode([
                        'ok' => true,
                        'brandKey' => $sibling->key,
                        'fallbackFrom' => $brand->key,
                        ...$retry,
                    ], JSON_THROW_ON_ERROR);
                }
            }

            return json_encode(['ok' => false, 'error' => 'vin_not_identified'], JSON_THROW_ON_ERROR);
        });
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
