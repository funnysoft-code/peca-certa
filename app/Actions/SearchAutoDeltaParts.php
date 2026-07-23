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

    /**
     * @param  bool  $includeUnavailable  When false (default), only in-stock
     *                                    variants are returned. OOS + unpriced
     *                                    TecDoc cross-refs are omitted so the
     *                                    first paint stays small and fast.
     */
    public function execute(string $reference, bool $includeUnavailable = false): PartSearchResult
    {
        $searchUrl = $this->searchUrl($reference);
        $articles = $this->client->searchByNumber($reference);

        if ($articles === []) {
            return new PartSearchResult($reference, [], $searchUrl);
        }

        $prices = $this->client->getTradePrices($articles);
        $merged = PartVariant::merge($articles, $prices, $reference);
        $result = new PartSearchResult($reference, $merged->variants, $searchUrl);

        return $includeUnavailable ? $result : $result->onlyInStock();
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
