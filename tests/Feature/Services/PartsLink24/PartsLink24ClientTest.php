<?php

declare(strict_types=1);

use App\Services\PartsLink24\PartsLink24Brand;
use App\Services\PartsLink24\PartsLink24Client;
use Illuminate\Support\Facades\Http;

function pl24Brand(): PartsLink24Brand
{
    return new PartsLink24Brand('mini', 'mini_parts', 'p5bmw');
}

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
        '*/p5bmw/extern/directAccess*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/vehicle-direct-access.json')), true)),
        '*/p5bmw/extern/groups/main-vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/main-groups-vin.json')), true)),
        '*/p5bmw/extern/groups/func-vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/func-groups-vin-hg11.json')), true)),
        '*/p5bmw/extern/bom/vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/bom-vin-114574.json')), true)),
        '*/p5bmw/extern/partinfo/vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/partinfo-vin.json')), true)),
    ]);
}

it('logs in, authorizes, and returns search rows with OE and catalog location', function (): void {
    fakePl24();

    $rows = resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');

    expect($rows)->not->toBeEmpty()
        ->and($rows[0]['oe'])->toBeString()->not->toBeEmpty()
        ->and($rows[0]['name'])->toBeString()->not->toBeEmpty()
        ->and($rows[0])->toHaveKeys(['oe', 'name', 'partno', 'maingroup', 'subgroup', 'btnr']);
});

it('sends configured squeezeOut on login and Bearer auth on search', function (): void {
    config()->set('suppliers.partslink24.squeeze_out', false);
    fakePl24();

    resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');

    Http::assertSent(fn ($req): bool => str_contains((string) $req->url(), '/pl24-appgtw/ext/api/1.0/login')
        && $req->data()['squeezeOut'] === false);
    Http::assertSent(fn ($req): bool => str_contains((string) $req->url(), '/p5bmw/extern/search/vin')
        && str_starts_with((string) $req->header('Authorization')[0], 'Bearer '));
});

it('retries login with squeezeOut false when squeezeOut true is rejected with 403', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.squeeze_out' => true,
    ]);

    Http::fake([
        '*/pl24-appgtw/ext/api/1.0/login' => Http::sequence()
            ->push(['status' => 403, 'error' => 'Forbidden'], 403)
            ->push(['token' => 'sess', 'refreshToken' => 'r', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/search/vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/search-oil-filter.json')), true)),
    ]);

    $rows = resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');

    expect($rows)->not->toBeEmpty();

    $logins = Http::recorded(fn ($req): bool => str_contains((string) $req->url(), '/pl24-appgtw/ext/api/1.0/login'));
    expect($logins)->toHaveCount(2)
        ->and($logins[0][0]->data()['squeezeOut'])->toBeTrue()
        ->and($logins[1][0]->data()['squeezeOut'])->toBeFalse();
});

it('throws a clear error when login is 200 but returns USER_ALREADY_LOGGED_IN without a token', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.squeeze_out' => false,
    ]);

    Http::fake([
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response([
            'securables' => null,
            'status' => 'USER_ALREADY_LOGGED_IN',
            'title' => null,
            'refreshToken' => null,
            'token' => null,
        ], 200),
    ]);

    expect(fn () => resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter'))
        ->toThrow(RuntimeException::class, 'USER_ALREADY_LOGGED_IN');
});

it('caches the authorize token across calls', function (): void {
    fakePl24();
    $client = resolve(PartsLink24Client::class);

    $client->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');
    $client->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'brake disc');

    Http::assertSentCount(4); // login+authorize once, then 2 searches
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

    $rows = resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');

    expect($rows)->not->toBeEmpty();

    Http::assertSentCount(6);
    expect(Http::recorded(fn ($req): bool => str_contains((string) $req->url(), '/pl24-appgtw/ext/api/1.0/login')))->toHaveCount(2)
        ->and(Http::recorded(fn ($req): bool => str_contains((string) $req->url(), '/auth/ext/api/1.1/authorize')))->toHaveCount(2)
        ->and(Http::recorded(fn ($req): bool => str_contains((string) $req->url(), '/p5bmw/extern/search/vin')))->toHaveCount(2);
});

it('decodes a VIN into vehicle description and basic fields', function (): void {
    fakePl24();

    $vehicle = resolve(PartsLink24Client::class)->decodeVin(pl24Brand(), 'WMWSU91010T717700');

    expect($vehicle)->not->toBeNull()
        ->and($vehicle['vin'])->toBe('WMWSU91010T717700')
        ->and($vehicle['resultStatus'])->toBe('VEHICLE_IDENTIFIED')
        ->and($vehicle['description'])->toContain('MINI')
        ->and($vehicle['fields'])->not->toBeEmpty()
        ->and($vehicle['fields'][0])->toHaveKeys(['description', 'value']);
});

it('lists main catalog groups for a VIN', function (): void {
    fakePl24();

    $groups = resolve(PartsLink24Client::class)->listMainGroups(pl24Brand(), 'WMWSU91010T717700');

    expect($groups)->not->toBeEmpty()
        ->and($groups[0])->toHaveKeys(['id', 'description']);
});

it('lists sub groups under a main group including BOM pages', function (): void {
    fakePl24();

    $groups = resolve(PartsLink24Client::class)->listSubGroups(pl24Brand(), 'WMWSU91010T717700', '11');

    expect($groups)->not->toBeEmpty();

    $bom = collect($groups)->first(fn (array $g): bool => $g['kind'] === 'bom');
    $section = collect($groups)->first(fn (array $g): bool => $g['kind'] === 'section');

    expect($bom)->not->toBeNull()
        ->and($bom['btnr'])->not->toBeNull()
        ->and($section)->not->toBeNull();
});

it('lists BOM parts with OE numbers and partinfo short partno', function (): void {
    fakePl24();

    $parts = resolve(PartsLink24Client::class)->listBomParts(pl24Brand(), 'WMWSU91010T717700', '11', '11_4574');

    expect($parts)->not->toBeEmpty()
        ->and($parts[0])->toHaveKeys(['oe', 'partno', 'description', 'pos', 'qty', 'partinfoPartno'])
        ->and($parts[0]['oe'])->toMatch('/^\d+$/');
});

it('loads part info for a BOM position', function (): void {
    fakePl24();

    $info = resolve(PartsLink24Client::class)->getPartInfo(
        pl24Brand(),
        'WMWSU91010T717700',
        '11',
        '11_4574',
        '8643745',
        '1',
    );

    expect($info)->not->toBeNull()
        ->and($info)->toHaveKeys(['oe', 'partno', 'description', 'fields']);
});
