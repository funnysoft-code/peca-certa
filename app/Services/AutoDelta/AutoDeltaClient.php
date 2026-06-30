<?php

declare(strict_types=1);

namespace App\Services\AutoDelta;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

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
     * @return list<array<string, mixed>>
     */
    public function searchByNumber(string $reference): array
    {
        $response = $this->call((string) config('suppliers.autodelta.search_url'), [
            'getArticles' => [
                'applyDqmRules' => true,
                'articleCountry' => (string) config('suppliers.autodelta.country'),
                'provider' => (int) config('suppliers.autodelta.provider'),
                'lang' => (string) config('suppliers.autodelta.lang'),
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

        /** @var array<int, array{dataSupplierId:int, mfrId:int, mfrName:string, articleNumber:string}> $articles */
        $articles = $response['articles'] ?? [];

        $results = collect($articles)
            ->map(fn (array $a): array => [
                'dataSupplierId' => $a['dataSupplierId'],
                'mfrId' => $a['mfrId'],
                'brandName' => $a['mfrName'],
                'articleNumber' => $a['articleNumber'],
            ])
            ->all();

        /** @var list<array<string, mixed>> $list */
        $list = array_values($results);

        return $list;
    }

    /**
     * @param  list<array{dataSupplierId:int, articleNumber:string}>  $articles
     * @return list<array<string, mixed>>
     */
    public function getTradePrices(array $articles): array
    {
        $payload = array_map(fn (array $a): array => [
            'dataSupplierId' => $a['dataSupplierId'],
            'articleNumber' => $a['articleNumber'],
            'quantity' => 1,
        ], $articles);

        $response = $this->call((string) config('suppliers.autodelta.catalog_url'), [
            'getTradePrices' => [
                'lang' => (string) config('suppliers.autodelta.lang'),
                'countryCode' => (string) config('suppliers.autodelta.country'),
                'articles' => $payload,
            ],
        ]);

        /** @var array<int, array<string, mixed>> $data */
        $data = $response['data']['array'] ?? [];

        /** @var list<array<string, mixed>> $list */
        $list = array_values($data);

        return $list;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function call(string $url, array $body): array
    {
        $token = $this->token();

        /** @var array<string, mixed> $response */
        $response = Http::asJson()
            ->withHeaders([
                'x-api-key' => $token->apiKey,
                'x-catalog' => (string) config('suppliers.autodelta.catalog_id'),
                'x-catalog-user' => $token->catalogUserId,
            ])
            ->post($url, $body)
            ->throw()
            ->json();

        return $response;
    }

    private function login(): AutoDeltaToken
    {
        /** @var array{apiKey:string, catalogUserId:string, expiresOn:string} $response */
        $response = Http::asJson()
            ->post((string) config('suppliers.autodelta.auth_url'), [
                'username' => (string) config('suppliers.autodelta.username'),
                'password' => (string) config('suppliers.autodelta.password'),
            ])
            ->throw()
            ->json();

        return new AutoDeltaToken(
            apiKey: $response['apiKey'],
            catalogUserId: $response['catalogUserId'],
            expiresOn: Date::parse($response['expiresOn']),
        );
    }
}
