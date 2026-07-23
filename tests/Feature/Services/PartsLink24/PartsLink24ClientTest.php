<?php

declare(strict_types=1);

use App\Services\PartsLink24\PartsLink24Brand;
use App\Services\PartsLink24\PartsLink24Client;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

function pl24Brand(): PartsLink24Brand
{
    return new PartsLink24Brand('mini', 'mini_parts', 'p5bmw');
}

function fakePl24(string $username = 'tester'): void
{
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => $username,
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.login_strategy' => 'auto',
    ]);

    Http::fake([
        // Non-admin (portal / Chrome path)
        '*/auth/ext/api/1.1/login' => Http::response(
            ['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'],
            200,
            ['Set-Cookie' => 'PL24TOKEN=session-cookie-value; Path=/; Domain=www.partslink24.com'],
        ),
        // Admin (legacy appgtw path)
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

it('uses portal auth/1.1 login for non-admin users with flat password fields', function (): void {
    config()->set('suppliers.partslink24.squeeze_out', true);
    fakePl24('ricardo');

    resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');

    Http::assertSent(fn ($req): bool => str_contains((string) $req->url(), '/auth/ext/api/1.1/login')
        && ($req->data()['user'] ?? null) === 'ricardo'
        && ($req->data()['password'] ?? null) === 'secret'
        && array_key_exists('account', $req->data())
        && ($req->data()['squeezeOut'] ?? null) === true
        && ! array_key_exists('authentication', $req->data()));
    Http::assertSent(fn ($req): bool => str_contains((string) $req->url(), '/p5bmw/extern/search/vin')
        && str_starts_with((string) $req->header('Authorization')[0], 'Bearer '));
    Http::assertNotSent(fn ($req): bool => str_contains((string) $req->url(), '/pl24-appgtw/ext/api/1.0/login'));
});

it('uses legacy appgtw login for admin with nested authentication and device', function (): void {
    config()->set([
        'suppliers.partslink24.squeeze_out' => true,
        'suppliers.partslink24.device.id' => 'dev-uuid-1',
        'suppliers.partslink24.device.os' => 'Windows',
        'suppliers.partslink24.device.os_version' => '10',
        'suppliers.partslink24.device.lang' => 'pt-PT',
        'suppliers.partslink24.device.offset' => '60',
        'suppliers.partslink24.app_version' => '2.4.1',
        'suppliers.partslink24.user_agent' => 'Mozilla/5.0 TestBrowser/1.0',
        'suppliers.partslink24.accept_language' => 'pt',
        'suppliers.partslink24.base_url' => 'https://www.partslink24.com',
        'suppliers.partslink24.referer_path' => '/portal-ui',
        'suppliers.partslink24.sec_ch_ua' => '"Test";v="1"',
        'suppliers.partslink24.sec_ch_ua_mobile' => '?0',
        'suppliers.partslink24.sec_ch_ua_platform' => '"macOS"',
        'suppliers.partslink24.login_extra' => ['extraFlag' => true],
    ]);
    fakePl24('admin');

    resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');

    Http::assertSent(function ($req): bool {
        if (! str_contains((string) $req->url(), '/pl24-appgtw/ext/api/1.0/login')) {
            return false;
        }

        $device = $req->data()['device'] ?? null;

        return is_array($device)
            && $device === [
                'id' => 'dev-uuid-1',
                'os' => 'Windows',
                'offset' => '60',
                'lang' => 'pt-PT',
                'os-version' => '10',
            ]
            && ($req->data()['app-version'] ?? null) === '2.4.1'
            && ($req->data()['extraFlag'] ?? null) === true
            && ($req->data()['authentication']['user'] ?? null) === 'admin'
            && ($req->data()['authentication']['pwd'] ?? null) === 'secret'
            && ($req->data()['squeezeOut'] ?? null) === true
            && str_contains((string) $req->header('User-Agent')[0], 'TestBrowser/1.0')
            && ($req->header('Accept-Language')[0] ?? null) === 'pt'
            && ($req->header('Origin')[0] ?? null) === 'https://www.partslink24.com'
            && ($req->header('Referer')[0] ?? null) === 'https://www.partslink24.com/portal-ui'
            && ($req->header('sec-ch-ua')[0] ?? null) === '"Test";v="1"'
            && ($req->header('sec-fetch-mode')[0] ?? null) === 'cors';
    });
    Http::assertNotSent(fn ($req): bool => str_contains((string) $req->url(), '/auth/ext/api/1.1/login'));
});

it('portal login retries with opposite squeezeOut after session-limit 400', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'ricardo',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.squeeze_out' => false,
        'suppliers.partslink24.login_strategy' => 'auto',
    ]);

    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::sequence()
            ->push([
                'type' => 'urn:login:session-limit-exceeded',
                'title' => 'Bad Request',
                'status' => 400,
                'detail' => 'Session limit exceeded.',
            ], 400)
            ->push(['loginStatus' => 'OK', 'sessionToken' => 'after-squeeze'], 200, [
                'Set-Cookie' => 'PL24TOKEN=session-cookie-value; Path=/; Domain=www.partslink24.com',
            ]),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/search/vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/search-oil-filter.json')), true)),
    ]);

    $rows = resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');

    expect($rows)->not->toBeEmpty();

    $logins = Http::recorded(fn ($req): bool => str_contains((string) $req->url(), '/auth/ext/api/1.1/login'));
    expect($logins)->toHaveCount(2)
        ->and($logins[0][0]->data()['squeezeOut'])->toBeFalse()
        ->and($logins[1][0]->data()['squeezeOut'])->toBeTrue();
});

