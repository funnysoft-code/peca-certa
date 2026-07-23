<?php

declare(strict_types=1);

use App\Actions\PersistOePartDiagrams;
use App\Data\OePart;
use App\Enums\SearchRunKind;
use App\Enums\SearchRunStatus;
use App\Models\SearchRun;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function fakeDiagramPl24(string $bomFixture): void
{
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.diagrams_disk' => 'pl24_diagrams',
        'suppliers.partslink24.illustration_retries' => 2,
    ]);

    Storage::fake('pl24_diagrams');

    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=x; Path=/']),
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'refreshToken' => 'r', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/bom/vin*' => Http::response(json_decode((string) file_get_contents(base_path($bomFixture)), true)),
    ]);
}

function diagramRun(string $toolTraceOe = '25117605282'): SearchRun
{
    return SearchRun::query()->create([
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
                            'oe' => $toolTraceOe,
                            'maingroup' => '25',
                            'btnr' => '25_0444',
                        ],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        ],
    ]);
}

it('stores a content-addressed diagram and links it on the OE', function (): void {
    fakeDiagramPl24('tests/Fixtures/PartsLink24/bom-vin-gearshift-unavailable.json');

    $parts = resolve(PersistOePartDiagrams::class)->execute(diagramRun(), [
        new OePart('25117605282', 'Leath. gearlever knob', 'OE'),
    ]);

    expect($parts)->toHaveCount(1)
        ->and($parts[0]->diagramPath)->toBeString()->not->toBeEmpty()
        ->and($parts[0]->factoryFit)->toBeTrue()
        ->and($parts[0]->pos)->toBe('01');

    Storage::disk('pl24_diagrams')->assertExists((string) $parts[0]->diagramPath);
});

it('reuses one blob when two OEs share the same BOM page', function (): void {
    fakeDiagramPl24('tests/Fixtures/PartsLink24/bom-vin-gearshift-unavailable.json');

    $run = diagramRun();
    $run->tool_traces = [
        [
            'tool' => 'search_parts_by_vin',
            'result' => json_encode([
                'ok' => true,
                'results' => [
                    ['oe' => '25117605282', 'maingroup' => '25', 'btnr' => '25_0444'],
                    ['oe' => '25117638583', 'maingroup' => '25', 'btnr' => '25_0444'],
                ],
            ], JSON_THROW_ON_ERROR),
        ],
    ];
    $run->save();

    $parts = resolve(PersistOePartDiagrams::class)->execute($run, [
        new OePart('25117605282', 'Factory knob', 'OE'),
        new OePart('25117638583', 'GP knob', 'OE'),
    ]);

    expect($parts)->toHaveCount(2)
        ->and($parts[0]->diagramPath)->toBe($parts[1]->diagramPath)
        ->and($parts[0]->diagramPath)->not->toBeNull();

    $files = Storage::disk('pl24_diagrams')->allFiles('diagrams');
    expect($files)->toHaveCount(1);
});

it('completes with null diagram when PL24 has no illustration', function (): void {
    fakeDiagramPl24('tests/Fixtures/PartsLink24/bom-vin-no-images.json');

    $run = diagramRun('11427622446');
    $run->tool_traces = [
        [
            'tool' => 'search_parts_by_vin',
            'result' => json_encode([
                'ok' => true,
                'results' => [
                    ['oe' => '11427622446', 'maingroup' => '11', 'btnr' => '11_4574'],
                ],
            ], JSON_THROW_ON_ERROR),
        ],
    ];
    $run->save();

    $parts = resolve(PersistOePartDiagrams::class)->execute($run, [
        new OePart('11427622446', 'Oil filter', 'OE'),
    ]);

    expect($parts[0]->diagramPath)->toBeNull()
        ->and($parts[0]->diagramUrl)->toBeNull();
});

