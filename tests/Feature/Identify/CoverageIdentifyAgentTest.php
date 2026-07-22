<?php

declare(strict_types=1);

use App\Actions\FanOutOePricing;
use App\Actions\ResumeIdentifyRun;
use App\Actions\RunIdentifyAgentTurn;
use App\Ai\Agents\IdentifyPartAgent;
use App\Ai\Tools\PartsLink24\DecodeVin;
use App\Ai\Tools\PartsLink24\GetPartInfo;
use App\Ai\Tools\PartsLink24\ListBomParts;
use App\Ai\Tools\PartsLink24\ListMainGroups;
use App\Ai\Tools\PartsLink24\ListSubGroups;
use App\Ai\Tools\PartsLink24\ResolveBrand;
use App\Ai\Tools\PartsLink24\SearchPartsByVin;
use App\Data\IdentifyAgentResult;
use App\Data\OePart;
use App\Enums\SearchRunStatus;
use App\Events\SearchRunAdvanced;
use App\Jobs\IdentifyAgentJob;
use App\Jobs\PriceSupplierJob;
use App\Models\SearchRun;
use App\Services\PartsLink24\PartsLink24Brand;
use App\Services\PartsLink24\PartsLink24Client;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;

function pl24AuthFake(array $extra = []): void
{
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
    ]);

    Http::fake(array_merge([
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
    ], $extra));
}

