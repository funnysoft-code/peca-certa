<?php

declare(strict_types=1);

use App\Actions\RunIdentifyAgentTurn;
use App\Ai\Agents\IdentifyPartAgent;
use App\Ai\Tools\PartsLink24\Concerns\SoftFailsPartsLink24Http;
use App\Ai\Tools\PartsLink24\DecodeVin;
use App\Ai\Tools\PartsLink24\ListBomParts;
use App\Ai\Tools\PartsLink24\ListMainGroups;
use App\Ai\Tools\PartsLink24\ListSubGroups;
use App\Ai\Tools\PartsLink24\SearchPartsByVin;
use App\Enums\SearchRunStatus;
use App\Jobs\IdentifyAgentJob;
use App\Models\SearchRun;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

function softFailPl24Auth(): void
{
    Cache::flush();

    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
    ]);

    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=x; Path=/']),
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
    ]);
}

it('search_parts_by_vin soft-fails on HTTP 400 without throwing', function (): void {
    softFailPl24Auth();
    /** @var array{status: int, body: string} $errorFixture */
    $errorFixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/man-search-error.json')), true);

    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=x; Path=/']),
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5man/extern/search/vin*' => Http::response($errorFixture['body'], $errorFixture['status']),
    ]);

    $json = json_decode((string) resolve(SearchPartsByVin::class)->handle(new Request([
        'vin' => 'WMA06XZZ8HM753386',
        'query' => 'turbo',
    ])), true);

    expect($json['ok'])->toBeFalse()
        ->and($json['error'])->toBe('http_error')
        ->and($json['status'])->toBe(400)
        ->and($json['body'])->toBeString()->not->toBeEmpty();
});

it('maps authorize 403 RequestException to pl24_auth_error with status', function (): void {
    Cache::flush();
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.base_url' => 'https://www.partslink24.com',
    ]);

    Http::fake([
        'https://www.partslink24.com/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=x; Path=/']),
        'https://www.partslink24.com/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'status' => 'OK']),
        'https://www.partslink24.com/auth/ext/api/1.1/authorize' => Http::response(['error' => 'Forbidden'], 403),
    ]);

    $json = json_decode((string) resolve(SearchPartsByVin::class)->handle(new Request([
        'vin' => 'WMWSU91010T717700',
        'query' => 'cabin filter',
    ])), true);

    expect($json['ok'])->toBeFalse()
        ->and($json['error'])->toBe('pl24_auth_error')
        ->and($json['status'])->toBe(403)
        ->and($json['body'])->toContain('authentication');
});

it('maps generic throwable soft failures without auth markers', function (): void {
    $tool = new class
    {
        use SoftFailsPartsLink24Http;

        public function run(): string
        {
            return $this->withSoftHttp(function (): string {
                throw new RuntimeException('unexpected boom');
            });
        }
    };

    $json = json_decode($tool->run(), true);

    expect($json['ok'])->toBeFalse()
        ->and($json['error'])->toBe('http_error')
        ->and($json['status'])->toBeNull()
        ->and($json['body'])->toContain('unexpected boom');
});

it('maps login 403 to pl24_auth_error instead of generic http_error', function (): void {
    Cache::flush();
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'ricardo',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.base_url' => 'https://www.partslink24.com',
        'suppliers.partslink24.squeeze_out' => true,
    ]);

    // Non-admin uses portal login; both squeeze attempts return 403.
    Http::fake([
        'https://www.partslink24.com/auth/ext/api/1.1/login' => Http::response([
            'status' => 403,
            'error' => 'Forbidden',
            'path' => '/auth/ext/api/1.1/login',
        ], 403),
    ]);

    $json = json_decode((string) resolve(SearchPartsByVin::class)->handle(new Request([
        'vin' => 'WMWSU91010T717700',
        'query' => 'cabin filter',
    ])), true);

    expect($json['ok'])->toBeFalse()
        ->and($json['error'])->toBe('pl24_auth_error')
        ->and($json['status'])->toBe(403)
        ->and($json['body'])->toContain('authentication');
});

it('list_main_groups soft-fails on HTTP 500 without throwing', function (): void {
    softFailPl24Auth();
    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=x; Path=/']),
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5man/extern/groups/main-vin*' => Http::response(['demo' => false], 500),
    ]);

    $json = json_decode((string) resolve(ListMainGroups::class)->handle(new Request([
        'vin' => 'WMA06XZZ8HM753386',
    ])), true);

    expect($json['ok'])->toBeFalse()
        ->and($json['error'])->toBe('http_error')
        ->and($json['status'])->toBe(500);
});

