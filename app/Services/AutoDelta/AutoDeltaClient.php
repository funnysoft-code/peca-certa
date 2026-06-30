<?php

declare(strict_types=1);

namespace App\Services\AutoDelta;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class AutoDeltaClient
{
    private const string CACHE_KEY = 'autodelta.token';

    public function token(): AutoDeltaToken
    {
        $cached = Cache::get(self::CACHE_KEY);

        if ($cached instanceof AutoDeltaToken && $cached->isValid()) {
            return $cached;
        }

        $token = $this->login();

        Cache::put(self::CACHE_KEY, $token, $token->expiresOn);

        return $token;
    }

    /**
     * @return list<array{dataSupplierId: int, mfrId: int, brandName: string, articleNumber: string}>
     */
    public function searchByNumber(string $reference): array
    {
        $response = $this->call(config()->string('suppliers.autodelta.search_url'), [
            'getArticles' => [
                'applyDqmRules' => true,
                'articleCountry' => config()->string('suppliers.autodelta.country'),
                'provider' => config()->integer('suppliers.autodelta.provider'),
                'lang' => config()->string('suppliers.autodelta.lang'),
                'searchQuery' => $reference,
                'searchMatchType' => 'exact',
                'searchType' => 10,
                'page' => 1,
                'perPage' => 200,
                'sort' => [
                    ['field' => 'mfrName', 'direction' => 'asc'],
                    ['field' => 'linkageSortNum', 'direction' => 'asc'],
                ],
            ],
        ]);

        $articles = $response['articles'] ?? [];

        if (! is_array($articles)) {
            return [];
        }

        $results = [];

        foreach ($articles as $article) {
            if (! is_array($article)) {
                continue;
            }

            $results[] = [
                'dataSupplierId' => $this->toInt($article['dataSupplierId'] ?? null),
                'mfrId' => $this->toInt($article['mfrId'] ?? null),
                'brandName' => $this->toString($article['mfrName'] ?? null),
                'articleNumber' => $this->toString($article['articleNumber'] ?? null),
            ];
        }

        return $results;
    }

    /**
     * @param  list<array{dataSupplierId: int, articleNumber: string, mfrId?: int, brandName?: string}>  $articles
     * @return list<array{dataSupplierId: int, articleNumber: string, traderArticleNumber: string, priceTypeKey: string, price: float, currencyCode: string, availableQuantity: int, stockStatusDescription: string, stockMatchCode: string}>
     */
    public function getTradePrices(array $articles): array
    {
        $payload = array_map(fn (array $a): array => [
            'dataSupplierId' => $a['dataSupplierId'],
            'articleNumber' => $a['articleNumber'],
            'quantity' => 1,
        ], $articles);

        $response = $this->call(config()->string('suppliers.autodelta.catalog_url'), [
            'getTradePrices' => [
                'lang' => config()->string('suppliers.autodelta.lang'),
                'countryCode' => config()->string('suppliers.autodelta.country'),
                'articles' => $payload,
            ],
        ]);

        $data = $response['data'] ?? null;
        $rows = is_array($data) ? ($data['array'] ?? []) : [];

        if (! is_array($rows)) {
            return [];
        }

        $list = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $list[] = [
                'dataSupplierId' => $this->toInt($row['dataSupplierId'] ?? null),
                'articleNumber' => $this->toString($row['articleNumber'] ?? null),
                'traderArticleNumber' => $this->toString($row['traderArticleNumber'] ?? null),
                'priceTypeKey' => $this->toString($row['priceTypeKey'] ?? null),
                'price' => $this->toFloat($row['price'] ?? null),
                'currencyCode' => $this->toString($row['currencyCode'] ?? null),
                'availableQuantity' => $this->toInt($row['availableQuantity'] ?? null),
                'stockStatusDescription' => $this->toString($row['stockStatusDescription'] ?? null),
                'stockMatchCode' => $this->toString($row['stockMatchCode'] ?? null),
            ];
        }

        return $list;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<array-key, mixed>
     */
    private function call(string $url, array $body): array
    {
        $token = $this->token();

        $response = Http::asJson()
            ->withHeaders([
                'x-api-key' => $token->apiKey,
                'x-catalog' => config()->string('suppliers.autodelta.catalog_id'),
                'x-catalog-user' => $token->catalogUserId,
            ])
            ->post($url, $body)
            ->throw()
            ->json();

        return is_array($response) ? $response : [];
    }

    private function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function toString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * Authenticate against AuthWS (JSON-RPC) and return a usable token.
     *
     * `getAPIKeyForUser` takes the catalog KEY (e.g. "autodelta", not the
     * catalog id) plus credentials and returns a 24h dynamic `apiKey`. The
     * `catalogUserId` is a stable per-account identifier: it is read from the
     * response when present, otherwise from config (`catalog_user_id`).
     */
    private function login(): AutoDeltaToken
    {
        $response = Http::asJson()
            ->post(config()->string('suppliers.autodelta.auth_url'), [
                'getAPIKeyForUser' => [
                    'catalog' => config()->string('suppliers.autodelta.catalog_key'),
                    'username' => config()->string('suppliers.autodelta.username'),
                    'password' => config()->string('suppliers.autodelta.password'),
                ],
            ])
            ->throw()
            ->json();

        throw_unless(is_array($response), RuntimeException::class, 'Unexpected Auto Delta auth response.');

        $apiKey = $response['apiKey'] ?? null;
        $expiresOn = $response['expiresOn'] ?? null;

        throw_if(! is_string($apiKey) || ! is_string($expiresOn), RuntimeException::class, 'Incomplete Auto Delta auth response (apiKey/expiresOn missing).');

        $catalogUserId = $response['catalogUserId'] ?? null;
        if (! is_string($catalogUserId) || $catalogUserId === '') {
            $catalogUserId = config()->string('suppliers.autodelta.catalog_user_id');
        }

        throw_if($catalogUserId === '', RuntimeException::class, 'Missing Auto Delta catalog_user_id — set AUTODELTA_CATALOG_USER_ID.');

        return new AutoDeltaToken(
            apiKey: $apiKey,
            catalogUserId: $catalogUserId,
            expiresOn: Date::parse($expiresOn),
        );
    }
}
