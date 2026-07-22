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

final class GetPartInfo implements Tool
{
    use ResolvesPartsLink24Brand;

    public function __construct(
        private PartsLink24Client $client,
    ) {}

    public function description(): string
    {
        return 'Load part detail for a BOM position. partinfoPartno and pos come from list_bom_parts (not the full OE).';
    }

    public function handle(Request $request): string
    {
        $vin = (string) $request->string('vin');
        $mainGroupId = (string) $request->string('mainGroupId');
        $btnr = (string) $request->string('btnr');
        $partinfoPartno = (string) $request->string('partinfoPartno');
        $pos = (string) $request->string('pos');
        $brand = $this->brandForVin($vin);

        if (! $brand instanceof PartsLink24Brand) {
            return json_encode($this->unsupportedBrand(), JSON_THROW_ON_ERROR);
        }

        $info = $this->client->getPartInfo($brand, $vin, $mainGroupId, $btnr, $partinfoPartno, $pos);

        if ($info === null) {
            return json_encode(['ok' => false, 'error' => 'not_found'], JSON_THROW_ON_ERROR);
        }

        return json_encode(['ok' => true, ...$info], JSON_THROW_ON_ERROR);
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
            'partinfoPartno' => $schema->string()->required(),
            'pos' => $schema->string()->required(),
        ];
    }
}
