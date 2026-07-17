<?php

declare(strict_types=1);

namespace App\Services\PartsLink24;

use App\Data\OePart;
use App\Services\PartsLink24\Contracts\PartsLink24Catalog;
use Illuminate\Support\Str;

final readonly class PartsLink24HttpClient implements PartsLink24Catalog
{
    public function __construct(
        private VinBrandResolver $resolver,
        private PartsLink24Client $client,
    ) {}

    /**
     * @param  list<string>  $keywords
     * @return list<OePart>
     */
    public function resolveOeParts(string $vin, string $category, array $keywords): array
    {
        if ($vin === '') {
            return [];
        }

        $brand = $this->resolver->resolve($vin);

        if (! $brand instanceof PartsLink24Brand) {
            return [];
        }

        $query = $category !== '' ? $category : ($keywords[0] ?? '');

        if ($query === '') {
            return [];
        }

        $rows = $this->client->searchByVin($brand, $vin, $query);

        /** @var array<string, OePart> $parts */
        $parts = [];

        foreach ($rows as $row) {
            $oe = $row['oe'];

            if (isset($parts[$oe])) {
                continue;
            }

            $parts[$oe] = new OePart($oe, $this->cleanName($row['name']), 'OE');
        }

        $max = config()->integer('suppliers.partslink24.max_candidates');

        return array_slice(array_values($parts), 0, $max);
    }

    /**
     * Catalog names carry match markup: square brackets around matched terms
     * and `\-` escapes (e.g. "Set [oil]\-[filter] element" => "Set oil-filter element").
     */
    private function cleanName(string $name): string
    {
        $name = str_replace(['[', ']'], '', $name);
        $name = str_replace('\\-', '-', $name);

        return Str::squish($name);
    }
}
