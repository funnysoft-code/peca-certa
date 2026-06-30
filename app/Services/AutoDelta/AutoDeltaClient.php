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
     * Authenticate against AuthWS and return a usable token.
     *
     * LIVE-INTEGRATION TODO (see plan "Known API contract"): AuthWS is a
     * JSON-RPC endpoint — a raw {"username","password"} body returns
     * {"status":400,"statusText":"Unknown Call: username"}. The request below is
     * a placeholder. The webshop makes TWO AuthWS calls: call 1 (no x-api-key)
     * returns {apiKey, expiresOn}; call 2 (WITH x-api-key) returns the session
     * context including catalogUserId. Capture both method names + bodies via a
     * proxy on a real login, then implement the two-call flow here. Until then
     * this method fails loud (throws) rather than sending a bad token.
     */
    private function login(): AutoDeltaToken
    {
        $response = Http::asJson()
            ->post(config()->string('suppliers.autodelta.auth_url'), [
                'username' => config()->string('suppliers.autodelta.username'),
                'password' => config()->string('suppliers.autodelta.password'),
            ])
            ->throw()
            ->json();

        throw_unless(is_array($response), RuntimeException::class, 'Unexpected Auto Delta auth response.');

        $apiKey = $response['apiKey'] ?? null;
        $catalogUserId = $response['catalogUserId'] ?? null;
        $expiresOn = $response['expiresOn'] ?? null;

        throw_if(! is_string($apiKey) || ! is_string($catalogUserId) || ! is_string($expiresOn), RuntimeException::class, 'Incomplete Auto Delta auth response.');

        return new AutoDeltaToken(
            apiKey: $apiKey,
            catalogUserId: $catalogUserId,
            expiresOn: Date::parse($expiresOn),
        );
    }
}
