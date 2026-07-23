<?php

declare(strict_types=1);

use App\Actions\EnrichOePartsFromBom;
use App\Data\OePart;
use App\Enums\SearchRunKind;
use App\Enums\SearchRunStatus;
use App\Models\SearchRun;
use App\Models\User;
use Illuminate\Support\Facades\Http;

function fakeGearshiftBom(): void
{
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
    ]);

    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=x; Path=/']),
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'refreshToken' => 'r', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/bom/vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/bom-vin-gearshift-unavailable.json')), true)),
        '*/p5bmw/extern/search/vin*' => Http::response([
            'data' => [
                'records' => [
                    [
                        'recordContext' => ['bidata_part_no' => '25117638583'],
                        'values' => [
                            'name' => 'Gearshift knob',
                            'partno' => '25 11 7 638 583',
                            'hg' => '25',
                            'fg' => '10',
                            'btnr' => '25_0444',
                        ],
                    ],
                    [
                        'recordContext' => ['bidata_part_no' => '25117605282'],
                        'values' => [
                            'name' => 'Leath. gearlever knob',
                            'partno' => '25 11 7 605 282',
                            'hg' => '25',
                            'fg' => '10',
                            'btnr' => '25_0444',
                        ],
                    ],
                ],
            ],
        ]),
    ]);
}