it('covers tool descriptions, schemas, and get_part_info paths', function (): void {
    pl24AuthFake([
        '*/p5bmw/extern/partinfo/vin*' => Http::sequence()
            ->push(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/partinfo-vin.json')), true))
            ->push(['messages' => ['Cannot invoke'], 'demo' => false]),
    ]);

    /** @var list<class-string> $tools */
    $tools = [
        ResolveBrand::class,
        DecodeVin::class,
        SearchPartsByVin::class,
        ListMainGroups::class,
        ListSubGroups::class,
        ListBomParts::class,
        GetPartInfo::class,
    ];

    $schema = new JsonSchemaTypeFactory;

    foreach ($tools as $class) {
        $tool = resolve($class);
        expect($tool->description())->toBeString()->not->toBeEmpty()
            ->and($tool->schema($schema))->toBeArray()->not->toBeEmpty();
    }

    $ok = json_decode((string) resolve(GetPartInfo::class)->handle(new Request([
        'vin' => 'WMWSU91010T717700',
        'mainGroupId' => '11',
        'btnr' => '11_4574',
        'partinfoPartno' => '8643745',
        'pos' => '1',
    ])), true);

    expect($ok['ok'])->toBeTrue();

    $missing = json_decode((string) resolve(GetPartInfo::class)->handle(new Request([
        'vin' => 'WMWSU91010T717700',
        'mainGroupId' => '11',
        'btnr' => '11_4574',
        'partinfoPartno' => '0',
        'pos' => '9',
    ])), true);

    expect($missing['ok'])->toBeFalse()->and($missing['error'])->toBe('not_found');

    $unsupported = json_decode((string) resolve(GetPartInfo::class)->handle(new Request([
        'vin' => 'ZZZ99999999999999',
        'mainGroupId' => '11',
        'btnr' => '11_4574',
        'partinfoPartno' => '1',
        'pos' => '1',
    ])), true);

    expect($unsupported['error'])->toBe('unsupported_brand');
});

it('covers unsupported brand branches on every PL24 tool', function (): void {
    Http::fake();
    $vin = 'ZZZ99999999999999';

    foreach ([
        resolve(DecodeVin::class)->handle(new Request(['vin' => $vin])),
        resolve(SearchPartsByVin::class)->handle(new Request(['vin' => $vin, 'query' => 'x'])),
        resolve(ListMainGroups::class)->handle(new Request(['vin' => $vin])),
        resolve(ListSubGroups::class)->handle(new Request(['vin' => $vin, 'mainGroupId' => '11'])),
        resolve(ListBomParts::class)->handle(new Request(['vin' => $vin, 'mainGroupId' => '11', 'btnr' => '1'])),
    ] as $raw) {
        expect(json_decode((string) $raw, true)['error'])->toBe('unsupported_brand');
    }

    Http::assertNothingSent();
});

it('covers client empty-record and null-decode paths', function (): void {
    pl24AuthFake([
        '*/p5bmw/extern/search/vin*' => Http::response(['data' => ['records' => [
            ['values' => ['name' => 'no oe']],
            ['recordContext' => ['bidata_part_no' => ''], 'values' => ['name' => 'empty']],
            ['recordContext' => ['bidata_part_no' => 123], 'values' => ['name' => 'bad type']],
            ['recordContext' => ['bidata_part_no' => '1'], 'values' => ['name' => 9]],
            ['recordContext' => ['bidata_part_no' => '2'], 'values' => ['name' => 'ok', 'partno' => 9, 'hg' => 1, 'fg' => 2, 'btnr' => 3]],
        ]]]),
        '*/p5bmw/extern/directAccess*' => Http::sequence()
            ->push(['data' => []])
            ->push([
                'data' => [
                    'vin' => 'WMWSU91010T717700',
                    'resultStatus' => 'VEHICLE_IDENTIFIED',
                    'description' => 'MINI\\-R56',
                    'segments' => ['vinfoBasic' => ['records' => [
                        ['values' => ['description' => '', 'value' => 'x']],
                        ['values' => ['description' => 'Color', 'value' => '']],
                        ['values' => ['description' => 1, 'value' => 'x']],
                        ['values' => ['description' => 'Model', 'value' => 'R56']],
                    ]]],
                ],
            ]),
        '*/p5bmw/extern/groups/main-vin*' => Http::response(['data' => ['records' => [
            ['id' => '', 'values' => ['description' => 'skip']],
            ['id' => 11, 'values' => ['description' => 'bad id type']],
            ['values' => ['id' => '11', 'description' => 'Engine']],
            ['values' => ['id' => '12', 'description' => '']],
        ]]]),
        '*/p5bmw/extern/groups/func-vin*' => Http::response(['data' => ['records' => [
            ['id' => '', 'values' => ['descr' => 'skip']],
            ['id' => 1, 'values' => ['descr' => 'bad']],
            ['id' => 'bad_desc', 'values' => ['descr' => 99]],
            ['characteristic' => 'sectionrow', 'id' => '05', 'values' => ['descr' => 'Engine']],
            ['id' => 'plain', 'values' => ['descr' => 'No underscore']],
            ['id' => 'x_y', 'values' => ['description' => 'alt\\-key']],
            ['id' => 'z_a', 'values' => ['descr' => '']],
        ]]]),
        '*/p5bmw/extern/bom/vin*' => Http::response(['data' => ['records' => [
            ['characteristic' => 'sectionrow', 'description' => 'hdr'],
            ['partno' => '', 'description' => 'skip'],
            ['partno' => 9, 'description' => 'bad oe type'],
            ['partno' => '1', 'description' => '', 'values' => []],
            ['partno' => '2', 'description' => 9, 'values' => []],
            [
                'partno' => '1142',
                'description' => 'Part',
                'pos' => 2,
                'values' => ['qty' => 1, 'partno' => '11 42'],
                'link' => ['path' => '/x?partno=42'],
            ],
            [
                'partno' => '1143',
                'description' => 'No link',
                'values' => ['pos' => '03', 'qty' => '2', 'partno' => '11 43'],
                'link' => ['path' => '/no-query'],
            ],
            [
                'partno' => '1144',
                'values' => ['description' => 'from values', 'partno' => 9, 'pos' => null, 'qty' => null],
                'link' => ['path' => ''],
            ],
            [
                'partno' => '1145',
                'description' => 'empty partno query',
                'values' => ['pos' => '1', 'qty' => '1'],
                'link' => ['path' => '/x?partno='],
            ],
        ]]]),
        '*/p5bmw/extern/partinfo/vin*' => Http::sequence()
            ->push(['messages' => ['fail']])
            ->push(['data' => ['segments' => [
                'a' => 'not-array',
                'b' => ['records' => [
                    ['values' => ['description' => '', 'value' => 'x']],
                    ['values' => ['name' => 'Label', 'value' => 'Val\\-ue']],
                    ['values' => ['name' => 'OnlyName']],
                    ['values' => ['name' => 'EmptyVal', 'value' => '']],
                    ['values' => ['name' => 'NumVal', 'value' => 3]],
                ]],
            ], 'records' => []]])
            ->push(['data' => ['segments' => [], 'records' => [
                ['partno' => '11 42 1', 'description' => 'With\\-dash'],
            ]]])
            ->push(['data' => ['segments' => [], 'records' => []]]),
    ]);

    $client = resolve(PartsLink24Client::class);
    $brand = new PartsLink24Brand('mini', 'mini_parts', 'p5bmw');

    $search = $client->searchByVin($brand, 'WMWSU91010T717700', 'q');
    expect($search)->toHaveCount(1)
        ->and($search[0]['partno'])->toBe('2')
        ->and($search[0]['maingroup'])->toBeNull();

    expect($client->decodeVin($brand, 'WMWSU91010T717700'))->toBeNull();

    $decoded = $client->decodeVin($brand, 'WMWSU91010T717700');
    expect($decoded)->not->toBeNull()
        ->and($decoded['description'])->toBe('MINI-R56')
        ->and($decoded['fields'])->toHaveCount(1);

    expect($client->listMainGroups($brand, 'WMWSU91010T717700'))->toHaveCount(1)
        ->and($client->listSubGroups($brand, 'WMWSU91010T717700', '11'))->toHaveCount(3)
        ->and($client->listBomParts($brand, 'WMWSU91010T717700', '11', 'x'))->toHaveCount(4)
        ->and($client->getPartInfo($brand, 'v', '11', 'b', 'p', '1'))->toBeNull();

    $withFields = $client->getPartInfo($brand, 'v', '11', 'b', 'p', '1');
    expect($withFields)->not->toBeNull()
        ->and($withFields['fields'][0]['value'])->toBe('Val-ue');

    $withOe = $client->getPartInfo($brand, 'v', '11', 'b', 'p', '1');
    expect($withOe['oe'])->toBe('11421')
        ->and($withOe['description'])->toBe('With-dash');

    expect($client->getPartInfo($brand, 'v', '11', 'b', 'p', '1'))->toBeNull();
});

it('covers decode_vin tool when vehicle is not identified', function (): void {
    Cache::flush();
    pl24AuthFake([
        '*/p5bmw/extern/directAccess*' => Http::response(['data' => []]),
    ]);

    $json = json_decode((string) resolve(DecodeVin::class)->handle(new Request(['vin' => 'WMWSU91010T717700'])), true);

    expect($json['ok'])->toBeFalse()->and($json['error'])->toBe('vin_not_identified');
});

it('covers IdentifyAgentResult edge parsing and jsonSerialize', function (): void {
    $result = IdentifyAgentResult::fromArray([
        'status' => null,
        'question' => '',
        'options' => 'nope',
        'oeParts' => [
            'skip',
            ['oeNumber' => '', 'description' => 'x', 'brand' => 'OE'],
            ['oeNumber' => '1', 'description' => 'ok', 'brand' => 'OE'],
        ],
        'confidence' => 'not-a-number',
    ]);

    expect($result->status)->toBe('failed')
        ->and($result->question)->toBeNull()
        ->and($result->options)->toBe([])
        ->and($result->oeParts)->toHaveCount(1)
        ->and($result->confidence)->toBe(0.0)
        ->and($result->needsInput())->toBeFalse()
        ->and($result->hasSelectedParts())->toBeFalse()
        ->and($result->jsonSerialize()['oeParts'][0])->toBeInstanceOf(OePart::class);
});

it('covers agent job no-ops for deleted, done, and failed runs', function (): void {
    Bus::fake([PriceSupplierJob::class]);
    Event::fake([SearchRunAdvanced::class]);

    $deleted = SearchRun::factory()->create();
    SearchRun::query()->whereKey($deleted->id)->delete();
    new IdentifyAgentJob($deleted)->handle(resolve(RunIdentifyAgentTurn::class));
    new IdentifyAgentJob($deleted)->failed(new RuntimeException('x'));

    $done = SearchRun::factory()->create(['status' => SearchRunStatus::Done]);
    new IdentifyAgentJob($done)->handle(resolve(RunIdentifyAgentTurn::class));
    new IdentifyAgentJob($done)->failed(new RuntimeException('x'));
    expect($done->refresh()->status)->toBe(SearchRunStatus::Done);

    $failed = SearchRun::factory()->create(['status' => SearchRunStatus::Failed]);
    new IdentifyAgentJob($failed)->failed(new RuntimeException('x'));
    expect($failed->refresh()->status)->toBe(SearchRunStatus::Failed);

    $middleware = new IdentifyAgentJob(SearchRun::factory()->make())->middleware();
    expect($middleware)->toHaveCount(2);
});

it('covers empty OE selection and fan-out empty list', function (): void {
    Bus::fake([PriceSupplierJob::class]);
    IdentifyPartAgent::fake([
        [
            'status' => 'failed',
            'oeParts' => [],
            'question' => null,
            'options' => [],
            'confidence' => 0.1,
        ],
    ]);

    $run = SearchRun::factory()->create([
        'request_text' => 'x',
        'vin' => 'WMWSU91010T717700',
        'messages' => [],
    ]);

    new IdentifyAgentJob($run)->handle(resolve(RunIdentifyAgentTurn::class));

    expect($run->refresh()->status)->toBe(SearchRunStatus::Done)
        ->and($run->oe_parts)->toBe([]);

    resolve(FanOutOePricing::class)->execute($run->fresh(), []);
    Bus::assertNotDispatched(PriceSupplierJob::class);
});

it('covers resume invalid status and IdentifyPartAgent wiring', function (): void {
    $run = SearchRun::factory()->create(['status' => SearchRunStatus::Running]);

    expect(fn () => resolve(ResumeIdentifyRun::class)->execute($run, 'x'))
        ->toThrow(InvalidArgumentException::class);

    $agent = new IdentifyPartAgent([]);
    expect($agent->maxSteps())->toBeNull()
        ->and($agent->timeout())->toBeNull()
        ->and($agent->instructions())->toContain('PartsLink24')
        ->and(iterator_to_array($agent->messages()))->toBe([])
        ->and(iterator_to_array($agent->tools()))->toHaveCount(7)
        ->and($agent->schema(new JsonSchemaTypeFactory))->toHaveKeys(['status', 'oeParts', 'question', 'options', 'confidence']);
});

it('covers RunIdentifyAgentTurn when agent returns selected with empty parts via hasSelected false', function (): void {
    Bus::fake([PriceSupplierJob::class]);
    IdentifyPartAgent::fake([
        [
            'status' => 'selected',
            'oeParts' => [],
            'question' => null,
            'options' => [],
            'confidence' => 0.2,
        ],
    ]);

    $run = SearchRun::factory()->create([
        'request_text' => 'nada',
        'vin' => 'WMWSU91010T717700',
        'messages' => [
            ['role' => 'user', 'content' => 'old'],
            ['role' => 'assistant', 'content' => '{}'],
            ['role' => 'user', 'content' => 'resume answer'],
        ],
    ]);

    resolve(RunIdentifyAgentTurn::class)->execute($run);

    expect($run->refresh()->status)->toBe(SearchRunStatus::Done)
        ->and($run->oe_parts)->toBe([]);
});
