<?php

declare(strict_types=1);

namespace App\Ai\Tools\PartsLink24;

use App\Ai\Tools\PartsLink24\Concerns\ResolvesPartsLink24Brand;
use App\Ai\Tools\PartsLink24\Concerns\SoftFailsPartsLink24Http;
use App\Services\PartsLink24\PartsLink24Brand;
use App\Services\PartsLink24\PartsLink24Client;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final class ListBomParts implements Tool
{
    use ResolvesPartsLink24Brand;
    use SoftFailsPartsLink24Http;

    public function __construct(
        private PartsLink24Client $client,
    ) {}

    public function name(): string
    {
        return 'list_bom_parts';
    }

    public function description(): string
    {
        return 'List OE parts on a BOM/illustration page (mainGroupId + btnr). Each part includes factoryFit (true = factory / non-greyed), unavailable (true = greyed option pack), remark, applicability, maingroup, btnr, pos. Prefer factoryFit=true. Never auto-select unavailable/factoryFit=false when a factoryFit=true row exists for the same pos. Returns ok:false http_error on 4xx/5xx.';
    }

    public function handle(Request $request): string
    {
        return $this->withSoftHttp(function () use ($request): string {
            $vin = (string) $request->string('vin');
            $mainGroupId = (string) $request->string('mainGroupId');
            $btnr = (string) $request->string('btnr');
            $brand = $this->brandForVin($vin);

            if (! $brand instanceof PartsLink24Brand) {
                return json_encode($this->unsupportedBrand(), JSON_THROW_ON_ERROR);
            }

            return json_encode([
                'ok' => true,
                'parts' => $this->client->listBomParts($brand, $vin, $mainGroupId, $btnr),
            ], JSON_THROW_ON_ERROR);
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'vin' => $schema->string()->required(),
            'mainGroupId' => $schema->string()->required(),
            'btnr' => $schema->string()->required(),
        ];
    }
}
