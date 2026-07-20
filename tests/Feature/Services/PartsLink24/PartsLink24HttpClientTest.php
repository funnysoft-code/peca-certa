<?php

declare(strict_types=1);

use App\Services\PartsLink24\Contracts\PartsLink24Catalog;
use App\Services\PartsLink24\PartsLink24HttpClient;
use Illuminate\Support\Facades\Http;

function fakePl24Full(): void
{
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
    ]);

    Http::fake([
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/search/vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/search-oil-filter.json')), true)),
    ]);
}

it('resolves a VIN + term to deduped, cleaned OE parts', function (): void {
    fakePl24Full();

    $parts = resolve(PartsLink24HttpClient::class)->resolveOeParts('WMWSU91010T717700', 'oil filter', ['oil']);

    // 4 raw rows, one duplicate collapsed => 3 distinct OE numbers.
    expect($parts)->toHaveCount(3)
        ->and($parts[0]->oeNumber)->toBe('11427557011')
        ->and($parts[0]->description)->toBe('Oil filter cover')
        ->and($parts[1]->oeNumber)->toBe('11427622446')
        ->and($parts[1]->description)->toBe('Set oil-filter element')
        ->and($parts[1]->brand)->toBe('OE');
});

it('returns no parts for an empty VIN without any HTTP', function (): void {
    Http::fake();
    expect(resolve(PartsLink24HttpClient::class)->resolveOeParts('', 'oil filter', []))->toBe([]);
    Http::assertNothingSent();
});

it('returns no parts for an unknown brand VIN without any HTTP', function (): void {
    Http::fake();
    expect(resolve(PartsLink24HttpClient::class)->resolveOeParts('ZZZ99999999999999', 'oil filter', []))->toBe([]);
    Http::assertNothingSent();
});

it('returns no parts for an empty query without any HTTP', function (): void {
    Http::fake();
    expect(resolve(PartsLink24HttpClient::class)->resolveOeParts('WMWSU91010T717700', '', []))->toBe([]);
    Http::assertNothingSent();
});

it('is the bound implementation after Task 7', function (): void {
    // Sanity: the contract resolves to the real client.
    expect(resolve(PartsLink24Catalog::class))->toBeInstanceOf(PartsLink24HttpClient::class);
});

it('caps candidates at the configured maximum', function (): void {
    config()->set('suppliers.partslink24.max_candidates', 1);
    fakePl24Full();

    expect(resolve(PartsLink24HttpClient::class)->resolveOeParts('WMWSU91010T717700', 'oil filter', []))->toHaveCount(1);
});