it('skips invalid tool-trace payloads when locating parts', function (): void {
    fakeDiagramPl24('tests/Fixtures/PartsLink24/bom-vin-gearshift-unavailable.json');

    $run = SearchRun::query()->create([
        'user_id' => User::factory()->create()->id,
        'kind' => SearchRunKind::Identify,
        'request_text' => 'punho',
        'vin' => 'WMWSU91010T717700',
        'status' => SearchRunStatus::Running,
        'tool_traces' => [
            ['result' => null],
            ['result' => ''],
            ['result' => '{bad'],
            ['result' => json_encode(['results' => false], JSON_THROW_ON_ERROR)],
            ['result' => json_encode(['results' => [['oe' => '25117605282', 'maingroup' => 25, 'btnr' => '25_0444']]], JSON_THROW_ON_ERROR)],
            ['result' => json_encode(['results' => [['oe' => '25117605282', 'maingroup' => '', 'btnr' => 'x']]], JSON_THROW_ON_ERROR)],
            ['result' => json_encode(['results' => [['oe' => '25117605282', 'maingroup' => '25', 'btnr' => '']]], JSON_THROW_ON_ERROR)],
            ['result' => json_encode(['parts' => [['oeNumber' => '25117605282', 'mainGroupId' => '25', 'btnr' => '25_0444']]], JSON_THROW_ON_ERROR)],
        ],
    ]);

    $parts = resolve(PersistOePartDiagrams::class)->execute($run, [
        new OePart('25117605282', 'Factory', 'OE'),
    ]);

    expect($parts[0]->diagramPath)->not->toBeNull();
});

it('uses search coordinates when the OE is missing from the BOM page', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.diagrams_disk' => 'pl24_diagrams',
    ]);
    Storage::fake('pl24_diagrams');

    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=x; Path=/']),
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'refreshToken' => 'r', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/search/vin*' => Http::response([
            'data' => [
                'records' => [
                    [
                        'recordContext' => ['bidata_part_no' => '99999999999'],
                        'values' => ['name' => 'Ghost', 'partno' => 'x', 'hg' => '25', 'btnr' => '25_0444'],
                    ],
                ],
            ],
        ]),
        '*/p5bmw/extern/bom/vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/bom-vin-gearshift-unavailable.json')), true)),
    ]);

    $run = SearchRun::query()->create([
        'user_id' => User::factory()->create()->id,
        'kind' => SearchRunKind::Identify,
        'request_text' => 'x',
        'vin' => 'WMWSU91010T717700',
        'status' => SearchRunStatus::Running,
        'tool_traces' => [],
    ]);

    $parts = resolve(PersistOePartDiagrams::class)->execute($run, [
        new OePart('99999999999', 'Ghost', 'OE'),
    ]);

    expect($parts[0]->mainGroupId)->toBe('25')
        ->and($parts[0]->btnr)->toBe('25_0444')
        ->and($parts[0]->diagramPath)->not->toBeNull();
});

it('soft-skips diagrams when PL24 throws a non-RuntimeException during illustration fetch', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.diagrams_disk' => 'pl24_diagrams',
    ]);
    Storage::fake('pl24_diagrams');

    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=x; Path=/']),
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'refreshToken' => 'r', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/bom/vin*' => Http::response('down', 503),
    ]);

    $run = SearchRun::query()->create([
        'user_id' => User::factory()->create()->id,
        'kind' => SearchRunKind::Identify,
        'request_text' => 'punho',
        'vin' => 'WMWSU91010T717700',
        'status' => SearchRunStatus::Running,
    ]);

    $parts = resolve(PersistOePartDiagrams::class)->execute($run, [
        new OePart(
            oeNumber: '25117605282',
            description: 'Factory',
            brand: 'OE',
            mainGroupId: '25',
            btnr: '25_0444',
        ),
    ]);

    expect($parts[0]->diagramPath)->toBeNull()
        ->and($parts[0]->mainGroupId)->toBe('25');
});