it('replaces greyed GP option with factory OE when request does not name GP', function (): void {
    fakeGearshiftBom();

    $run = SearchRun::query()->create([
        'user_id' => User::factory()->create()->id,
        'kind' => SearchRunKind::Identify,
        'request_text' => 'punho de mudanças',
        'vin' => 'WMWSU91010T717700',
        'status' => SearchRunStatus::Running,
        'tool_traces' => [
            [
                'tool' => 'search_parts_by_vin',
                'result' => json_encode([
                    'ok' => true,
                    'results' => [
                        [
                            'oe' => '25117638583',
                            'name' => 'Gearshift knob',
                            'maingroup' => '25',
                            'btnr' => '25_0444',
                        ],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        ],
    ]);

    $parts = resolve(EnrichOePartsFromBom::class)->execute($run, [
        new OePart('25117638583', 'Gearshift knob, leather/6-speed', 'OE'),
    ]);

    expect($parts)->toHaveCount(1)
        ->and($parts[0]->oeNumber)->toBe('25117605282')
        ->and($parts[0]->factoryFit)->toBeTrue()
        ->and($parts[0]->pos)->toBe('01')
        ->and($parts[0]->mainGroupId)->toBe('25')
        ->and($parts[0]->btnr)->toBe('25_0444');
});

it('ignores malformed tool traces while resolving locations', function (): void {
    fakeGearshiftBom();

    $run = SearchRun::query()->create([
        'user_id' => User::factory()->create()->id,
        'kind' => SearchRunKind::Identify,
        'request_text' => 'punho',
        'vin' => 'WMWSU91010T717700',
        'status' => SearchRunStatus::Running,
        'tool_traces' => [
            ['tool' => 'x', 'result' => 123],
            ['tool' => 'x', 'result' => ''],
            ['tool' => 'x', 'result' => '{not-json'],
            ['tool' => 'x', 'result' => json_encode(['results' => 'nope'], JSON_THROW_ON_ERROR)],
            ['tool' => 'x', 'result' => json_encode(['results' => [null, 'x', ['oe' => 'nope']]], JSON_THROW_ON_ERROR)],
            ['tool' => 'x', 'result' => json_encode([
                'parts' => [
                    ['oe' => '25117605282', 'maingroup' => '', 'btnr' => '25_0444'],
                    ['oe' => '25117605282', 'maingroup' => '25', 'btnr' => ''],
                    ['oe' => '25117605282', 'maingroup' => '25', 'btnr' => '25_0444'],
                ],
            ], JSON_THROW_ON_ERROR)],
        ],
    ]);

    $parts = resolve(EnrichOePartsFromBom::class)->execute($run, [
        new OePart('25117605282', 'Factory', 'OE'),
    ]);

    expect($parts[0]->factoryFit)->toBeTrue()
        ->and($parts[0]->mainGroupId)->toBe('25');
});

it('resolves location via searchByVin when traces lack maingroup', function (): void {
    fakeGearshiftBom();

    $run = SearchRun::query()->create([
        'user_id' => User::factory()->create()->id,
        'kind' => SearchRunKind::Identify,
        'request_text' => 'punho',
        'vin' => 'WMWSU91010T717700',
        'status' => SearchRunStatus::Running,
        'tool_traces' => [],
    ]);

    $parts = resolve(EnrichOePartsFromBom::class)->execute($run, [
        new OePart('25117605282', 'Factory', 'OE'),
    ]);

    expect($parts[0]->factoryFit)->toBeTrue()
        ->and($parts[0]->oeNumber)->toBe('25117605282');
});

it('uses mainGroupId/btnr already on the OePart', function (): void {
    fakeGearshiftBom();

    $run = SearchRun::query()->create([
        'user_id' => User::factory()->create()->id,
        'kind' => SearchRunKind::Identify,
        'request_text' => 'punho',
        'vin' => 'WMWSU91010T717700',
        'status' => SearchRunStatus::Running,
    ]);

    $parts = resolve(EnrichOePartsFromBom::class)->execute($run, [
        new OePart('25117605282', 'Factory', 'OE', mainGroupId: '25', btnr: '25_0444'),
    ]);

    expect($parts[0]->factoryFit)->toBeTrue();
});

it('returns empty list for empty input', function (): void {
    fakeGearshiftBom();

    $run = SearchRun::query()->create([
        'user_id' => User::factory()->create()->id,
        'kind' => SearchRunKind::Identify,
        'request_text' => 'x',
        'vin' => 'WMWSU91010T717700',
        'status' => SearchRunStatus::Running,
    ]);

    expect(resolve(EnrichOePartsFromBom::class)->execute($run, []))->toBe([]);
});

it('keeps maingroup/btnr when BOM row is missing for a located page', function (): void {
    fakeGearshiftBom();

    $run = SearchRun::query()->create([
        'user_id' => User::factory()->create()->id,
        'kind' => SearchRunKind::Identify,
        'request_text' => 'x',
        'vin' => 'WMWSU91010T717700',
        'status' => SearchRunStatus::Running,
        'tool_traces' => [
            [
                'tool' => 'search_parts_by_vin',
                'result' => json_encode([
                    'ok' => true,
                    'results' => [
                        ['oe' => '11111111111', 'maingroup' => '25', 'btnr' => '25_0444'],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        ],
    ]);

    $parts = resolve(EnrichOePartsFromBom::class)->execute($run, [
        new OePart('11111111111', 'Not on BOM', 'OE'),
    ]);

    expect($parts[0]->mainGroupId)->toBe('25')
        ->and($parts[0]->btnr)->toBe('25_0444')
        ->and($parts[0]->factoryFit)->toBeNull();
});

it('survives listBomParts failures and keeps the selected OE', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
    ]);

    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=x; Path=/']),
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'refreshToken' => 'r', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/bom/vin*' => Http::response('boom', 500),
    ]);

    $run = SearchRun::query()->create([
        'user_id' => User::factory()->create()->id,
        'kind' => SearchRunKind::Identify,
        'request_text' => 'x',
        'vin' => 'WMWSU91010T717700',
        'status' => SearchRunStatus::Running,
        'tool_traces' => [
            [
                'tool' => 'search_parts_by_vin',
                'result' => json_encode([
                    'ok' => true,
                    'results' => [
                        ['oe' => '25117605282', 'maingroup' => '25', 'btnr' => '25_0444'],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        ],
    ]);

    $parts = resolve(EnrichOePartsFromBom::class)->execute($run, [
        new OePart('25117605282', 'Factory', 'OE'),
    ]);

    expect($parts)->toHaveCount(1)
        ->and($parts[0]->oeNumber)->toBe('25117605282')
        ->and($parts[0]->mainGroupId)->toBe('25');
});

it('passes through when BOM location is unknown', function (): void {
    fakeGearshiftBom();

    $run = SearchRun::query()->create([
        'user_id' => User::factory()->create()->id,
        'kind' => SearchRunKind::Identify,
        'request_text' => 'filtro',
        'vin' => 'WMWSU91010T717700',
        'status' => SearchRunStatus::Running,
        'tool_traces' => [],
    ]);

    $parts = resolve(EnrichOePartsFromBom::class)->execute($run, [
        new OePart('99999999999', 'Unknown', 'OE'),
    ]);

    expect($parts)->toHaveCount(1)
        ->and($parts[0]->oeNumber)->toBe('99999999999')
        ->and($parts[0]->factoryFit)->toBeNull();
});

it('deduplicates OEs after factory replacement', function (): void {
    fakeGearshiftBom();

    $run = SearchRun::query()->create([
        'user_id' => User::factory()->create()->id,
        'kind' => SearchRunKind::Identify,
        'request_text' => 'punho',
        'vin' => 'WMWSU91010T717700',
        'status' => SearchRunStatus::Running,
        'tool_traces' => [
            [
                'tool' => 'list_bom_parts',
                'result' => json_encode([
                    'ok' => true,
                    'parts' => [
                        ['oe' => '25117638583', 'maingroup' => '25', 'btnr' => '25_0444', 'factoryFit' => false],
                        ['oe' => '25117605282', 'maingroup' => '25', 'btnr' => '25_0444', 'factoryFit' => true],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        ],
    ]);

    $parts = resolve(EnrichOePartsFromBom::class)->execute($run, [
        new OePart('25117638583', 'GP', 'OE'),
        new OePart('25117638583', 'GP again', 'OE'),
    ]);

    expect(collect($parts)->pluck('oeNumber')->unique()->values()->all())->toBe(['25117605282']);
});

it('keeps greyed option OE when operator text names JCW GP', function (): void {
    fakeGearshiftBom();

    $run = SearchRun::query()->create([
        'user_id' => User::factory()->create()->id,
        'kind' => SearchRunKind::Identify,
        'request_text' => 'punho JCW GP',
        'vin' => 'WMWSU91010T717700',
        'status' => SearchRunStatus::Running,
        'tool_traces' => [
            [
                'tool' => 'search_parts_by_vin',
                'result' => json_encode([
                    'ok' => true,
                    'results' => [
                        [
                            'oe' => '25117638583',
                            'name' => 'Gearshift knob',
                            'maingroup' => '25',
                            'btnr' => '25_0444',
                        ],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        ],
    ]);

    $parts = resolve(EnrichOePartsFromBom::class)->execute($run, [
        new OePart('25117638583', 'Gearshift knob, leather/6-speed', 'OE'),
    ]);

    expect($parts)->toHaveCount(1)
        ->and($parts[0]->oeNumber)->toBe('25117638583')
        ->and($parts[0]->factoryFit)->toBeFalse();
});