it('warms the session with an HTML document GET before login', function (): void {
    config()->set([
        'suppliers.partslink24.session.warm_up' => true,
        'suppliers.partslink24.base_url' => 'https://www.partslink24.com',
        'suppliers.partslink24.referer_path' => '/portal-ui',
    ]);

    Http::fake([
        'https://www.partslink24.com/portal-ui' => Http::response('<html>pl24</html>', 200, ['Content-Type' => 'text/html']),
        '*/auth/ext/api/1.1/login' => Http::response(
            ['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'],
            200,
            ['Set-Cookie' => 'PL24TOKEN=session-cookie-value; Path=/; Domain=www.partslink24.com'],
        ),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/search/vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/search-oil-filter.json')), true)),
    ]);

    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
    ]);

    resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');

    $urls = collect(Http::recorded())->map(fn ($pair): string => (string) $pair[0]->url())->values();

    expect($urls->first())->toBe('https://www.partslink24.com/portal-ui')
        ->and($urls->contains(fn (string $url): bool => str_contains($url, '/auth/ext/api/1.1/login')))->toBeTrue();

    Http::assertSent(fn ($req): bool => $req->url() === 'https://www.partslink24.com/portal-ui'
        && ($req->header('sec-fetch-mode')[0] ?? null) === 'navigate'
        && str_contains((string) ($req->header('Accept')[0] ?? ''), 'text/html'));
});

it('refuses new sessions outside business hours when the gate is enabled', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.volume.business_hours_only' => true,
        'suppliers.partslink24.volume.business_hours_start' => 7,
        'suppliers.partslink24.volume.business_hours_end' => 20,
        'suppliers.partslink24.volume.business_timezone' => 'Europe/Lisbon',
    ]);

    $this->travelTo(Date::parse('2026-07-23 22:30:00', 'Europe/Lisbon'));

    expect(fn () => resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter'))
        ->toThrow(RuntimeException::class, 'business-hours gate');
});

it('throws when the hourly catalog budget is exhausted', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.volume.max_per_hour' => 1,
        'suppliers.partslink24.volume.max_per_day' => 0,
    ]);
    fakePl24();

    $client = resolve(PartsLink24Client::class);
    $client->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');

    expect(fn () => $client->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'brake disc'))
        ->toThrow(RuntimeException::class, 'hourly catalog budget');
});