it('covers search/tool-trace coordinate validation branches', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.diagrams_disk' => 'pl24_diagrams',
    ]);
    Storage::fake('pl24_diagrams');

    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=x; Path=/']),
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'refreshToken' => 'r', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/search/vin*' => Http::response([
            'data' => [
                'records' => [
                    [
                        'recordContext' => ['bidata_part_no' => '25117605282'],
                        'values' => ['name' => 'x', 'partno' => 'x', 'hg' => null, 'btnr' => '25_0444'],
                    ],
                    [
                        'recordContext' => ['bidata_part_no' => '25117605282'],
                        'values' => ['name' => 'x', 'partno' => 'x', 'hg' => '25', 'btnr' => null],
                    ],
                    [
                        'recordContext' => ['bidata_part_no' => '25117605282'],
                        'values' => ['name' => 'x', 'partno' => 'x', 'hg' => '', 'btnr' => '25_0444'],
                    ],
                    [
                        'recordContext' => ['bidata_part_no' => '25117605282'],
                        'values' => ['name' => 'x', 'partno' => 'x', 'hg' => '25', 'btnr' => ''],
                    ],
                    [
                        'recordContext' => ['bidata_part_no' => '25117605282'],
                        'values' => ['name' => 'x', 'partno' => 'x', 'hg' => '25', 'btnr' => '25_0444'],
                    ],
                ],
            ],
        ]),
        '*/p5bmw/extern/bom/vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/bom-vin-gearshift-unavailable.json')), true)),
    ]);

    $run = SearchRun::query()->create([
        'user_id' => User::factory()->create()->id,
        'kind' => SearchRunKind::Identify,
        'request_text' => 'punho',
        'vin' => 'WMWSU91010T717700',
        'status' => SearchRunStatus::Running,
        'tool_traces' => [
            [
                'result' => json_encode([
                    'results' => [
                        'not-a-list-item',
                        ['oe' => '25117605282', 'maingroup' => '25', 'btnr' => 12345],
                        ['oe' => '25117605282', 'maingroup' => '25', 'btnr' => ''],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        ],
    ]);

    $parts = resolve(PersistOePartDiagrams::class)->execute($run, [
        new OePart('25117605282', 'Factory', 'OE'),
    ]);

    expect($parts[0]->diagramPath)->not->toBeNull();
});

it('returns empty when no OE parts are given', function (): void {
    fakeDiagramPl24('tests/Fixtures/PartsLink24/bom-vin-gearshift-unavailable.json');

    expect(resolve(PersistOePartDiagrams::class)->execute(diagramRun(), []))->toBe([]);
});

it('leaves parts unchanged when BOM location cannot be resolved', function (): void {
    fakeDiagramPl24('tests/Fixtures/PartsLink24/bom-vin-gearshift-unavailable.json');

    $run = SearchRun::query()->create([
        'user_id' => User::factory()->create()->id,
        'kind' => SearchRunKind::Identify,
        'request_text' => 'x',
        'vin' => 'WMWSU91010T717700',
        'status' => SearchRunStatus::Running,
        'tool_traces' => [],
    ]);

    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=x; Path=/']),
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'refreshToken' => 'r', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/search/vin*' => Http::response(['data' => ['records' => []]]),
        '*/p5bmw/extern/bom/vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/bom-vin-gearshift-unavailable.json')), true)),
    ]);

    $parts = resolve(PersistOePartDiagrams::class)->execute($run, [
        new OePart('99999999999', 'Unknown', 'OE'),
    ]);

    expect($parts[0]->diagramPath)->toBeNull()
        ->and($parts[0]->oeNumber)->toBe('99999999999');
});

it('falls back to OePart catalog meta when listBomParts throws', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.diagrams_disk' => 'pl24_diagrams',
    ]);
    Storage::fake('pl24_diagrams');

    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=x; Path=/']),
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'refreshToken' => 'r', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/bom/vin*' => Http::sequence()
            ->push('fail', 500)
            ->push(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/bom-vin-gearshift-unavailable.json')), true)),
    ]);

    $run = SearchRun::query()->create([
        'user_id' => User::factory()->create()->id,
        'kind' => SearchRunKind::Identify,
        'request_text' => 'punho',
        'vin' => 'WMWSU91010T717700',
        'status' => SearchRunStatus::Running,
    ]);

    $parts = resolve(PersistOePartDiagrams::class)->execute($run, [
        new OePart(
            oeNumber: '25117605282',
            description: 'Factory',
            brand: 'OE',
            factoryFit: true,
            pos: '01',
            mainGroupId: '25',
            btnr: '25_0444',
        ),
    ]);

    expect($parts[0]->mainGroupId)->toBe('25')
        ->and($parts[0]->diagramPath)->not->toBeNull();
});

it('skips non-matching search rows and empty catalog coordinates', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.diagrams_disk' => 'pl24_diagrams',
    ]);
    Storage::fake('pl24_diagrams');

    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=x; Path=/']),
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'refreshToken' => 'r', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/search/vin*' => Http::response([
            'data' => [
                'records' => [
                    [
                        'recordContext' => ['bidata_part_no' => '000'],
                        'values' => ['name' => 'x', 'partno' => 'x', 'hg' => '25', 'btnr' => '25_0444'],
                    ],
                    [
                        'recordContext' => ['bidata_part_no' => '25117605282'],
                        'values' => ['name' => 'x', 'partno' => 'x', 'hg' => '', 'btnr' => '25_0444'],
                    ],
                    [
                        'recordContext' => ['bidata_part_no' => '25117605282'],
                        'values' => ['name' => 'x', 'partno' => 'x', 'hg' => '25', 'btnr' => ''],
                    ],
                    [
                        'recordContext' => ['bidata_part_no' => '25117605282'],
                        'values' => ['name' => 'x', 'partno' => 'x', 'hg' => '25', 'btnr' => '25_0444'],
                    ],
                ],
            ],
        ]),
        '*/p5bmw/extern/bom/vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/bom-vin-gearshift-unavailable.json')), true)),
    ]);

    $run = SearchRun::query()->create([
        'user_id' => User::factory()->create()->id,
        'kind' => SearchRunKind::Identify,
        'request_text' => 'punho',
        'vin' => 'WMWSU91010T717700',
        'status' => SearchRunStatus::Running,
        'tool_traces' => [],
    ]);

    $parts = resolve(PersistOePartDiagrams::class)->execute($run, [
        new OePart('25117605282', 'Factory', 'OE'),
    ]);

    expect($parts[0]->diagramPath)->not->toBeNull();
});

