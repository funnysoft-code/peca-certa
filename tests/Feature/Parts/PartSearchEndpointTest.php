<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\AutoDelta\AutoDeltaToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

it('renders the parts index page for authenticated users', function (): void {
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->get('/parts')
        ->assertOk();
});

it('requires auth for the search endpoint', function (): void {
    $this->postJson('/parts/search', ['reference' => 'OC90', 'supplier' => 'autodelta'])->assertUnauthorized();
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
        ->postJson('/parts/search', ['reference' => 'OC90', 'supplier' => 'autodelta'])
        ->assertOk()
        ->assertJsonStructure(['query', 'variants' => [['brandName', 'articleNumber', 'purchasePrice', 'retailPrice', 'availableQuantity', 'inStock']]]);
});

it('returns auto zitania variants as json', function (): void {
    config()->set('suppliers.autozitania.username', 'user');
    config()->set('suppliers.autozitania.password', 'secret');

    $fixture = (string) file_get_contents(base_path('tests/Fixtures/AutoZitania/search-output.json'));
    Process::fake(['*' => Process::result(output: $fixture)]);

    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->postJson('/parts/search', ['reference' => 'OC 90', 'supplier' => 'autozitania'])
        ->assertOk()
        ->assertJsonPath('variants.0.brandName', 'FEBI BILSTEIN')
        ->assertJsonPath('variants.0.purchasePrice', null)
        ->assertJsonPath('variants.5.inStock', true);
});

it('validates the reference is present', function (): void {
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->postJson('/parts/search', ['reference' => '', 'supplier' => 'autodelta'])
        ->assertJsonValidationErrorFor('reference');
});

it('validates the supplier is a known supplier', function (): void {
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->postJson('/parts/search', ['reference' => 'OC90', 'supplier' => 'europecas'])
        ->assertJsonValidationErrorFor('supplier');
});

it('validates the supplier is present', function (): void {
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->postJson('/parts/search', ['reference' => 'OC90'])
        ->assertJsonValidationErrorFor('supplier');
});