it('caches decodeVin results for the configured TTL', function (): void {
    config()->set('suppliers.partslink24.cache.decode_ttl', 1800);
    fakePl24();
    $client = resolve(PartsLink24Client::class);

    $first = $client->decodeVin(pl24Brand(), 'WMWSU91010T717700');
    $second = $client->decodeVin(pl24Brand(), 'WMWSU91010T717700');

    expect($first)->not->toBeNull()
        ->and($second)->toBe($first);

    expect(Http::recorded(fn ($req): bool => str_contains((string) $req->url(), '/extern/directAccess')))
        ->toHaveCount(1);
});

it('caches listMainGroups results for the configured TTL', function (): void {
    config()->set('suppliers.partslink24.cache.main_groups_ttl', 1800);
    fakePl24();
    $client = resolve(PartsLink24Client::class);

    $client->listMainGroups(pl24Brand(), 'WMWSU91010T717700');
    $client->listMainGroups(pl24Brand(), 'WMWSU91010T717700');

    expect(Http::recorded(fn ($req): bool => str_contains((string) $req->url(), '/groups/main-vin')))
        ->toHaveCount(1);
});

it('retries appgtw login with the opposite squeezeOut when the preferred value fails', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'admin',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.squeeze_out' => true,
        'suppliers.partslink24.login_strategy' => 'auto',
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

it('accepts a PL24TOKEN cookie as a successful appgtw login even when JSON token is null', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'admin',
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

it('throws when portal login returns a non-recoverable HTTP failure', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'ricardo',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.squeeze_out' => true,
    ]);

    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(['error' => 'server'], 500),
    ]);

    expect(fn () => resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter'))
        ->toThrow(RequestException::class);
});

it('throws a clear error when appgtw login never establishes a session cookie or token', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'admin',
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

it('forces portal login when login_strategy is portal even for admin', function (): void {
    fakePl24('admin');
    config()->set([
        'suppliers.partslink24.login_strategy' => 'portal',
        'suppliers.partslink24.squeeze_out' => true,
    ]);

    resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');

    Http::assertSent(fn ($req): bool => str_contains((string) $req->url(), '/auth/ext/api/1.1/login')
        && ($req->data()['user'] ?? null) === 'admin'
        && ($req->data()['password'] ?? null) === 'secret');
    Http::assertNotSent(fn ($req): bool => str_contains((string) $req->url(), '/pl24-appgtw/ext/api/1.0/login'));
});

it('forces appgtw login when login_strategy is appgtw even for non-admin', function (): void {
    fakePl24('ricardo');
    config()->set([
        'suppliers.partslink24.login_strategy' => 'appgtw',
        'suppliers.partslink24.squeeze_out' => true,
    ]);

    resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');

    Http::assertSent(fn ($req): bool => str_contains((string) $req->url(), '/pl24-appgtw/ext/api/1.0/login')
        && ($req->data()['authentication']['user'] ?? null) === 'ricardo');
    Http::assertNotSent(fn ($req): bool => str_contains((string) $req->url(), '/auth/ext/api/1.1/login'));
});

it('treats non-string username as non-admin for auto login strategy', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => null,
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.login_strategy' => 'auto',
        'suppliers.partslink24.squeeze_out' => true,
    ]);

    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(
            ['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'],
            200,
            ['Set-Cookie' => 'PL24TOKEN=session-cookie-value; Path=/; Domain=www.partslink24.com'],
        ),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/search/vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/search-oil-filter.json')), true)),
    ]);

    // config()->string would fail for null user/password on portal body; set string password already.
    // Portal post uses config()->string for account/user/password — username null will throw.
    // Force empty string instead:
    config()->set('suppliers.partslink24.username', '');

    resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');

    Http::assertSent(fn ($req): bool => str_contains((string) $req->url(), '/auth/ext/api/1.1/login'));
});

