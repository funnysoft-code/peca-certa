<?php

declare(strict_types=1);

namespace App\Services\PartsLink24;

use Illuminate\Support\Facades\File;

/**
 * Runtime catalog registry (service/group + optional WMI overrides) that does not
 * require a full config deploy. Static config remains the base; this file merges on top.
 *
 * Path: storage/app/private/partslink24/dynamic-catalogs.json
 *
 * @phpstan-type CatalogEntry array{service: string, group: string}
 * @phpstan-type StoreShape array{
 *     catalogs: array<string, CatalogEntry>,
 *     wmi: array<string, string>
 * }
 */
final readonly class DynamicPartsLink24CatalogStore
{
    public function path(): string
    {
        return storage_path('app/private/partslink24/dynamic-catalogs.json');
    }

    /**
     * @return StoreShape
     */
    public function read(): array
    {
        $path = $this->path();

        if (! File::exists($path)) {
            return ['catalogs' => [], 'wmi' => []];
        }

        /** @var mixed $decoded */
        $decoded = json_decode((string) File::get($path), true);

        if (! is_array($decoded)) {
            return ['catalogs' => [], 'wmi' => []];
        }

        $catalogsRaw = $decoded['catalogs'] ?? [];
        $wmiRaw = $decoded['wmi'] ?? [];
        $catalogs = [];
        $wmi = [];

        if (is_array($catalogsRaw)) {
            foreach ($catalogsRaw as $key => $entry) {
                if (! is_string($key)) {
                    continue;
                }

                if (! is_array($entry)) {
                    continue;
                }

                $service = $entry['service'] ?? null;
                $group = $entry['group'] ?? null;

                if (is_string($service) && $service !== '' && is_string($group) && $group !== '') {
                    $catalogs[$key] = ['service' => $service, 'group' => $group];
                }
            }
        }

        if (is_array($wmiRaw)) {
            foreach ($wmiRaw as $code => $brandKey) {
                if (is_string($code) && $code !== '' && is_string($brandKey) && $brandKey !== '') {
                    $wmi[mb_strtoupper($code)] = $brandKey;
                }
            }
        }

        return ['catalogs' => $catalogs, 'wmi' => $wmi];
    }

    /**
     * @param  StoreShape  $store
     */
    public function write(array $store): void
    {
        $path = $this->path();
        $directory = dirname($path);

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, recursive: true);
        }

        File::put(
            $path,
            json_encode($store, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );
    }
}