it('stores jpeg payloads with a .jpg extension', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.diagrams_disk' => 'pl24_diagrams',
    ]);
    Storage::fake('pl24_diagrams');

    $jpg = "\xFF\xD8\xFF".str_repeat("\x00", 12);

    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=x; Path=/']),
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'refreshToken' => 'r', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/bom/vin*' => Http::response([
            'data' => [
                'records' => [
                    [
                        'partno' => '25117605282',
                        'description' => 'Knob',
                        'values' => ['pos' => '01', 'qty' => '1', 'partno' => 'x'],
                    ],
                ],
                'images' => [
                    ['id' => '_DFLT_', 'content' => base64_encode($jpg)],
                ],
            ],
        ]),
    ]);

    $parts = resolve(PersistOePartDiagrams::class)->execute(diagramRun(), [
        new OePart('25117605282', 'Knob', 'OE', mainGroupId: '25', btnr: '25_0444'),
    ]);

    expect($parts[0]->diagramPath)->toEndWith('.jpg');
});

it('stores riff payloads with a .bin extension', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.diagrams_disk' => 'pl24_diagrams',
    ]);
    Storage::fake('pl24_diagrams');

    $riff = 'RIFF'.str_repeat('A', 24);

    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=x; Path=/']),
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'refreshToken' => 'r', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/bom/vin*' => Http::response([
            'data' => [
                'records' => [
                    [
                        'partno' => '25117605282',
                        'description' => 'Knob',
                        'values' => ['pos' => '01', 'qty' => '1', 'partno' => 'x'],
                    ],
                ],
                'images' => [
                    ['id' => '_DFLT_', 'content' => base64_encode($riff)],
                ],
            ],
        ]),
    ]);

    $parts = resolve(PersistOePartDiagrams::class)->execute(diagramRun(), [
        new OePart('25117605282', 'Knob', 'OE', mainGroupId: '25', btnr: '25_0444'),
    ]);

    expect($parts[0]->diagramPath)->toEndWith('.bin');
});

it('locates OE via searchByVin when tool traces are empty', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.diagrams_disk' => 'pl24_diagrams',
    ]);
    Storage::fake('pl24_diagrams');

    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=x; Path=/']),
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'refreshToken' => 'r', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/search/vin*' => Http::response([
            'data' => [
                'records' => [
                    [
                        'recordContext' => ['bidata_part_no' => '25117605282'],
                        'values' => [
                            'name' => 'Knob',
                            'partno' => '25 11 7 605 282',
                            'hg' => '25',
                            'fg' => '10',
                            'btnr' => '25_0444',
                        ],
                    ],
                ],
            ],
        ]),
        '*/p5bmw/extern/bom/vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/bom-vin-gearshift-unavailable.json')), true)),
    ]);

    $run = SearchRun::query()->create([
        'user_id' => User::factory()->create()->id,
        'kind' => SearchRunKind::Identify,
        'request_text' => 'punho',
        'vin' => 'WMWSU91010T717700',
        'status' => SearchRunStatus::Running,
        'tool_traces' => [],
    ]);

    $parts = resolve(PersistOePartDiagrams::class)->execute($run, [
        new OePart('25117605282', 'Factory', 'OE'),
    ]);

    expect($parts[0]->diagramPath)->not->toBeNull()
        ->and($parts[0]->mainGroupId)->toBe('25');
});

