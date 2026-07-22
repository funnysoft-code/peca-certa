<?php

declare(strict_types=1);

use App\Ai\Tools\PartsLink24\DecodeVin;
use App\Ai\Tools\PartsLink24\ListBomParts;
use App\Ai\Tools\PartsLink24\ListMainGroups;
use App\Ai\Tools\PartsLink24\ListSubGroups;
use App\Ai\Tools\PartsLink24\ResolveBrand;
use App\Ai\Tools\PartsLink24\SearchPartsByVin;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

function fakePl24Tools(): void
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
        '*/p5bmw/extern/directAccess*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/vehicle-direct-access.json')), true)),
        '*/p5bmw/extern/groups/main-vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/main-groups-vin.json')), true)),
        '*/p5bmw/extern/groups/func-vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/func-groups-vin-hg11.json')), true)),
        '*/p5bmw/extern/bom/vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/bom-vin-114574.json')), true)),
    ]);
}

it('resolve_brand returns catalog for Mini WMI', function (): void {
    $json = json_decode((string) resolve(ResolveBrand::class)->handle(new Request(['vin' => 'WMWSU91010T717700'])), true);

    expect($json['ok'])->toBeTrue()
        ->and($json['brandKey'])->toBe('mini')
        ->and($json['service'])->toBe('mini_parts');
});

it('resolve_brand fails for unknown WMI without HTTP', function (): void {
    Http::fake();
    $json = json_decode((string) resolve(ResolveBrand::class)->handle(new Request(['vin' => 'ZZZ99999999999999'])), true);

    expect($json['ok'])->toBeFalse()->and($json['error'])->toBe('unsupported_brand');
    Http::assertNothingSent();
});

it('search_parts_by_vin returns fixture rows', function (): void {
    fakePl24Tools();
    $json = json_decode((string) resolve(SearchPartsByVin::class)->handle(new Request([
        'vin' => 'WMWSU91010T717700',
        'query' => 'oil filter',
    ])), true);

    expect($json['ok'])->toBeTrue()->and($json['results'])->not->toBeEmpty();
});

it('decode_vin returns vehicle identity', function (): void {
    fakePl24Tools();
    $json = json_decode((string) resolve(DecodeVin::class)->handle(new Request([
        'vin' => 'WMWSU91010T717700',
    ])), true);

    expect($json['ok'])->toBeTrue()
        ->and($json['resultStatus'])->toBe('VEHICLE_IDENTIFIED');
});

it('list_main_groups and list_sub_groups and list_bom_parts chain', function (): void {
    fakePl24Tools();

    $main = json_decode((string) resolve(ListMainGroups::class)->handle(new Request([
        'vin' => 'WMWSU91010T717700',
    ])), true);
    $sub = json_decode((string) resolve(ListSubGroups::class)->handle(new Request([
        'vin' => 'WMWSU91010T717700',
        'mainGroupId' => '11',
    ])), true);
    $bom = json_decode((string) resolve(ListBomParts::class)->handle(new Request([
        'vin' => 'WMWSU91010T717700',
        'mainGroupId' => '11',
        'btnr' => '11_4574',
    ])), true);

    expect($main['ok'])->toBeTrue()
        ->and($sub['ok'])->toBeTrue()
        ->and($bom['ok'])->toBeTrue()
        ->and($bom['parts'])->not->toBeEmpty();
});
