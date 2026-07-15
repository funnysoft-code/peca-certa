<?php

declare(strict_types=1);

use App\Actions\IdentifyAndSourceParts;
use App\Ai\Agents\PartRequestUnderstander;
use App\Data\OePart;
use App\Services\AutoDelta\AutoDeltaToken;
use App\Services\PartsLink24\Contracts\PartsLink24Catalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

it('understands, identifies, and prices when a vin is given', function (): void {
    PartRequestUnderstander::fake([
        ['category' => 'filtro de óleo', 'keywords' => ['óleo'], 'clarifyingQuestion' => null, 'confidence' => 0.9],
    ]);
    $this->mock(PartsLink24Catalog::class)
        ->shouldReceive('resolveOeParts')->once()
        ->andReturn([new OePart('OC 90', 'Filtro de óleo', 'OE')]);

    config()->set('suppliers.autodelta.catalog_url', 'https://cat.test/WebCat30WS');
    config()->set('suppliers.autodelta.search_url', 'https://cat.test/Tecdoc');
    config()->set('suppliers.autodelta.catalog_id', 'CAT');
    config()->set('suppliers.autozitania.username', 'user');
    config()->set('suppliers.autozitania.password', 'secret');
    Cache::put('autodelta.token', new AutoDeltaToken('KEY', 'USER', now()->addDay()), now()->addDay());
    $search = json_decode((string) file_get_contents(base_path('tests/Fixtures/AutoDelta/search-by-number.json')), true);
    $prices = json_decode((string) file_get_contents(base_path('tests/Fixtures/AutoDelta/trade-prices.json')), true);
    Http::fakeSequence('cat.test/*')->push($search['response'])->push($prices['response']);
    Process::fake(['*' => Process::result(output: (string) file_get_contents(base_path('tests/Fixtures/AutoZitania/search-output.json')))]);

    $result = resolve(IdentifyAndSourceParts::class)->execute('filtro de óleo para Golf', 'WVWZZZ1JZXW000001');

    expect($result->understanding->category)->toBe('filtro de óleo')
        ->and($result->oeParts)->toHaveCount(1)
        ->and($result->autoDeltaResults)->not->toBeEmpty()
        ->and($result->autoZitaniaResults)->not->toBeEmpty();
});

it('stops at the clarifying question and does not identify', function (): void {
    PartRequestUnderstander::fake([
        ['category' => '', 'keywords' => [], 'clarifyingQuestion' => 'Qual é o motor?', 'confidence' => 0.2],
    ]);
    $this->mock(PartsLink24Catalog::class)->shouldNotReceive('resolveOeParts');

    $result = resolve(IdentifyAndSourceParts::class)->execute('preciso de uma peça', 'WVWZZZ1JZXW000001');

    expect($result->understanding->needsClarification())->toBeTrue()
        ->and($result->oeParts)->toBe([])
        ->and($result->autoDeltaResults)->toBe([])
        ->and($result->autoZitaniaResults)->toBe([]);
});