it('list_sub_groups and list_bom_parts soft-fail on 5xx', function (): void {
    softFailPl24Auth();
    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=x; Path=/']),
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5man/extern/groups/func-vin*' => Http::response(['messages' => ['HTTP 404 Not Found']], 500),
        '*/p5man/extern/bom/vin*' => Http::response(['demo' => false], 500),
    ]);

    $sub = json_decode((string) resolve(ListSubGroups::class)->handle(new Request([
        'vin' => 'WMA06XZZ8HM753386',
        'mainGroupId' => '01',
    ])), true);
    $bom = json_decode((string) resolve(ListBomParts::class)->handle(new Request([
        'vin' => 'WMA06XZZ8HM753386',
        'mainGroupId' => '01',
        'btnr' => '01_1',
    ])), true);

    expect($sub['error'])->toBe('http_error')->and($sub['status'])->toBe(500)
        ->and($bom['error'])->toBe('http_error')->and($bom['status'])->toBe(500);
});

it('decode_vin works for MAN fixture path and returns brandKey', function (): void {
    softFailPl24Auth();
    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=x; Path=/']),
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5man/extern/directAccess*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/man-direct-access.json')), true)),
    ]);

    $json = json_decode((string) resolve(DecodeVin::class)->handle(new Request([
        'vin' => 'WMA06XZZ8HM753386',
    ])), true);

    expect($json['ok'])->toBeTrue()
        ->and($json['brandKey'])->toBe('man')
        ->and($json['resultStatus'])->toBe('VEHICLE_IDENTIFIED')
        ->and($json['description'])->toContain('TGX');
});

it('decode_vin family fallback tries sibling PSA catalog when primary fails', function (): void {
    softFailPl24Auth();
    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=x; Path=/']),
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5psa/extern/directAccess*' => Http::sequence()
            ->push(['data' => []], 200) // primary opel empty
            ->push(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/vehicle-direct-access.json')), true)),
    ]);

    // Force WMI to opel without relying on map quirks: use VXK
    $json = json_decode((string) resolve(DecodeVin::class)->handle(new Request([
        'vin' => 'VXKUBYHTKM4025404',
    ])), true);

    expect($json['ok'])->toBeTrue()
        ->and($json['fallbackFrom'] ?? null)->toBe('opel')
        ->and($json['brandKey'])->not->toBe('opel');
});

it('soft-fails non-HTTP exceptions as http_error without status', function (): void {
    Cache::flush();
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
    ]);
    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=x; Path=/']),
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'status' => 'OK']),
        // Incomplete authorize triggers RuntimeException inside the client (not RequestException).
        '*/auth/ext/api/1.1/authorize' => Http::response(['expires_in' => 600], 200),
    ]);

    $json = json_decode((string) resolve(ListMainGroups::class)->handle(new Request([
        'vin' => 'WMA06XZZ8HM753386',
    ])), true);

    expect($json['ok'])->toBeFalse()
        ->and($json['error'])->toBe('http_error')
        ->and($json['status'])->toBeNull()
        ->and($json['body'])->toContain('access_token');
});

it('decode_vin skips invalid family sibling catalog keys', function (): void {
    softFailPl24Auth();
    config([
        'suppliers.partslink24.brands.families' => [
            'psa' => ['opel', 'ghost_catalog'],
        ],
    ]);

    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=x; Path=/']),
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5psa/extern/directAccess*' => Http::response(['data' => []], 200),
    ]);

    $json = json_decode((string) resolve(DecodeVin::class)->handle(new Request([
        'vin' => 'VXKUBYHTKM4025404',
    ])), true);

    expect($json['ok'])->toBeFalse()->and($json['error'])->toBe('vin_not_identified');
});

it('soft http_error does not kill IdentifyAgentJob into empty failed', function (): void {
    Bus::fake();
    IdentifyPartAgent::fake([
        [
            'status' => 'needs_input',
            'oeParts' => [],
            'question' => 'O catálogo MAN não suporta pesquisa livre para este camião. Quer tentar outra descrição em inglês (turbocharger) ou cancelar?',
            'options' => ['Tentar turbocharger', 'Cancelar'],
            'confidence' => 0.3,
        ],
    ]);

    softFailPl24Auth();
    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=x; Path=/']),
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5man/extern/*' => Http::response(['demo' => false], 500),
    ]);

    $run = SearchRun::factory()->create([
        'request_text' => 'turbo',
        'vin' => 'WMA06XZZ8HM753386',
        'messages' => [],
        'status' => SearchRunStatus::Pending,
    ]);

    // Tool soft-fail path (no exception):
    $toolJson = json_decode((string) resolve(ListMainGroups::class)->handle(new Request([
        'vin' => 'WMA06XZZ8HM753386',
    ])), true);
    expect($toolJson['ok'])->toBeFalse()->and($toolJson['error'])->toBe('http_error');

    // Agent turn still completes with needs_input (not failed):
    new IdentifyAgentJob($run)->handle(resolve(RunIdentifyAgentTurn::class));
    expect($run->refresh()->status)->toBe(SearchRunStatus::NeedsInput)
        ->and($run->messages)->not->toBeEmpty();
});
