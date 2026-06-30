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
        $articles = $this->client->searchByNumber($reference);

        if ($articles === []) {
            return new PartSearchResult($reference, []);
        }

        $prices = $this->client->getTradePrices($articles);

        return PartVariant::merge($articles, $prices, $reference);
    }
}