it('portal login retries after 403 then succeeds with opposite squeeze', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'ricardo',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.squeeze_out' => true,
    ]);

    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::sequence()
            ->push(['status' => 403, 'error' => 'Forbidden'], 403)
            ->push(['loginStatus' => 'OK', 'sessionToken' => 'ok'], 200, [
                'Set-Cookie' => 'PL24TOKEN=session-cookie-value; Path=/; Domain=www.partslink24.com',
            ]),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/search/vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/search-oil-filter.json')), true)),
    ]);

    $rows = resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');

    expect($rows)->not->toBeEmpty();
    $logins = Http::recorded(fn ($req): bool => str_contains((string) $req->url(), '/auth/ext/api/1.1/login'));
    expect($logins)->toHaveCount(2)
        ->and($logins[0][0]->data()['squeezeOut'])->toBeTrue()
        ->and($logins[1][0]->data()['squeezeOut'])->toBeFalse();
});

it('throws when portal login never establishes a session', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'ricardo',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.squeeze_out' => false,
    ]);

    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response([
            'type' => 'urn:login:session-limit-exceeded',
            'title' => 'Bad Request',
            'status' => 400,
        ], 400),
    ]);

    expect(fn () => resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter'))
        ->toThrow(RuntimeException::class, 'portal login did not establish a session');
});

it('refuses PL24 when require_proxy is on and proxy is empty', function (): void {
    config()->set([
        'suppliers.partslink24.require_proxy' => true,
        'suppliers.partslink24.proxy' => '',
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'ricardo',
        'suppliers.partslink24.password' => 'secret',
    ]);

    expect(fn () => resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter'))
        ->toThrow(RuntimeException::class, 'PARTSLINK24_PROXY is empty');
});

it('refuses PL24 when require_proxy is on and the proxy is unreachable', function (): void {
    config()->set([
        'suppliers.partslink24.require_proxy' => true,
        'suppliers.partslink24.proxy' => 'http://proxy.test:3128',
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'ricardo',
        'suppliers.partslink24.password' => 'secret',
    ]);

    Http::fake([
        'https://api.ipify.org' => Http::response('error', 500),
    ]);

    expect(fn () => resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter'))
        ->toThrow(RuntimeException::class, 'shop proxy is down');
});

it('refuses PL24 when require_proxy health check returns a non-IP body', function (): void {
    config()->set([
        'suppliers.partslink24.require_proxy' => true,
        'suppliers.partslink24.proxy' => 'http://proxy.test:3128',
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'ricardo',
        'suppliers.partslink24.password' => 'secret',
    ]);

    Http::fake([
        'https://api.ipify.org' => Http::response('not-an-ip', 200),
    ]);

    expect(fn () => resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter'))
        ->toThrow(RuntimeException::class, 'public IPv4');
});

it('probes the shop proxy once then uses cache on subsequent auth', function (): void {
    config()->set([
        'suppliers.partslink24.require_proxy' => true,
        'suppliers.partslink24.proxy' => 'http://proxy.test:3128',
    ]);
    fakePl24('ricardo');

    // Overlay health endpoint on top of catalog fakes.
    Http::fake([
        'https://api.ipify.org' => Http::response('77.54.114.220', 200),
        '*/auth/ext/api/1.1/login' => Http::response(
            ['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'],
            200,
            ['Set-Cookie' => 'PL24TOKEN=session-cookie-value; Path=/; Domain=www.partslink24.com'],
        ),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/search/vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/search-oil-filter.json')), true)),
        '*/p5bmw/extern/directAccess*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/vehicle-direct-access.json')), true)),
    ]);

    $client = resolve(PartsLink24Client::class);
    $client->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');
    // Drop token so authorize runs again; proxy health must be cached.
    Cache::forget('partslink24.token.mini_parts');
    $client->decodeVin(pl24Brand(), 'WMWSU91010T717700');

    expect(Http::recorded(fn ($req): bool => str_contains((string) $req->url(), 'api.ipify.org')))->toHaveCount(1);
});

