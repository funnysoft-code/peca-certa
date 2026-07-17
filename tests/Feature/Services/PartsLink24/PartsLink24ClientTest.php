<?php

declare(strict_types=1);

use App\Services\PartsLink24\PartsLink24Brand;
use App\Services\PartsLink24\PartsLink24Client;
use Illuminate\Support\Facades\Http;

function fakePl24(): void
{
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
    ]);

    Http::fake([
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'refreshToken' => 'r', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/search/vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/search-oil-filter.json')), true)),
    ]);
}

it('logs in, authorizes, and returns raw search rows for a VIN', function (): void {
    fakePl24();
    $brand = new PartsLink24Brand('mini', 'mini_parts', 'p5bmw');

    $rows = resolve(PartsLink24Client::class)->searchByVin($brand, 'WMWSU91010T717700', 'oil filter');

    expect($rows)->toHaveCount(4)
        ->and($rows[0])->toBe(['oe' => '11427557011', 'name' => '[Oil] [filter] cover']);
});

it('sends squeezeOut true on login and Bearer auth on search', function (): void {
    fakePl24();
    $brand = new PartsLink24Brand('mini', 'mini_parts', 'p5bmw');

    resolve(PartsLink24Client::class)->searchByVin($brand, 'WMWSU91010T717700', 'oil filter');

    Http::assertSent(fn ($req): bool => str_contains((string) $req->url(), '/pl24-appgtw/ext/api/1.0/login')
        && $req->data()['squeezeOut'] === true);
    Http::assertSent(fn ($req): bool => str_contains((string) $req->url(), '/p5bmw/extern/search/vin')
        && str_starts_with((string) $req->header('Authorization')[0], 'Bearer '));
});

it('caches the authorize token across calls', function (): void {
    fakePl24();
    $brand = new PartsLink24Brand('mini', 'mini_parts', 'p5bmw');
    $client = resolve(PartsLink24Client::class);

    $client->searchByVin($brand, 'WMWSU91010T717700', 'oil filter');
    $client->searchByVin($brand, 'WMWSU91010T717700', 'brake disc');

    Http::assertSentCount(4); // login+authorize once, then 2 searches (token reused on 2nd)
});

it('retries once after a 401 by dropping the cached token and re-authenticating', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
    ]);

    Http::fake([
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'refreshToken' => 'r', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/search/vin*' => Http::sequence()
            ->push(['message' => 'Unauthorized'], 401)
            ->push(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/search-oil-filter.json')), true)),
    ]);

    $brand = new PartsLink24Brand('mini', 'mini_parts', 'p5bmw');

    $rows = resolve(PartsLink24Client::class)->searchByVin($brand, 'WMWSU91010T717700', 'oil filter');

    expect($rows)->toHaveCount(4)
        ->and($rows[0])->toBe(['oe' => '11427557011', 'name' => '[Oil] [filter] cover']);

    // login+authorize happen twice (initial + retry after the 401), plus 2 search calls.
    Http::assertSentCount(6);
    expect(Http::recorded(fn ($req): bool => str_contains((string) $req->url(), '/pl24-appgtw/ext/api/1.0/login')))->toHaveCount(2)
        ->and(Http::recorded(fn ($req): bool => str_contains((string) $req->url(), '/auth/ext/api/1.1/authorize')))->toHaveCount(2)
        ->and(Http::recorded(fn ($req): bool => str_contains((string) $req->url(), '/p5bmw/extern/search/vin')))->toHaveCount(2);
});
