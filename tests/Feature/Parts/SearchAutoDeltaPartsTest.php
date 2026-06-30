<?php

declare(strict_types=1);

use App\Actions\SearchAutoDeltaParts;
use App\Services\AutoDelta\AutoDeltaToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('returns merged variants for a reference', function (): void {
    config()->set('suppliers.autodelta.catalog_url', 'https://cat.test/WebCat30WS');
    config()->set('suppliers.autodelta.search_url', 'https://cat.test/Tecdoc');
    config()->set('suppliers.autodelta.catalog_id', 'CAT');
    config()->set('suppliers.autodelta.provider', 1066);
    Cache::put('autodelta.token', new AutoDeltaToken('KEY', 'USER', now()->addDay()), now()->addDay());

    $search = json_decode((string) file_get_contents(base_path('tests/Fixtures/AutoDelta/search-by-number.json')), true);
    $prices = json_decode((string) file_get_contents(base_path('tests/Fixtures/AutoDelta/trade-prices.json')), true);

    // First call → search (getArticles) response, second → prices response.
    Http::fakeSequence('cat.test/*')
        ->push($search['response'])
        ->push($prices['response']);

    $result = resolve(SearchAutoDeltaParts::class)->execute('OC90');

    expect($result->query)->toBe('OC90')
        ->and($result->variants)->not->toBeEmpty()
        ->and($result->variants[0]->brandName)->not->toBe('');
});

it('returns empty variants when search returns no articles', function (): void {
    config()->set('suppliers.autodelta.catalog_url', 'https://cat.test/WebCat30WS');
    config()->set('suppliers.autodelta.search_url', 'https://cat.test/Tecdoc');
    config()->set('suppliers.autodelta.catalog_id', 'CAT');
    config()->set('suppliers.autodelta.provider', 1066);
    Cache::put('autodelta.token', new AutoDeltaToken('KEY', 'USER', now()->addDay()), now()->addDay());

    Http::fake(['cat.test/*' => Http::response(['articles' => [], 'status' => 200])]);

    $result = resolve(SearchAutoDeltaParts::class)->execute('NOPE');

    expect($result->query)->toBe('NOPE')
        ->and($result->variants)->toBe([]);
});
