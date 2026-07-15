<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\PartSearchResult;
use App\Data\PartVariant;
use App\Services\AutoDelta\AutoDeltaClient;

final readonly class SearchAutoDeltaParts
{
    public function __construct(
        private AutoDeltaClient $client,
    ) {}

    public function execute(string $reference): PartSearchResult
    {
        $searchUrl = $this->searchUrl($reference);
        $articles = $this->client->searchByNumber($reference);

        if ($articles === []) {
            return new PartSearchResult($reference, [], $searchUrl);
        }

        $prices = $this->client->getTradePrices($articles);
        $merged = PartVariant::merge($articles, $prices, $reference);

        return new PartSearchResult($reference, $merged->variants, $searchUrl);
    }

    private function searchUrl(string $reference): ?string
    {
        $webshopUrl = config()->string('suppliers.autodelta.webshop_url');

        if ($webshopUrl === '') {
            return null;
        }

        return $webshopUrl.'/parts/search?query='.rawurlencode($reference).'&exact=true';
    }
}
