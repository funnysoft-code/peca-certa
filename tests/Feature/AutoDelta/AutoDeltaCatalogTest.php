<?php

declare(strict_types=1);

use App\Services\AutoDelta\AutoDeltaClient;
use App\Services\AutoDelta\AutoDeltaToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('suppliers.autodelta.catalog_url', 'https://cat.test/WebCat30WS');
    config()->set('suppliers.autodelta.search_url', 'https://cat.test/Tecdoc');
    config()->set('suppliers.autodelta.catalog_id', 'CAT123');
    config()->set('suppliers.autodelta.provider', 1066);
    Cache::put('autodelta.token', new AutoDeltaToken('KEY', 'USER', now()->addDay()), now()->addDay());
});

it('fetches trade prices and sends the auth headers', function (): void {
    $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/AutoDelta/trade-prices.json')), true);

    Http::fake(['cat.test/*' => Http::response($fixture['response'])]);

    $rows = resolve(AutoDeltaClient::class)->getTradePrices([
        ['dataSupplierId' => 156, 'articleNumber' => 'FO-398S'],
    ]);

    expect($rows)->not->toBeEmpty()
        ->and($rows[0])->toHaveKeys(['dataSupplierId', 'articleNumber', 'priceTypeKey', 'price']);

    Http::assertSent(fn ($req): bool => $req->hasHeader('x-api-key', 'KEY')
        && $req->hasHeader('x-catalog', 'CAT123')
        && $req->hasHeader('x-catalog-user', 'USER'));
});

it('searches by number and returns brand variants', function (): void {
    $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/AutoDelta/search-by-number.json')), true);

    Http::fake(['cat.test/*' => Http::response($fixture['response'])]);

    $articles = resolve(AutoDeltaClient::class)->searchByNumber('OC90');

    expect($articles)->not->toBeEmpty()
        ->and($articles[0])->toHaveKeys(['dataSupplierId', 'mfrId', 'brandName', 'articleNumber']);
});

it('returns no variants when the search payload is malformed', function (): void {
    Http::fake(['cat.test/*' => Http::response(['articles' => 'not-an-array', 'status' => 200])]);

    expect(resolve(AutoDeltaClient::class)->searchByNumber('OC90'))->toBe([]);
});

it('skips non-array article entries', function (): void {
    Http::fake(['cat.test/*' => Http::response([
        'articles' => [
            'garbage',
            ['dataSupplierId' => 156, 'mfrId' => 2194, 'mfrName' => 'JAPANPARTS', 'articleNumber' => 'FO-398S'],
        ],
        'status' => 200,
    ])]);

    $articles = resolve(AutoDeltaClient::class)->searchByNumber('OC90');

    expect($articles)->toHaveCount(1)
        ->and($articles[0]['brandName'])->toBe('JAPANPARTS');
});

it('returns no prices when the trade-price payload is malformed', function (): void {
    Http::fake(['cat.test/*' => Http::response(['data' => ['array' => 'not-an-array'], 'status' => 200])]);

    expect(resolve(AutoDeltaClient::class)->getTradePrices([
        ['dataSupplierId' => 156, 'articleNumber' => 'FO-398S'],
    ]))->toBe([]);
});

it('skips non-array price rows', function (): void {
    Http::fake(['cat.test/*' => Http::response([
        'data' => ['array' => [
            'garbage',
            ['dataSupplierId' => 156, 'articleNumber' => 'FO-398S', 'priceTypeKey' => 'E', 'price' => 1.7],
        ]],
        'status' => 200,
    ])]);

    $rows = resolve(AutoDeltaClient::class)->getTradePrices([
        ['dataSupplierId' => 156, 'articleNumber' => 'FO-398S'],
    ]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['priceTypeKey'])->toBe('E');
});