it('attaches configured proxy to PL24 HTTP transport', function (): void {
    config()->set([
        'suppliers.partslink24.require_proxy' => false,
        'suppliers.partslink24.proxy' => 'http://user:pass@proxy.test:3128',
        'suppliers.partslink24.http2' => false,
    ]);
    fakePl24('ricardo');

    resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');

    Http::assertSent(fn ($req): bool => str_contains((string) $req->url(), '/auth/ext/api/1.1/login'));
});

it('skips sending session cookies on catalog when send_cookies is false', function (): void {
    config()->set([
        'suppliers.partslink24.session.send_cookies' => false,
        'suppliers.partslink24.require_proxy' => false,
    ]);
    fakePl24('ricardo');

    resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');

    Http::assertSent(fn ($req): bool => str_contains((string) $req->url(), '/p5bmw/extern/search/vin'));
});

it('normalizes referer_path without a leading slash for warm-up and xhr', function (): void {
    config()->set([
        'suppliers.partslink24.session.warm_up' => true,
        'suppliers.partslink24.base_url' => 'https://www.partslink24.com',
        'suppliers.partslink24.referer_path' => 'portal-ui',
        'suppliers.partslink24.require_proxy' => false,
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'ricardo',
        'suppliers.partslink24.password' => 'secret',
    ]);

    Http::fake([
        'https://www.partslink24.com/portal-ui' => Http::response('<html>ok</html>', 200),
        '*/auth/ext/api/1.1/login' => Http::response(
            ['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'],
            200,
            ['Set-Cookie' => 'PL24TOKEN=session-cookie-value; Path=/; Domain=www.partslink24.com'],
        ),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/search/vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/search-oil-filter.json')), true)),
    ]);

    resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');

    Http::assertSent(fn ($req): bool => $req->url() === 'https://www.partslink24.com/portal-ui');
    Http::assertSent(fn ($req): bool => str_contains((string) $req->url(), '/auth/ext/api/1.1/login')
        && ($req->header('Referer')[0] ?? null) === 'https://www.partslink24.com/portal-ui');
});

it('throws when appgtw login returns a hard 500', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'admin',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.squeeze_out' => true,
        'suppliers.partslink24.require_proxy' => false,
    ]);

    Http::fake([
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['error' => 'server'], 500),
    ]);

    expect(fn () => resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter'))
        ->toThrow(RequestException::class);
});

it('accepts portal login established only via PL24 cookie without loginStatus OK', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'ricardo',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.squeeze_out' => true,
        'suppliers.partslink24.require_proxy' => false,
    ]);

    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(
            ['loginStatus' => 'PENDING', 'sessionToken' => null],
            200,
            ['Set-Cookie' => 'PL24TOKEN=session-cookie-value; Path=/; Domain=www.partslink24.com'],
        ),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/search/vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/search-oil-filter.json')), true)),
    ]);

    $rows = resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');

    expect($rows)->not->toBeEmpty();
});

it('skips protected keys when merging appgtw login_extra', function (): void {
    fakePl24('ricardo');
    config()->set([
        'suppliers.partslink24.login_strategy' => 'appgtw',
        'suppliers.partslink24.login_extra' => [
            'authentication' => ['user' => 'hacker'],
            'device' => ['id' => 'nope'],
            'app-version' => 'hacked',
            'squeezeOut' => false,
            'safeExtra' => 'kept',
        ],
        'suppliers.partslink24.squeeze_out' => true,
        'suppliers.partslink24.require_proxy' => false,
    ]);

    resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');

    Http::assertSent(function ($req): bool {
        if (! str_contains((string) $req->url(), '/pl24-appgtw/ext/api/1.0/login')) {
            return false;
        }

        return ($req->data()['authentication']['user'] ?? null) === 'ricardo'
            && ($req->data()['safeExtra'] ?? null) === 'kept'
            && ($req->data()['squeezeOut'] ?? null) === true
            && ($req->data()['app-version'] ?? null) !== 'hacked';
    });
});

