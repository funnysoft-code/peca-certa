<?php

declare(strict_types=1);

namespace App\Ai\Tools\PartsLink24;

use App\Ai\Tools\PartsLink24\Concerns\ResolvesPartsLink24Brand;
use App\Services\PartsLink24\PartsLink24Brand;
use App\Services\PartsLink24\PartsLink24Client;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

final class ListMainGroups implements Tool
{
    use ResolvesPartsLink24Brand;

    public function __construct(
        private PartsLink24Client $client,
    ) {}

    public function description(): string
    {
        return 'List top-level PartsLink24 catalog groups for a VIN (Engine, Brakes, Body, …).';
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
            'groups' => $this->client->listMainGroups($brand, $vin),
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
