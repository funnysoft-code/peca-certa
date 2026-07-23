<?php

declare(strict_types=1);

namespace App\Actions;

use App\Services\PartsLink24\DynamicPartsLink24CatalogStore;
use InvalidArgumentException;

final readonly class RegisterPartsLink24Catalog
{
    public function __construct(
        private DynamicPartsLink24CatalogStore $store,
    ) {}

    /**
     * Register or update a brand catalog (and optional WMI → brand mappings) without a deploy.
     *
     * @param  list<string>  $wmis
     * @return array{key: string, service: string, group: string, wmis: list<string>}
     */
    public function execute(string $key, string $service, string $group, array $wmis = []): array
    {
        $key = mb_strtolower(mb_trim($key));
        $service = mb_trim($service);
        $group = mb_trim($group);

        throw_if($key === '', InvalidArgumentException::class, 'Catalog key is required.');
        throw_if($service === '', InvalidArgumentException::class, 'Catalog service is required.');
        throw_if($group === '', InvalidArgumentException::class, 'Catalog group is required.');

        $normalizedWmis = [];

        foreach ($wmis as $wmi) {
            $code = mb_strtoupper(mb_trim($wmi));

            if (mb_strlen($code) === 3) {
                $normalizedWmis[] = $code;
            }
        }

        $store = $this->store->read();
        $store['catalogs'][$key] = ['service' => $service, 'group' => $group];

        foreach ($normalizedWmis as $code) {
            $store['wmi'][$code] = $key;
        }

        $this->store->write($store);

        return [
            'key' => $key,
            'service' => $service,
            'group' => $group,
            'wmis' => $normalizedWmis,
        ];
    }
}