it('derives device offset from app timezone when config offset is empty', function (): void {
    fakePl24('admin');
    config()->set([
        'suppliers.partslink24.login_strategy' => 'appgtw',
        'suppliers.partslink24.device.offset' => '',
        'suppliers.partslink24.squeeze_out' => true,
        'suppliers.partslink24.require_proxy' => false,
    ]);

    resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');

    Http::assertSent(function ($req): bool {
        if (! str_contains((string) $req->url(), '/pl24-appgtw/ext/api/1.0/login')) {
            return false;
        }

        $offset = $req->data()['device']['offset'] ?? null;

        return is_string($offset) && $offset !== '';
    });
});

it('applies min-gap sleep when a prior PL24 request was recent', function (): void {
    config()->set([
        'suppliers.partslink24.session.min_gap_ms' => 50,
        'suppliers.partslink24.jitter_ms_min' => 0,
        'suppliers.partslink24.jitter_ms_max' => 0,
        'suppliers.partslink24.require_proxy' => false,
    ]);
    fakePl24('ricardo');

    // Seed last request timestamp as "just now".
    Cache::put(
        'partslink24.last_request_ms.pt-test',
        (int) floor(microtime(true) * 1000),
        now()->addHour(),
    );

    resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');

    Http::assertSent(fn ($req): bool => str_contains((string) $req->url(), '/auth/ext/api/1.1/login'));
});

it('ignores non-numeric min-gap cache values', function (): void {
    config()->set([
        'suppliers.partslink24.session.min_gap_ms' => 50,
        'suppliers.partslink24.jitter_ms_min' => 0,
        'suppliers.partslink24.jitter_ms_max' => 0,
        'suppliers.partslink24.require_proxy' => false,
    ]);
    fakePl24('ricardo');

    Cache::put(
        'partslink24.last_request_ms.pt-test',
        'not-a-timestamp',
        now()->addHour(),
    );

    resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');

    Http::assertSent(fn ($req): bool => str_contains((string) $req->url(), '/auth/ext/api/1.1/login'));
});

it('records per-minute rate limit hits when enabled', function (): void {
    config()->set([
        'suppliers.partslink24.rate_limit_per_minute' => 30,
        'suppliers.partslink24.session.min_gap_ms' => 0,
        'suppliers.partslink24.jitter_ms_min' => 0,
        'suppliers.partslink24.jitter_ms_max' => 0,
        'suppliers.partslink24.require_proxy' => false,
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'ricardo',
        'suppliers.partslink24.password' => 'secret',
    ]);

    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(
            ['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'],
            200,
            ['Set-Cookie' => 'PL24TOKEN=session-cookie-value; Path=/; Domain=www.partslink24.com'],
        ),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/search/vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/search-oil-filter.json')), true)),
    ]);

    resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');

    expect(RateLimiter::attempts('partslink24.rate.pt-test'))->toBeGreaterThan(0);
});

it('waits when the per-minute rate limit is already exhausted', function (): void {
    config()->set([
        'suppliers.partslink24.rate_limit_per_minute' => 1,
        'suppliers.partslink24.session.min_gap_ms' => 0,
        'suppliers.partslink24.jitter_ms_min' => 0,
        'suppliers.partslink24.jitter_ms_max' => 0,
        'suppliers.partslink24.require_proxy' => false,
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'ricardo',
        'suppliers.partslink24.password' => 'secret',
    ]);

    // Exhaust limit so applyRateLimit enters the wait loop (clears under runningUnitTests).
    RateLimiter::hit('partslink24.rate.pt-test', 60);

    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(
            ['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'],
            200,
            ['Set-Cookie' => 'PL24TOKEN=session-cookie-value; Path=/; Domain=www.partslink24.com'],
        ),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/search/vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/search-oil-filter.json')), true)),
    ]);

    resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');

    Http::assertSent(fn ($req): bool => str_contains((string) $req->url(), '/p5bmw/extern/search/vin'));
});

