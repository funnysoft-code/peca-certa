<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\AutoDelta\AutoDeltaToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

it('searches a part and shows variants on the run page', function (): void {
    config()->set('suppliers.autodelta.catalog_url', 'https://cat.test/WebCat30WS');
    config()->set('suppliers.autodelta.search_url', 'https://cat.test/Tecdoc');
    config()->set('suppliers.autodelta.catalog_id', 'CAT');
    config()->set('suppliers.autodelta.provider', 1066);
    config()->set('suppliers.autozitania.username', 'user');
    config()->set('suppliers.autozitania.password', 'secret');
    Cache::put('autodelta.token', new AutoDeltaToken('KEY', 'USER', now()->addDay()), now()->addDay());

    $search = json_decode((string) file_get_contents(base_path('tests/Fixtures/AutoDelta/search-by-number.json')), true);
    $prices = json_decode((string) file_get_contents(base_path('tests/Fixtures/AutoDelta/trade-prices.json')), true);
    Http::fakeSequence('cat.test/*')->push($search['response'])->push($prices['response']);
    Process::fake(['*' => Process::result(output: (string) file_get_contents(base_path('tests/Fixtures/AutoZitania/search-output.json')))]);

    $user = User::factory()->create(['email_verified_at' => now()]);
    $this->actingAs($user);

    $page = visit('/parts');
    $page->waitForEvent('networkidle');

    $page->fill('#reference', 'OC90')
        ->press('@parts-submit');

    $page->waitForEvent('networkidle');

    $page->waitForText('Preço')
        ->assertSee('Fornecedor')
        ->assertSee('OC90')
        ->assertPresent('a[href*="web.tecalliance.net"]');

    $page->waitForText('Abrir OC90 em Auto Delta');

    $page->waitForText('Auto Zitânia')
        ->assertSee('Disponível');

    $page->waitForText('Indisponíveis');
});