it('falls back to tool-trace location when metaFromBom cannot match the OE', function (): void {
    fakeDiagramPl24('tests/Fixtures/PartsLink24/bom-vin-gearshift-unavailable.json');

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

    $parts = resolve(PersistOePartDiagrams::class)->execute($run, [
        new OePart('11111111111', 'Ghost', 'OE'),
    ]);

    // Located via traces; diagram still stored for the shared BOM page.
    expect($parts[0]->mainGroupId)->toBe('25')
        ->and($parts[0]->btnr)->toBe('25_0444')
        ->and($parts[0]->diagramPath)->not->toBeNull();
});

it('uses maingroup/btnr already present on the OePart', function (): void {
    fakeDiagramPl24('tests/Fixtures/PartsLink24/bom-vin-gearshift-unavailable.json');

    $run = SearchRun::query()->create([
        'user_id' => User::factory()->create()->id,
        'kind' => SearchRunKind::Identify,
        'request_text' => 'punho',
        'vin' => 'WMWSU91010T717700',
        'status' => SearchRunStatus::Running,
        'tool_traces' => [],
    ]);

    $parts = resolve(PersistOePartDiagrams::class)->execute($run, [
        new OePart(
            oeNumber: '25117605282',
            description: 'Factory',
            brand: 'OE',
            mainGroupId: '25',
            btnr: '25_0444',
        ),
    ]);

    expect($parts[0]->diagramPath)->not->toBeNull()
        ->and($parts[0]->factoryFit)->toBeTrue();
});

it('stores gif illustrations with a .gif extension', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.diagrams_disk' => 'pl24_diagrams',
    ]);
    Storage::fake('pl24_diagrams');

    $gif = 'GIF89a'.str_repeat("\x00", 16);

    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=x; Path=/']),
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'refreshToken' => 'r', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/bom/vin*' => Http::response([
            'data' => [
                'records' => [
                    [
                        'partno' => '25117605282',
                        'description' => 'Knob',
                        'values' => ['pos' => '01', 'qty' => '1', 'partno' => 'x'],
                    ],
                ],
                'images' => [
                    ['id' => '_DFLT_', 'data' => base64_encode($gif)],
                ],
            ],
        ]),
    ]);

    $parts = resolve(PersistOePartDiagrams::class)->execute(diagramRun(), [
        new OePart('25117605282', 'Knob', 'OE', mainGroupId: '25', btnr: '25_0444'),
    ]);

    expect($parts[0]->diagramPath)->toEndWith('.gif');
});

it('stores svg illustrations with a .svg extension', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.diagrams_disk' => 'pl24_diagrams',
    ]);
    Storage::fake('pl24_diagrams');

    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>';

    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=x; Path=/']),
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'refreshToken' => 'r', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/bom/vin*' => Http::response([
            'data' => [
                'records' => [
                    [
                        'partno' => '25117605282',
                        'description' => 'Knob',
                        'values' => ['pos' => '01', 'qty' => '1', 'partno' => 'x'],
                    ],
                ],
                'images' => [
                    ['id' => '_DFLT_', 'data' => base64_encode($svg)],
                ],
            ],
        ]),
    ]);

    $parts = resolve(PersistOePartDiagrams::class)->execute(diagramRun(), [
        new OePart('25117605282', 'Knob', 'OE', mainGroupId: '25', btnr: '25_0444'),
    ]);

    expect($parts[0]->diagramPath)->toEndWith('.svg');
});

it('hard-fails when PL24 advertises an illustration but bytes cannot be obtained', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.diagrams_disk' => 'pl24_diagrams',
        'suppliers.partslink24.illustration_retries' => 2,
    ]);

    Storage::fake('pl24_diagrams');

    Http::fake([
        '*/auth/ext/api/1.1/login' => Http::response(['loginStatus' => 'OK', 'sessionToken' => 'portal-sess'], 200, ['Set-Cookie' => 'PL24TOKEN=x; Path=/']),
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'refreshToken' => 'r', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/bom/vin*' => Http::response([
            'data' => [
                'records' => [
                    [
                        'partno' => '25117605282',
                        'description' => 'Knob',
                        'values' => ['pos' => '01', 'qty' => '1', 'partno' => '25 11 7 605 282'],
                    ],
                ],
                'images' => [
                    ['id' => '_DFLT_', 'name' => '25_0444'],
                ],
            ],
        ]),
        '*/p5bmw/extern/images/vin*' => Http::response(['messages' => ['HTTP 404 Not Found']], 500),
    ]);

    $run = diagramRun();

    resolve(PersistOePartDiagrams::class)->execute($run, [
        new OePart('25117605282', 'Knob', 'OE'),
    ]);
})->throws(RuntimeException::class, 'illustration present but download failed');
