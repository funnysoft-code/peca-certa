<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\AutoDelta\AutoDeltaToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('renders the parts index page for authenticated users', function (): void {
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->get('/parts')
        ->assertOk();
});

it('requires auth for the search endpoint', function (): void {
    $this->postJson('/parts/search', ['reference' => 'OC90'])->assertUnauthorized();
});

it('returns merged variants as json', function (): void {
    config()->set('suppliers.autodelta.catalog_url', 'https://cat.test/WebCat30WS');
    config()->set('suppliers.autodelta.search_url', 'https://cat.test/Tecdoc');
    config()->set('suppliers.autodelta.catalog_id', 'CAT');
    config()->set('suppliers.autodelta.provider', 1066);
    Cache::put('autodelta.token', new AutoDeltaToken('KEY', 'USER', now()->addDay()), now()->addDay());

    $search = json_decode((string) file_get_contents(base_path('tests/Fixtures/AutoDelta/search-by-number.json')), true);
    $prices = json_decode((string) file_get_contents(base_path('tests/Fixtures/AutoDelta/trade-prices.json')), true);
    Http::fakeSequence('cat.test/*')->push($search['response'])->push($prices['response']);

    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->postJson('/parts/search', ['reference' => 'OC90'])
        ->assertOk()
        ->assertJsonStructure(['query', 'variants' => [['brandName', 'articleNumber', 'purchasePrice', 'retailPrice', 'availableQuantity', 'inStock']]]);
});

it('validates the reference is present', function (): void {
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->postJson('/parts/search', ['reference' => ''])
        ->assertJsonValidationErrorFor('reference');
});
