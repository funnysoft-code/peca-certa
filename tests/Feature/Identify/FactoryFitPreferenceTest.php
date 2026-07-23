<?php

declare(strict_types=1);

use App\Actions\FanOutOePricing;
use App\Ai\Agents\IdentifyPartAgent;
use App\Data\OePart;
use App\Enums\SearchRunKind;
use App\Enums\SearchRunStatus;
use App\Models\SearchRun;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('requires confidence >= 0.9 and factory-fit in agent instructions', function (): void {
    $instructions = new IdentifyPartAgent([])->instructions();

    expect($instructions)->toContain('confidence >= 0.9')
        ->and($instructions)->toContain('factoryFit')
        ->and($instructions)->toContain('needs_input')
        ->and($instructions)->toContain('decode_vin');
});

it('fan-out prefers factory OE 25117605282 over greyed GP 25117638583 for plain gearshift request', function (): void {
    Bus::fake();

    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
        'suppliers.partslink24.diagrams_disk' => 'pl24_diagrams',
    ]);

    Storage::fake('pl24_diagrams');

    Http::fake([
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'refreshToken' => 'r', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/bom/vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/bom-vin-gearshift-unavailable.json')), true)),
        '*/p5bmw/extern/search/vin*' => Http::response(['data' => ['records' => []]]),
    ]);

    $run = SearchRun::query()->create([
        'user_id' => User::factory()->create()->id,
        'kind' => SearchRunKind::Identify,
        'request_text' => 'manete de mudanças',
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
                            'maingroup' => '25',
                            'btnr' => '25_0444',
                        ],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
        ],
    ]);

    // Agent wrongly selected the GP greyed OE (production bug).
    resolve(FanOutOePricing::class)->execute($run, [
        new OePart('25117638583', 'Gearshift knob, leather/6-speed', 'OE'),
    ]);

    $run->refresh();
    $oeParts = $run->oe_parts ?? [];

    expect($oeParts)->toHaveCount(1)
        ->and($oeParts[0]['oeNumber'])->toBe('25117605282')
        ->and($oeParts[0]['factoryFit'])->toBeTrue()
        ->and($oeParts[0]['diagramPath'])->not->toBeNull();
});
