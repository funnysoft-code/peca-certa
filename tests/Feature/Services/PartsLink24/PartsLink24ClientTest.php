<?php

declare(strict_types=1);

use App\Services\PartsLink24\PartsLink24Brand;
use App\Services\PartsLink24\PartsLink24Client;
use Illuminate\Http\Client\RequestException;
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
    config()->set('suppliers.partslink24.squeeze_out', true);
    fakePl24();

    resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');

    Http::assertSent(fn ($req): bool => str_contains((string) $req->url(), '/pl24-appgtw/ext/api/1.0/login')
        && $req->data()['squeezeOut'] === true);
    Http::assertSent(fn ($req): bool => str_contains((string) $req->url(), '/p5bmw/extern/search/vin')
        && str_starts_with((string) $req->header('Authorization')[0], 'Bearer '));
});

it('retries login with the opposite squeezeOut when the preferred value fails', function (): void {
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

it('accepts a PL24TOKEN cookie as a successful login even when JSON token is null', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.squeeze_out' => true,
    ]);

    Http::fake([
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(
            ['securables' => null, 'status' => null, 'token' => null, 'refreshToken' => 'r'],
            200,
            ['Set-Cookie' => 'PL24TOKEN=session-cookie-value; Path=/; Domain=www.partslink24.com'],
        ),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/search/vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/search-oil-filter.json')), true)),
    ]);

    $rows = resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');

    expect($rows)->not->toBeEmpty();
});

it('throws when login returns a non-403 HTTP failure', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.squeeze_out' => true,
    ]);

    Http::fake([
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['error' => 'server'], 500),
    ]);

    expect(fn () => resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter'))
        ->toThrow(RequestException::class);
});

it('throws a clear error when login never establishes a session cookie or token', function (): void {
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
        ->and($parts[0])->toHaveKeys(['oe', 'partno', 'description', 'pos', 'qty', 'partinfoPartno', 'factoryFit', 'unavailable', 'remark', 'applicability', 'maingroup', 'btnr'])
        ->and($parts[0]['oe'])->toMatch('/^\d+$/')
        ->and($parts[0]['factoryFit'])->toBeTrue()
        ->and($parts[0]['unavailable'])->toBeFalse();
});

it('marks package-only BOM rows as non-factory (greyed) and keeps the factory OE', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
    ]);

    Http::fake([
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'refreshToken' => 'r', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/bom/vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/bom-vin-gearshift-unavailable.json')), true)),
    ]);

    $page = resolve(PartsLink24Client::class)->listBomPage(pl24Brand(), 'WMWSU91010T717700', '25', '25_0444');
    $parts = $page['parts'];

    expect($parts)->toHaveCount(2)
        ->and($parts[0]['oe'])->toBe('25117605282')
        ->and($parts[0]['factoryFit'])->toBeTrue()
        ->and($parts[0]['unavailable'])->toBeFalse()
        ->and($parts[0]['remark'])->toBe('SILBER')
        ->and($parts[0]['applicability'])->toContain('Automatic transmission')
        ->and($parts[1]['oe'])->toBe('25117638583')
        ->and($parts[1]['factoryFit'])->toBeFalse()
        ->and($parts[1]['unavailable'])->toBeTrue()
        ->and($parts[1]['remark'])->toBe('GP2')
        ->and($parts[1]['applicability'])->toContain('John Cooper Works GP')
        ->and($page['illustrationAvailable'])->toBeTrue()
        ->and($page['images'])->not->toBeEmpty();

    $bytes = resolve(PartsLink24Client::class)->getBomIllustrationBytes(pl24Brand(), 'WMWSU91010T717700', '25', '25_0444');
    expect($bytes)->toBeString()->not->toBeEmpty()
        ->and(str_starts_with((string) $bytes, "\x89PNG"))->toBeTrue();
});

it('downloads illustration from a url field on the image descriptor', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.base_url' => 'https://www.partslink24.com',
    ]);

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);

    Http::fake([
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'refreshToken' => 'r', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/bom/vin*' => Http::response([
            'data' => [
                'records' => [],
                'images' => [
                    ['id' => '_DFLT_', 'url' => 'https://www.partslink24.com/pl24-res/diagram.png'],
                ],
            ],
        ]),
        'https://www.partslink24.com/pl24-res/diagram.png' => Http::response($png, 200, ['Content-Type' => 'image/png']),
    ]);

    $bytes = resolve(PartsLink24Client::class)->getBomIllustrationBytes(pl24Brand(), 'WMWSU91010T717700', '25', '25_0444');

    expect($bytes)->toBe($png);
});

it('downloads illustration bytes from images/vin when content-type is image', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
    ]);

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);

    Http::fake([
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'refreshToken' => 'r', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/bom/vin*' => Http::response([
            'data' => [
                'records' => [],
                'images' => [['id' => '_DFLT_', 'name' => '25_0444']],
            ],
        ]),
        '*/p5bmw/extern/images/vin*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
    ]);

    expect(resolve(PartsLink24Client::class)->getBomIllustrationBytes(pl24Brand(), 'WMWSU91010T717700', '25', '25_0444'))
        ->toBe($png);
});

