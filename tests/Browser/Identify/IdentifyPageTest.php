<?php

declare(strict_types=1);

use App\Ai\Agents\PartRequestUnderstander;
use App\Data\OePart;
use App\Models\User;
use App\Services\AutoDelta\AutoDeltaToken;
use App\Services\PartsLink24\Contracts\PartsLink24Catalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

it('identifies a part from a request and vin', function (): void {
    PartRequestUnderstander::fake([
        ['category' => 'filtro de óleo', 'searchTerm' => 'oil filter', 'keywords' => ['óleo'], 'clarifyingQuestion' => null, 'confidence' => 0.9],
    ]);
    $this->mock(PartsLink24Catalog::class)->shouldReceive('resolveOeParts')->andReturn([new OePart('OC 90', 'Filtro', 'OE')]);
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

    $this->actingAs(User::factory()->create(['email_verified_at' => now()]));
    $page = visit('/identify');
    $page->waitForEvent('networkidle');
    $page->fill('input[placeholder="Pedido do cliente"]', 'filtro de óleo para Golf')
        ->fill('input[placeholder="VIN"]', 'WVWZZZ1JZXW000001')
        ->press('Identificar');
    $page->waitForText('Preço')->assertSee('Fornecedor');
})->skip('Superseded by the run-based /identify flow (create/store/show); needs a rewrite against identify/show once Task 9 ships that page.');
