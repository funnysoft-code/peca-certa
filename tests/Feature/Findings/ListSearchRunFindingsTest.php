<?php

declare(strict_types=1);

use App\Enums\Supplier;
use App\Models\Finding;
use App\Models\SearchRun;
use App\Models\SupplierLookup;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;

function findingsRoute(SearchRun $run, array $query = []): string
{
    $base = route('search-runs.findings.index', $run);

    if ($query === []) {
        return $base;
    }

    return $base.'?'.http_build_query($query);
}

it('returns a paginated findings contract for the run owner', function (): void {
    $user = User::factory()->create();
    $run = SearchRun::factory()->for($user)->create();
    Finding::factory()->count(3)->forLookup(
        SupplierLookup::factory()->for($run, 'run')->create(['supplier' => Supplier::AutoDelta]),
    )->create();

    $this->actingAs($user)
        ->getJson(findingsRoute($run, ['filter' => ['in_stock' => '1'], 'per_page' => 2, 'page' => 1]))
        ->assertOk()
        ->assertJson(fn (AssertableJson $json): AssertableJson => $json
            ->has('data', 2)
            ->has('links', fn (AssertableJson $links): AssertableJson => $links
                ->has('first')
                ->has('last')
                ->has('prev')
                ->has('next')
                ->etc())
            ->has('meta', fn (AssertableJson $meta): AssertableJson => $meta
                ->where('per_page', 2)
                ->where('total', 3)
                ->where('current_page', 1)
                ->has('links')
                ->etc())
            ->etc());
});

it('forbids access for non-owners', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $run = SearchRun::factory()->for($owner)->create();

    $this->actingAs($other)
        ->getJson(findingsRoute($run))
        ->assertForbidden();
});

it('requires authentication', function (): void {
    $run = SearchRun::factory()->create();

    $this->getJson(findingsRoute($run))->assertUnauthorized();
});

