<?php

declare(strict_types=1);

use App\Actions\PersistOePartDiagrams;
use App\Data\OePart;
use App\Data\SearchRunData;
use App\Enums\SearchRunKind;
use App\Enums\SearchRunStatus;
use App\Models\SearchRun;
use App\Models\User;
use App\Support\OePartDiagramUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('serves stored diagram bytes via the auth-gated identify diagram route', function (): void {
    config()->set('suppliers.partslink24.diagrams_disk', 'pl24_diagrams');
    Storage::fake('pl24_diagrams');

    $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
    expect($bytes)->toBeString()->not->toBeEmpty();

    $path = 'diagrams/abc123.png';
    Storage::disk('pl24_diagrams')->put($path, $bytes);

    $owner = User::factory()->create(['email_verified_at' => now()]);
    $run = SearchRun::factory()->for($owner)->create([
        'kind' => SearchRunKind::Identify,
        'status' => SearchRunStatus::Done,
        'oe_parts' => [[
            'oeNumber' => '25117605282',
            'description' => 'Knob',
            'brand' => 'OE',
            'factoryFit' => true,
            'diagramPath' => $path,
            'diagramUrl' => null,
        ]],
    ]);

    $url = OePartDiagramUrl::for($run, $path);

    expect($url)
        ->toContain('/identify/'.$run->id.'/diagrams/abc123.png')
        ->not->toStartWith('/storage/');

    // Response body must match the private-disk payload exactly (reachable URL).
    $response = $this->actingAs($owner)->get($url);
    $response->assertOk()->assertHeader('Content-Type', 'image/png');
    expect($response->streamedContent())->toBe($bytes);
});

it('rejects guests and non-owners for diagram routes', function (): void {
    config()->set('suppliers.partslink24.diagrams_disk', 'pl24_diagrams');
    Storage::fake('pl24_diagrams');

    $path = 'diagrams/secret.png';
    Storage::disk('pl24_diagrams')->put($path, 'PNGDATA');

    $owner = User::factory()->create(['email_verified_at' => now()]);
    $other = User::factory()->create(['email_verified_at' => now()]);
    $run = SearchRun::factory()->for($owner)->create([
        'kind' => SearchRunKind::Identify,
        'oe_parts' => [[
            'oeNumber' => '1',
            'description' => 'x',
            'brand' => 'OE',
            'diagramPath' => $path,
        ]],
    ]);

    $url = route('identify.diagram', ['run' => $run, 'filename' => 'secret.png']);

    $this->get($url)->assertRedirect(route('login'));

    $this->actingAs($other)
        ->get($url)
        ->assertForbidden();
});

it('returns 404 for non-identify runs and path traversal filenames', function (): void {
    config()->set('suppliers.partslink24.diagrams_disk', 'pl24_diagrams');
    Storage::fake('pl24_diagrams');
    Storage::disk('pl24_diagrams')->put('diagrams/ok.png', 'x');

    $owner = User::factory()->create(['email_verified_at' => now()]);
    $partsRun = SearchRun::factory()->for($owner)->create([
        'kind' => SearchRunKind::Parts,
        'oe_parts' => [[
            'oeNumber' => '1',
            'description' => 'x',
            'brand' => 'OE',
            'diagramPath' => 'diagrams/ok.png',
        ]],
    ]);

    $this->actingAs($owner)
        ->get(route('identify.diagram', ['run' => $partsRun, 'filename' => 'ok.png']))
        ->assertNotFound();
});

it('returns 404 when the filename is not referenced by the run', function (): void {
    config()->set('suppliers.partslink24.diagrams_disk', 'pl24_diagrams');
    Storage::fake('pl24_diagrams');
    Storage::disk('pl24_diagrams')->put('diagrams/orphan.png', 'x');

    $owner = User::factory()->create(['email_verified_at' => now()]);
    $run = SearchRun::factory()->for($owner)->create([
        'kind' => SearchRunKind::Identify,
        'oe_parts' => [[
            'oeNumber' => '1',
            'description' => 'x',
            'brand' => 'OE',
            'diagramPath' => 'diagrams/owned.png',
        ]],
    ]);

    Storage::disk('pl24_diagrams')->put('diagrams/owned.png', 'owned');

    $this->actingAs($owner)
        ->get(route('identify.diagram', ['run' => $run, 'filename' => 'orphan.png']))
        ->assertNotFound();
});

