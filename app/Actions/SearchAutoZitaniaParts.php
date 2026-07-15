<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\PartSearchResult;
use App\Data\PartVariant;
use App\Services\AutoZitania\AutoZitaniaClient;

final readonly class SearchAutoZitaniaParts
{
    public function __construct(
        private AutoZitaniaClient $client,
    ) {}

    /**
     * Zitania's DVSE catalog exposes retail (P.V.P.) prices and binary
     * availability only — no purchase price, no quantities.
     */
    public function execute(string $reference): PartSearchResult
    {
        $variants = array_map(
            /** @param array<mixed, mixed> $row */
            fn (array $row): PartVariant => new PartVariant(
                brandName: $this->toString($row['brandName'] ?? null),
                articleNumber: $this->toString($row['articleNumber'] ?? null),
                traderArticleNumber: $this->toString($row['traderArticleNumber'] ?? null),
                purchasePrice: null,
                retailPrice: $this->toNullableFloat($row['retailPrice'] ?? null),
                currency: 'EUR',
                availableQuantity: 0,
                inStock: ($row['inStock'] ?? false) === true,
                warehouse: '',
            ),
            $this->client->searchByNumber($reference),
        );

        // The DVSE catalog has no shareable per-query URL; the portal root is
        // the only link that lands a logged-in operator on the catalog rather
        // than forcing a re-login (see config comment).
        $portalUrl = config()->string('suppliers.autozitania.portal_url');

        return new PartSearchResult(
            $reference,
            $variants,
            $portalUrl === '' ? null : $portalUrl,
        );
    }

    private function toString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function toNullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