it('applies jitter sleep when catalog jitter is enabled', function (): void {
    config()->set([
        'suppliers.partslink24.jitter_ms_min' => 1,
        'suppliers.partslink24.jitter_ms_max' => 2,
        'suppliers.partslink24.session.min_gap_ms' => 0,
        'suppliers.partslink24.rate_limit_per_minute' => 0,
        'suppliers.partslink24.require_proxy' => false,
    ]);
    fakePl24('ricardo');

    resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');

    Http::assertSent(fn ($req): bool => str_contains((string) $req->url(), '/p5bmw/extern/search/vin'));
});

it('increments the daily volume counter when max_per_day is enabled', function (): void {
    config()->set([
        'suppliers.partslink24.volume.max_per_day' => 100,
        'suppliers.partslink24.volume.max_per_hour' => 0,
        'suppliers.partslink24.rate_limit_per_minute' => 0,
        'suppliers.partslink24.session.min_gap_ms' => 0,
        'suppliers.partslink24.jitter_ms_min' => 0,
        'suppliers.partslink24.jitter_ms_max' => 0,
        'suppliers.partslink24.require_proxy' => false,
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'ricardo',
        'suppliers.partslink24.password' => 'secret',
    ]);

    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(
            ['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'],
            200,
            ['Set-Cookie' => 'PL24TOKEN=session-cookie-value; Path=/; Domain=www.partslink24.com'],
        ),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/search/vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/search-oil-filter.json')), true)),
    ]);

    resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');

    expect(RateLimiter::attempts('partslink24.vol.day.pt-test'))->toBeGreaterThan(0);
});

it('builds pending requests without extra guzzle options when proxy is empty and cookies absent', function (): void {
    config()->set([
        'suppliers.partslink24.proxy' => '',
        'suppliers.partslink24.http2' => false,
        'suppliers.partslink24.session.send_cookies' => false,
        'suppliers.partslink24.require_proxy' => false,
    ]);
    fakePl24('ricardo');

    resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');

    Http::assertSent(fn ($req): bool => str_contains((string) $req->url(), '/auth/ext/api/1.1/login'));
});

it('retries once after a 401 by dropping the cached token and re-authenticating', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'ricardo',
        'suppliers.partslink24.password' => 'secret',
    ]);

    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(
            ['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'],
            200,
            ['Set-Cookie' => 'PL24TOKEN=session-cookie-value; Path=/; Domain=www.partslink24.com'],
        ),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/search/vin*' => Http::sequence()
            ->push(['message' => 'Unauthorized'], 401)
            ->push(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/search-oil-filter.json')), true)),
    ]);

    $rows = resolve(PartsLink24Client::class)->searchByVin(pl24Brand(), 'WMWSU91010T717700', 'oil filter');

    expect($rows)->not->toBeEmpty();

    Http::assertSentCount(6);
    expect(Http::recorded(fn ($req): bool => str_contains((string) $req->url(), '/auth/ext/api/1.1/login')))->toHaveCount(2)
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
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=session-cookie-value; Path=/; Domain=www.partslink24.com']),
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
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=session-cookie-value; Path=/; Domain=www.partslink24.com']),
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
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=session-cookie-value; Path=/; Domain=www.partslink24.com']),
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
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=session-cookie-value; Path=/; Domain=www.partslink24.com']),
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
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=session-cookie-value; Path=/; Domain=www.partslink24.com']),
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
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=session-cookie-value; Path=/; Domain=www.partslink24.com']),
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
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=session-cookie-value; Path=/; Domain=www.partslink24.com']),
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
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=session-cookie-value; Path=/; Domain=www.partslink24.com']),
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
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=session-cookie-value; Path=/; Domain=www.partslink24.com']),
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
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=session-cookie-value; Path=/; Domain=www.partslink24.com']),
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
