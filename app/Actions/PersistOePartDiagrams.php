<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\OePart;
use App\Models\SearchRun;
use App\Services\PartsLink24\PartsLink24Brand;
use App\Services\PartsLink24\PartsLink24Client;
use App\Services\PartsLink24\VinBrandResolver;
use App\Support\OePartDiagramUrl;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Fetch PL24 BOM illustrations for selected OEs and store content-addressed blobs.
 *
 * One blob per BOM page (vin + maingroup + btnr). Multiple OEs sharing a page reuse the path.
 * Null diagram when PL24 has no asset; hard-fail when PL24 advertises an asset but download/store fails.
 */
final readonly class PersistOePartDiagrams
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

        /** @var array<string, string|null> $pagePaths */
        $pagePaths = [];
        $disk = $this->disk();
        $enriched = [];

        foreach ($oeParts as $part) {
            $location = $this->locatePart($run, $brand, $vin, $part);

            if ($location === null) {
                $enriched[] = $part;

                continue;
            }

            $pageKey = $location['maingroup'].'|'.$location['btnr'];

            if (! array_key_exists($pageKey, $pagePaths)) {
                $pagePaths[$pageKey] = $this->storePageIllustration(
                    $brand,
                    $vin,
                    $location['maingroup'],
                    $location['btnr'],
                    $disk,
                );
            }

            $path = $pagePaths[$pageKey];
            $url = is_string($path) ? OePartDiagramUrl::for($run, $path) : null;

            $enriched[] = $part
                ->withCatalogMeta(
                    factoryFit: $location['factoryFit'],
                    pos: $location['pos'],
                    mainGroupId: $location['maingroup'],
                    btnr: $location['btnr'],
                    applicability: $location['applicability'],
                )
                ->withDiagram($path, $url);
        }

        return $enriched;
    }

    /**
     * @return array{
     *     maingroup: string,
     *     btnr: string,
     *     pos: string|null,
     *     factoryFit: bool|null,
     *     applicability: string|null
     * }|null
     */
    private function locatePart(
        SearchRun $run,
        PartsLink24Brand $brand,
        string $vin,
        OePart $part,
    ): ?array {
        if (is_string($part->mainGroupId) && $part->mainGroupId !== ''
            && is_string($part->btnr) && $part->btnr !== '') {
            $fromBom = $this->metaFromBom($brand, $vin, $part->mainGroupId, $part->btnr, $part->oeNumber);

            if ($fromBom !== null) {
                return $fromBom;
            }

            return [
                'maingroup' => $part->mainGroupId,
                'btnr' => $part->btnr,
                'pos' => $part->pos,
                'factoryFit' => $part->factoryFit,
                'applicability' => $part->applicability,
            ];
        }

        $fromTraces = $this->locationFromToolTraces($run, $part->oeNumber);

        if ($fromTraces !== null) {
            $fromBom = $this->metaFromBom(
                $brand,
                $vin,
                $fromTraces['maingroup'],
                $fromTraces['btnr'],
                $part->oeNumber,
            );

            return $fromBom ?? $fromTraces;
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
            if (! is_string($maingroup)) {
                continue;
            }

            if ($maingroup === '') {
                continue;
            }

            if (! is_string($btnr)) {
                continue;
            }

            if ($btnr === '') {
                continue;
            }

            $fromBom = $this->metaFromBom($brand, $vin, $maingroup, $btnr, $part->oeNumber);

            if ($fromBom !== null) {
                return $fromBom;
            }

            return [
                'maingroup' => $maingroup,
                'btnr' => $btnr,
                'pos' => null,
                'factoryFit' => null,
                'applicability' => null,
            ];
        }

        return null;
    }

    /**
     * @return array{
     *     maingroup: string,
     *     btnr: string,
     *     pos: string|null,
     *     factoryFit: bool|null,
     *     applicability: string|null
     * }|null
     */
    private function metaFromBom(
        PartsLink24Brand $brand,
        string $vin,
        string $maingroup,
        string $btnr,
        string $oeNumber,
    ): ?array {
        try {
            $parts = $this->client->listBomParts($brand, $vin, $maingroup, $btnr);
        } catch (Throwable) {
            return null;
        }

        foreach ($parts as $bomPart) {
            if ($bomPart['oe'] !== $oeNumber) {
                continue;
            }

            return [
                'maingroup' => $maingroup,
                'btnr' => $btnr,
                'pos' => $bomPart['pos'] !== '' ? $bomPart['pos'] : null,
                'factoryFit' => $bomPart['factoryFit'],
                'applicability' => $bomPart['applicability'],
            ];
        }

        return null;
    }

    /**
     * @return array{
     *     maingroup: string,
     *     btnr: string,
     *     pos: string|null,
     *     factoryFit: bool|null,
     *     applicability: string|null
     * }|null
     */
    private function locationFromToolTraces(SearchRun $run, string $oeNumber): ?array
    {
        /** @var list<array<string, mixed>> $traces */
        $traces = $run->tool_traces ?? [];

        foreach ($traces as $trace) {
            $resultRaw = $trace['result'] ?? null;
            if (! is_string($resultRaw)) {
                continue;
            }

            if ($resultRaw === '') {
                continue;
            }

            try {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode($resultRaw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            $results = $decoded['results'] ?? $decoded['parts'] ?? null;

            if (! is_array($results)) {
                continue;
            }

            foreach ($results as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $oe = $row['oe'] ?? $row['oeNumber'] ?? null;

                if ($oe !== $oeNumber) {
                    continue;
                }

                $maingroup = $row['maingroup'] ?? $row['mainGroupId'] ?? null;
                $btnr = $row['btnr'] ?? null;
                if (! is_string($maingroup)) {
                    continue;
                }

                if ($maingroup === '') {
                    continue;
                }

                if (! is_string($btnr)) {
                    continue;
                }

                if ($btnr === '') {
                    continue;
                }

                $factoryFit = $row['factoryFit'] ?? null;

                return [
                    'maingroup' => $maingroup,
                    'btnr' => $btnr,
                    'pos' => is_string($row['pos'] ?? null) ? $row['pos'] : null,
                    'factoryFit' => is_bool($factoryFit) ? $factoryFit : null,
                    'applicability' => is_string($row['applicability'] ?? null) ? $row['applicability'] : null,
                ];
            }
        }

        return null;
    }

    private function storePageIllustration(
        PartsLink24Brand $brand,
        string $vin,
        string $maingroup,
        string $btnr,
        Filesystem $disk,
    ): ?string {
        try {
            $bytes = $this->client->getBomIllustrationBytes($brand, $vin, $maingroup, $btnr);
        } catch (RuntimeException $e) {
            // Hard-fail only when PL24 advertised an asset we could not fetch.
            throw $e;
        } catch (Throwable) {
            return null;
        }

        if ($bytes === null) {
            return null;
        }

        $ext = $this->extensionFor($bytes);
        $hash = hash('sha256', $bytes);
        $path = 'diagrams/'.$hash.'.'.$ext;

        if (! $disk->exists($path)) {
            $written = $disk->put($path, $bytes);
            throw_if($written === false, RuntimeException::class, 'Failed to store PL24 BOM illustration at '.$path.'.');
        }

        return $path;
    }

    private function extensionFor(string $bytes): string
    {
        if (str_starts_with($bytes, "\x89PNG")) {
            return 'png';
        }

        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'jpg';
        }

        if (str_starts_with($bytes, 'GIF8')) {
            return 'gif';
        }

        if (str_starts_with(mb_ltrim($bytes), '<svg') || str_starts_with(mb_ltrim($bytes), '<?xml')) {
            return 'svg';
        }

        return 'bin';
    }

    private function disk(): Filesystem
    {
        $name = config()->string('suppliers.partslink24.diagrams_disk', 'local');

        return Storage::disk($name);
    }
}
