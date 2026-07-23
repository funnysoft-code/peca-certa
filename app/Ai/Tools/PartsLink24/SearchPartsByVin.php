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

final class SearchPartsByVin implements Tool
{
    use ResolvesPartsLink24Brand;
    use SoftFailsPartsLink24Http;

    public function __construct(
        private PartsLink24Client $client,
    ) {}

    public function name(): string
    {
        return 'search_parts_by_vin';
    }

    public function description(): string
    {
        return 'Search OE parts for a VIN with an English free-text query (e.g. "oil filter"). Results are noisy and may include package-only parts not fitted to this VIN; always confirm candidates via list_bom_parts (use maingroup + btnr) before selecting. Returns ok:false http_error on 4xx/5xx.';
    }

    public function handle(Request $request): string
    {
        return $this->withSoftHttp(function () use ($request): string {
            $vin = (string) $request->string('vin');
            $query = (string) $request->string('query');
            $brand = $this->brandForVin($vin);

            if (! $brand instanceof PartsLink24Brand) {
                return json_encode($this->unsupportedBrand(), JSON_THROW_ON_ERROR);
            }

            $results = $this->client->searchByVin($brand, $vin, $query);

            return json_encode(['ok' => true, 'results' => $results], JSON_THROW_ON_ERROR);
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'vin' => $schema->string()->required(),
            'query' => $schema->string()->required(),
        ];
    }
}