it('accepts raw svg illustration text that is not base64', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
    ]);

    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>';

    Http::fake([
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'refreshToken' => 'r', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/bom/vin*' => Http::response([
            'data' => [
                'records' => [],
                'images' => [
                    ['id' => '_DFLT_', 'data' => '', 'content' => $svg],
                ],
            ],
        ]),
    ]);

    expect(resolve(PartsLink24Client::class)->getBomIllustrationBytes(pl24Brand(), 'WMWSU91010T717700', '25', '25_0444'))
        ->toBe($svg);
});

it('decodes data:image base64 illustration payloads', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
    ]);

    $b64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    Http::fake([
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'refreshToken' => 'r', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/bom/vin*' => Http::response([
            'data' => [
                'records' => [],
                'images' => [
                    ['id' => '_DFLT_', 'data' => 'data:image/png;base64,'.$b64],
                ],
            ],
        ]),
    ]);

    $bytes = resolve(PartsLink24Client::class)->getBomIllustrationBytes(pl24Brand(), 'WMWSU91010T717700', '25', '25_0444');

    expect($bytes)->toBe(base64_decode($b64, true));
});

it('retries and hard-fails when illustration resolve returns empty body', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.illustration_retries' => 2,
    ]);

    Http::fake([
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'refreshToken' => 'r', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/bom/vin*' => Http::response([
            'data' => [
                'records' => [],
                'images' => [
                    ['id' => '_DFLT_', 'name' => '25_0444', 'url' => '', 'src' => null],
                    ['id' => ''],
                    ['name' => 'no-id'],
                ],
            ],
        ]),
        '*/p5bmw/extern/images/vin*' => Http::response('', 200, ['Content-Type' => 'application/json']),
    ]);

    expect(fn () => resolve(PartsLink24Client::class)->getBomIllustrationBytes(pl24Brand(), 'WMWSU91010T717700', '25', '25_0444'))
        ->toThrow(RuntimeException::class, 'empty_body');
});

it('accepts illustration json envelope with base64 from images/vin', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
    ]);

    $b64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    Http::fake([
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'refreshToken' => 'r', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/bom/vin*' => Http::response([
            'data' => [
                'records' => [],
                'images' => [['id' => '_DFLT_', 'name' => '25_0444']],
            ],
        ]),
        '*/p5bmw/extern/images/vin*' => Http::response(['base64' => $b64], 200, ['Content-Type' => 'application/json']),
    ]);

    expect(resolve(PartsLink24Client::class)->getBomIllustrationBytes(pl24Brand(), 'WMWSU91010T717700', '25', '25_0444'))
        ->toBe(base64_decode($b64, true));
});

it('retries illustration download after a 401 on the absolute image url', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.base_url' => 'https://www.partslink24.com',
    ]);

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);

    Http::fake([
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'refreshToken' => 'r', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/bom/vin*' => Http::response([
            'data' => [
                'records' => [],
                'images' => [
                    ['id' => '_DFLT_', 'url' => 'https://www.partslink24.com/pl24-res/diagram.png'],
                ],
            ],
        ]),
        'https://www.partslink24.com/pl24-res/diagram.png' => Http::sequence()
            ->push('unauthorized', 401)
            ->push($png, 200, ['Content-Type' => 'image/png']),
    ]);

    expect(resolve(PartsLink24Client::class)->getBomIllustrationBytes(pl24Brand(), 'WMWSU91010T717700', '25', '25_0444'))
        ->toBe($png);
});

it('returns null when absolute illustration url fails permanently', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.base_url' => 'https://www.partslink24.com',
        'suppliers.partslink24.illustration_retries' => 1,
    ]);

    Http::fake([
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'refreshToken' => 'r', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/bom/vin*' => Http::response([
            'data' => [
                'records' => [],
                'images' => [
                    ['id' => '_DFLT_', 'url' => 'https://www.partslink24.com/pl24-res/missing.png'],
                ],
            ],
        ]),
        'https://www.partslink24.com/pl24-res/missing.png' => Http::response('nope', 404),
        '*/p5bmw/extern/images/vin*' => Http::response('', 200),
    ]);

    expect(fn () => resolve(PartsLink24Client::class)->getBomIllustrationBytes(pl24Brand(), 'WMWSU91010T717700', '25', '25_0444'))
        ->toThrow(RuntimeException::class);
});

it('returns null illustration when PL24 has no BOM images', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
    ]);

    Http::fake([
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'refreshToken' => 'r', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/bom/vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/bom-vin-no-images.json')), true)),
    ]);

    expect(resolve(PartsLink24Client::class)->getBomIllustrationBytes(pl24Brand(), 'WMWSU91010T717700', '11', '11_4574'))
        ->toBeNull();
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