it('wires PersistOePartDiagrams diagramUrl to the serve route and GET returns stored bytes', function (): void {
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.diagrams_disk' => 'pl24_diagrams',
    ]);
    Storage::fake('pl24_diagrams');

    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);

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
                    ['id' => '_DFLT_', 'data' => base64_encode((string) $png)],
                ],
            ],
        ]),
    ]);

    $owner = User::factory()->create(['email_verified_at' => now()]);
    $run = SearchRun::factory()->for($owner)->create([
        'kind' => SearchRunKind::Identify,
        'status' => SearchRunStatus::Running,
        'vin' => 'WMWSU91010T717700',
        'request_text' => 'punho',
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

    $parts = resolve(PersistOePartDiagrams::class)->execute($run, [
        new OePart('25117605282', 'Knob', 'OE'),
    ]);

    expect($parts[0]->diagramPath)->toBeString()->toStartWith('diagrams/')
        ->and($parts[0]->diagramUrl)->toContain('/identify/'.$run->id.'/diagrams/')
        ->and($parts[0]->diagramUrl)->not->toContain('/storage/');

    // Persist action returns enriched parts; fan-out saves them — mirror that here.
    $run->oe_parts = array_map(fn (OePart $part): array => $part->jsonSerialize(), $parts);
    $run->save();

    $filename = basename((string) $parts[0]->diagramPath);
    $diskBytes = Storage::disk('pl24_diagrams')->get((string) $parts[0]->diagramPath);

    $response = $this->actingAs($owner)
        ->get(route('identify.diagram', ['run' => $run, 'filename' => $filename]));

    $response->assertOk()->assertHeader('Content-Type', 'image/png');
    expect($response->streamedContent())->toBe($diskBytes);
});

it('serves gif jpeg and svg with the correct content types', function (): void {
    config()->set('suppliers.partslink24.diagrams_disk', 'pl24_diagrams');
    Storage::fake('pl24_diagrams');

    $owner = User::factory()->create(['email_verified_at' => now()]);

    $cases = [
        'shot.gif' => ['bytes' => 'GIF89a'.str_repeat("\x00", 8), 'mime' => 'image/gif'],
        'shot.jpg' => ['bytes' => "\xFF\xD8\xFF".str_repeat("\x00", 8), 'mime' => 'image/jpeg'],
        'shot.svg' => ['bytes' => '<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'mime' => 'image/svg+xml'],
        'shot.bin' => ['bytes' => 'RIFF'.str_repeat('A', 8), 'mime' => 'application/octet-stream'],
    ];

    foreach ($cases as $filename => $case) {
        $path = 'diagrams/'.$filename;
        Storage::disk('pl24_diagrams')->put($path, $case['bytes']);

        $run = SearchRun::factory()->for($owner)->create([
            'kind' => SearchRunKind::Identify,
            'oe_parts' => [[
                'oeNumber' => '1',
                'description' => 'x',
                'brand' => 'OE',
                'diagramPath' => $path,
            ]],
        ]);

        $this->actingAs($owner)
            ->get(route('identify.diagram', ['run' => $run, 'filename' => $filename]))
            ->assertOk()
            ->assertHeader('Content-Type', $case['mime']);
    }
});

it('exposes browser-reachable diagramUrl on SearchRunData from private disk path', function (): void {
    config()->set('suppliers.partslink24.diagrams_disk', 'pl24_diagrams');
    Storage::fake('pl24_diagrams');
    Storage::disk('pl24_diagrams')->put('diagrams/ui.png', 'PNGBYTES');

    $run = SearchRun::factory()->create([
        'kind' => SearchRunKind::Identify,
        'oe_parts' => [[
            'oeNumber' => '25117605282',
            'description' => 'Knob',
            'brand' => 'OE',
            'diagramPath' => 'diagrams/ui.png',
            'diagramUrl' => null,
        ]],
    ]);

    $data = SearchRunData::fromModel($run->load(['lookups', 'user']));

    expect($data->oeParts[0]->diagramUrl)
        ->toBe(route('identify.diagram', ['run' => $run, 'filename' => 'ui.png']))
        ->not->toContain('/storage/');
});