it('accepts valid filters and rejects unknown filter keys with 422', function (): void {
    $user = User::factory()->create();
    $run = SearchRun::factory()->for($user)->create();
    $lookup = SupplierLookup::factory()->for($run, 'run')->create();
    Finding::factory()->forLookup($lookup)->create([
        'brand' => 'Mann-Filter',
        'article' => 'OC90',
        'supplier' => Supplier::AutoDelta,
        'in_stock' => true,
    ]);

    $this->actingAs($user)
        ->getJson(findingsRoute($run, ['filter' => ['search' => 'Mann']]))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($user)
        ->getJson(findingsRoute($run, ['filter' => ['foo' => 'bar']]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter']);
});

it('defaults stock visibility via filter in_stock true and shows all when filter is removed', function (): void {
    $user = User::factory()->create();
    $run = SearchRun::factory()->for($user)->create();
    $lookup = SupplierLookup::factory()->for($run, 'run')->create();
    Finding::factory()->forLookup($lookup)->create(['brand' => 'In', 'in_stock' => true, 'available_quantity' => 2]);
    Finding::factory()->forLookup($lookup)->outOfStock()->create(['brand' => 'Out']);

    $this->actingAs($user)
        ->getJson(findingsRoute($run, ['filter' => ['in_stock' => '1']]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.brand', 'In');

    $this->actingAs($user)
        ->getJson(findingsRoute($run))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('accepts allowed sorts and rejects invalid sorts with 422', function (): void {
    $user = User::factory()->create();
    $run = SearchRun::factory()->for($user)->create();
    $lookup = SupplierLookup::factory()->for($run, 'run')->create();
    Finding::factory()->forLookup($lookup)->create(['brand' => 'Zebra', 'price' => 30]);
    Finding::factory()->forLookup($lookup)->create(['brand' => 'Alpha', 'price' => 10]);

    $asc = $this->actingAs($user)
        ->getJson(findingsRoute($run, ['sort' => 'brand']))
        ->assertOk()
        ->json('data');

    expect($asc[0]['brand'])->toBe('Alpha')
        ->and($asc[1]['brand'])->toBe('Zebra');

    $descPrice = $this->actingAs($user)
        ->getJson(findingsRoute($run, ['sort' => '-price']))
        ->assertOk()
        ->json('data');

    expect((float) $descPrice[0]['price'])->toBe(30.0);

    $this->actingAs($user)
        ->getJson(findingsRoute($run, ['sort' => 'not_a_column']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sort']);
});

it('clamps per_page to the configured max and uses default when out of range', function (): void {
    config()->set('peca.pagination_size', 15);
    config()->set('peca.max_pagination_size', 50);

    $user = User::factory()->create();
    $run = SearchRun::factory()->for($user)->create();
    $lookup = SupplierLookup::factory()->for($run, 'run')->create();
    Finding::factory()->count(3)->forLookup($lookup)->create();

    $this->actingAs($user)
        ->getJson(findingsRoute($run, ['per_page' => 25]))
        ->assertOk()
        ->assertJsonPath('meta.per_page', 25);

    $this->actingAs($user)
        ->getJson(findingsRoute($run, ['per_page' => 999]))
        ->assertOk()
        ->assertJsonPath('meta.per_page', 15);

    $this->actingAs($user)
        ->getJson(findingsRoute($run, ['per_page' => 0]))
        ->assertOk()
        ->assertJsonPath('meta.per_page', 15);
});

it('appends the query string on paginator links', function (): void {
    $user = User::factory()->create();
    $run = SearchRun::factory()->for($user)->create();
    $lookup = SupplierLookup::factory()->for($run, 'run')->create();
    Finding::factory()->count(3)->forLookup($lookup)->create();

    $response = $this->actingAs($user)
        ->getJson(findingsRoute($run, [
            'filter' => ['in_stock' => '1'],
            'sort' => 'brand',
            'per_page' => 1,
            'page' => 1,
        ]))
        ->assertOk();

    $next = $response->json('links.next');
    expect($next)->toBeString()
        ->and($next)->toContain('filter')
        ->and($next)->toContain('sort=brand')
        ->and($next)->toContain('per_page=1');
});

it('rejects a non-array filter payload with 422', function (): void {
    $user = User::factory()->create();
    $run = SearchRun::factory()->for($user)->create();

    $this->actingAs($user)
        ->getJson(findingsRoute($run).'?filter=not-an-array')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter']);
});

it('searches across supplier brand and article columns', function (): void {
    $user = User::factory()->create();
    $run = SearchRun::factory()->for($user)->create();
    $lookup = SupplierLookup::factory()->for($run, 'run')->create(['supplier' => Supplier::AutoDelta]);
    Finding::factory()->forLookup($lookup)->create(['brand' => 'Mann', 'article' => 'AAA', 'supplier' => Supplier::AutoDelta]);
    Finding::factory()->forLookup($lookup)->create(['brand' => 'Bosch', 'article' => 'OC90', 'supplier' => Supplier::AutoDelta]);

    $this->actingAs($user)
        ->getJson(findingsRoute($run, ['filter' => ['search' => 'OC9']]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.article', 'OC90');

    $this->actingAs($user)
        ->getJson(findingsRoute($run, ['filter' => ['search' => 'autodelta']]))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('matches brand search case-insensitively (Meyle finds MEYLE)', function (): void {
    $user = User::factory()->create();
    $run = SearchRun::factory()->for($user)->create();
    $lookup = SupplierLookup::factory()->for($run, 'run')->create(['supplier' => Supplier::AutoDelta]);
    Finding::factory()->forLookup($lookup)->create([
        'brand' => 'MEYLE',
        'article' => '714 322 0007',
        'supplier' => Supplier::AutoDelta,
        'in_stock' => true,
        'available_quantity' => 98,
        'price' => 3.49,
    ]);
    Finding::factory()->forLookup($lookup)->outOfStock()->create([
        'brand' => 'MEYLE',
        'article' => '11-12 330 0000/SK',
        'supplier' => Supplier::AutoDelta,
    ]);
    Finding::factory()->forLookup($lookup)->create([
        'brand' => 'BOSCH',
        'article' => 'F026',
        'supplier' => Supplier::AutoDelta,
        'in_stock' => true,
    ]);

    // Mixed-case UI search must hit uppercase brand (Postgres LIKE is case-sensitive).
    $this->actingAs($user)
        ->getJson(findingsRoute($run, ['filter' => ['search' => 'Meyle', 'in_stock' => '1']]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.brand', 'MEYLE')
        ->assertJsonPath('data.0.article', '714 322 0007');

    $this->actingAs($user)
        ->getJson(findingsRoute($run, ['filter' => ['search' => 'meyle']]))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('matches supplier by UI label as well as enum value', function (): void {
    $user = User::factory()->create();
    $run = SearchRun::factory()->for($user)->create();
    $autoDelta = SupplierLookup::factory()->for($run, 'run')->create(['supplier' => Supplier::AutoDelta]);
    $autoZitania = SupplierLookup::factory()->for($run, 'run')->create(['supplier' => Supplier::AutoZitania]);
    Finding::factory()->forLookup($autoDelta)->create(['brand' => 'A', 'supplier' => Supplier::AutoDelta, 'in_stock' => true]);
    Finding::factory()->forLookup($autoZitania)->create(['brand' => 'B', 'supplier' => Supplier::AutoZitania, 'in_stock' => true]);

    $this->actingAs($user)
        ->getJson(findingsRoute($run, ['filter' => ['search' => 'Auto Delta']]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.supplier', 'autodelta');

    $this->actingAs($user)
        ->getJson(findingsRoute($run, ['filter' => ['search' => 'Zitania']]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.supplier', 'autozitania');
});

it('matches article numbers that contain slashes and spaces', function (): void {
    $user = User::factory()->create();
    $run = SearchRun::factory()->for($user)->create();
    $lookup = SupplierLookup::factory()->for($run, 'run')->create();
    Finding::factory()->forLookup($lookup)->create([
        'brand' => 'MEYLE',
        'article' => '11-12 330 0000/SK',
        'in_stock' => false,
    ]);

    $this->actingAs($user)
        ->getJson(findingsRoute($run, ['filter' => ['search' => '11-12 330']]))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
