<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\OePart;
use App\Models\SearchRun;
use App\Services\PartsLink24\PartsLink24Brand;
use App\Services\PartsLink24\PartsLink24Client;
use App\Services\PartsLink24\VinBrandResolver;
use JsonException;
use Throwable;

/**
 * Attach factoryFit / pos / BOM location to agent-selected OEs using PL24 BOM (or tool traces).
 *
 * Prefer factory-fit OEs: when the agent selected only greyed option OEs for a position
 * that has a factory row, and the request does not clearly name an option pack, replace
 * with the factory OE(s) for that position.
 */
final readonly class EnrichOePartsFromBom
{
    public function __construct(
        private PartsLink24Client $client,
        private VinBrandResolver $vinBrandResolver,
    ) {}

    /**
     * @param  list<OePart>  $oeParts
     * @return list<OePart>
     */
    public function execute(SearchRun $run, array $oeParts): array
    {
        if ($oeParts === []) {
            return [];
        }

        $vin = (string) $run->vin;
        $brand = $this->vinBrandResolver->resolve($vin, $run->brand_override);

        if (! $brand instanceof PartsLink24Brand) {
            return $oeParts;
        }

        $requestText = mb_strtolower((string) $run->request_text);
        $operatorWantsOption = $this->mentionsOptionPack($requestText);

        /** @var array<string, list<array<string, mixed>>> $bomByPage */
        $bomByPage = [];
        $enriched = [];

        foreach ($oeParts as $part) {
            $location = $this->resolveLocation($run, $brand, $vin, $part);

            if ($location === null) {
                $enriched[] = $part;

                continue;
            }

            $pageKey = $location['maingroup'].'|'.$location['btnr'];

            if (! isset($bomByPage[$pageKey])) {
                try {
                    $bomByPage[$pageKey] = $this->client->listBomParts(
                        $brand,
                        $vin,
                        $location['maingroup'],
                        $location['btnr'],
                    );
                } catch (Throwable) {
                    $bomByPage[$pageKey] = [];
                }
            }

            $match = $this->findBomRow($bomByPage[$pageKey], $part->oeNumber);

            if ($match === null) {
                $enriched[] = $part->withCatalogMeta(
                    mainGroupId: $location['maingroup'],
                    btnr: $location['btnr'],
                );

                continue;
            }

            if (! $match['factoryFit'] && ! $operatorWantsOption) {
                $factoryRows = array_values(array_filter(
                    $bomByPage[$pageKey],
                    fn (array $row): bool => $row['factoryFit'] === true
                        && $row['pos'] === $match['pos'],
                ));

                if ($factoryRows !== []) {
                    foreach ($factoryRows as $factoryRow) {
                        $oe = $factoryRow['oe'] ?? null;
                        $description = $factoryRow['description'] ?? null;
                        $pos = $factoryRow['pos'] ?? null;
                        $applicability = $factoryRow['applicability'] ?? null;

                        // listBomParts always yields string oe/description; PHPStan still wants guards.
                        $rowIsValid = is_string($oe) && $oe !== '' && is_string($description);
                        if (! $rowIsValid) {
                            continue; // @codeCoverageIgnore
                        }

                        $enriched[] = new OePart(
                            oeNumber: $oe,
                            description: $description,
                            brand: 'OE',
                            factoryFit: true,
                            pos: is_string($pos) && $pos !== '' ? $pos : null,
                            mainGroupId: $location['maingroup'],
                            btnr: $location['btnr'],
                            applicability: is_string($applicability) ? $applicability : null,
                        );
                    }

                    continue;
                }
            }

            $factoryFit = $match['factoryFit'] ?? null;
            $pos = $match['pos'] ?? null;
            $applicability = $match['applicability'] ?? null;

            $enriched[] = $part->withCatalogMeta(
                factoryFit: is_bool($factoryFit) ? $factoryFit : null,
                pos: is_string($pos) && $pos !== '' ? $pos : null,
                mainGroupId: $location['maingroup'],
                btnr: $location['btnr'],
                applicability: is_string($applicability) ? $applicability : null,
            );
        }

        return $this->uniqueByOe($enriched);
    }

    /**
     * @return array{maingroup: string, btnr: string}|null
     */
    private function resolveLocation(
        SearchRun $run,
        PartsLink24Brand $brand,
        string $vin,
        OePart $part,
    ): ?array {
        if (is_string($part->mainGroupId) && $part->mainGroupId !== ''
            && is_string($part->btnr) && $part->btnr !== '') {
            return ['maingroup' => $part->mainGroupId, 'btnr' => $part->btnr];
        }

        /** @var list<array<string, mixed>> $traces */
        $traces = $run->tool_traces ?? [];

        foreach ($traces as $trace) {
            $resultRaw = $trace['result'] ?? null;

            if (! is_string($resultRaw)) {
                continue;
            }

            try {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode($resultRaw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            foreach (['results', 'parts'] as $key) {
                $rows = $decoded[$key] ?? null;

                if (! is_array($rows)) {
                    continue;
                }

                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $oe = $row['oe'] ?? $row['oeNumber'] ?? null;

                    if ($oe !== $part->oeNumber) {
                        continue;
                    }

                    $maingroup = $row['maingroup'] ?? $row['mainGroupId'] ?? null;
                    $btnr = $row['btnr'] ?? null;

                    if (is_string($maingroup) && $maingroup !== '' && is_string($btnr) && $btnr !== '') {
                        return ['maingroup' => $maingroup, 'btnr' => $btnr];
                    }
                }
            }
        }

        try {
            $rows = $this->client->searchByVin($brand, $vin, $part->oeNumber);
        } catch (Throwable) {
            return null;
        }

        foreach ($rows as $row) {
            if ($row['oe'] !== $part->oeNumber) {
                continue;
            }

            $maingroup = $row['maingroup'] ?? null;
            $btnr = $row['btnr'] ?? null;

            if (is_string($maingroup) && $maingroup !== '' && is_string($btnr) && $btnr !== '') {
                return ['maingroup' => $maingroup, 'btnr' => $btnr];
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function findBomRow(array $rows, string $oeNumber): ?array
    {
        foreach ($rows as $row) {
            if (($row['oe'] ?? null) === $oeNumber) {
                return $row;
            }
        }

        return null;
    }

    private function mentionsOptionPack(string $requestText): bool
    {
        return (bool) preg_match(
            '/\b(gp|gp2|jcw\s*gp|chrome\s*line|bayswater|baker\s*street|steptronic|op[cç][aã]o|option)\b/u',
            $requestText,
        );
    }

    /**
     * @param  list<OePart>  $parts
     * @return list<OePart>
     */
    private function uniqueByOe(array $parts): array
    {
        /** @var array<string, OePart> $byOe */
        $byOe = [];

        foreach ($parts as $part) {
            $byOe[$part->oeNumber] = $part;
        }

        return array_values($byOe);
    }
}
