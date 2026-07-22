<?php

declare(strict_types=1);

use App\Actions\BuildProcurementAnalytics;
use App\Enums\Supplier;
use App\Models\Finding;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('requires authentication for the analytics page', function (): void {
    $this->get(route('analytics.index'))
        ->assertRedirect(route('login'));
});

it('renders shop-wide analytics with default 30 day range', function (): void {
    $user = User::factory()->create();

    Finding::factory()->count(3)->create([
        'supplier' => Supplier::AutoDelta,
        'brand' => 'Mahle',
        'article' => 'OC90',
        'price' => 10.5,
        'in_stock' => true,
        'created_at' => now()->subDays(2),
    ]);

    Finding::factory()->create([
        'supplier' => Supplier::AutoZitania,
        'brand' => 'Mahle',
        'article' => 'OC90',
        'price' => 12.0,
        'in_stock' => true,
        'created_at' => now()->subDays(2),
    ]);

    Finding::factory()->outOfStock()->create([
        'supplier' => Supplier::AutoDelta,
        'brand' => 'Bosch',
        'created_at' => now()->subDays(1),
    ]);

    $this->actingAs($user)
        ->get(route('analytics.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('analytics/index')
            ->where('range', 30)
            ->has('ranges', 3)
            ->where('analytics.scorecards.findings', 5)
            ->where('analytics.scorecards.in_stock', 4)
            ->where('analytics.head_to_head.pairs', 1)
            ->where('analytics.head_to_head.autodelta_wins', 1)
            ->has('analytics.ranked_brands')
            ->has('analytics.suppliers_chart')
            ->has('analytics.brands_chart')
            ->has('analytics.stock_chart')
            ->missing('analytics.findings'));
});

it('accepts 7 30 and 90 day ranges and rejects others as 30', function (): void {
    $user = User::factory()->create();

    Finding::factory()->create([
        'created_at' => now()->subDays(10),
        'in_stock' => true,
    ]);

    Finding::factory()->create([
        'created_at' => now()->subDays(40),
        'in_stock' => true,
    ]);

    $this->actingAs($user)
        ->get(route('analytics.index', ['range' => 7]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('range', 7)
            ->where('analytics.range_days', 7)
            ->where('analytics.scorecards.findings', 0));

    $this->actingAs($user)
        ->get(route('analytics.index', ['range' => 30]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('range', 30)
            ->where('analytics.scorecards.findings', 1));

    $this->actingAs($user)
        ->get(route('analytics.index', ['range' => 90]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('range', 90)
            ->where('analytics.scorecards.findings', 2));

    $this->actingAs($user)
        ->get(route('analytics.index', ['range' => 14]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('range', 30)
            ->where('analytics.range_days', 30));
});

it('builds head to head wins only for paired in-stock article brand rows', function (): void {
    Finding::factory()->create([
        'supplier' => Supplier::AutoDelta,
        'brand' => 'Mahle',
        'article' => 'OC90',
        'price' => 8.0,
        'in_stock' => true,
        'created_at' => now()->subDay(),
    ]);

    Finding::factory()->create([
        'supplier' => Supplier::AutoZitania,
        'brand' => 'Mahle',
        'article' => 'OC90',
        'price' => 7.5,
        'in_stock' => true,
        'created_at' => now()->subDay(),
    ]);

    // Unpaired (only Delta)
    Finding::factory()->create([
        'supplier' => Supplier::AutoDelta,
        'brand' => 'Mann',
        'article' => 'W712',
        'price' => 5.0,
        'in_stock' => true,
        'created_at' => now()->subDay(),
    ]);

    // Paired but one out of stock — skip
    Finding::factory()->create([
        'supplier' => Supplier::AutoDelta,
        'brand' => 'Bosch',
        'article' => 'F026',
        'price' => 4.0,
        'in_stock' => true,
        'created_at' => now()->subDay(),
    ]);

    Finding::factory()->outOfStock()->create([
        'supplier' => Supplier::AutoZitania,
        'brand' => 'Bosch',
        'article' => 'F026',
        'price' => 3.0,
        'created_at' => now()->subDay(),
    ]);

    $payload = resolve(BuildProcurementAnalytics::class)->execute(30);

    expect($payload['head_to_head']['pairs'])->toBe(1)
        ->and($payload['head_to_head']['autozitania_wins'])->toBe(1)
        ->and($payload['head_to_head']['autodelta_wins'])->toBe(0)
        ->and($payload['scorecards']['findings'])->toBe(5)
        ->and($payload['ranked_brands'])->not->toBeEmpty();
});

it('does not dump full finding rows into the analytics payload', function (): void {
    Finding::factory()->count(2)->create(['created_at' => now()->subDay()]);

    $payload = resolve(BuildProcurementAnalytics::class)->execute(30);

    expect($payload)->not->toHaveKey('findings')
        ->and($payload)->not->toHaveKey('rows')
        ->and(array_keys($payload))->toContain(
            'scorecards',
            'suppliers_chart',
            'brands_chart',
            'stock_chart',
            'ranked_brands',
            'head_to_head',
        );
});

it('counts ties when both suppliers share the same cheapest in-stock price', function (): void {
    Finding::factory()->create([
        'supplier' => Supplier::AutoDelta,
        'brand' => 'Mahle',
        'article' => 'OC90',
        'price' => 9.0,
        'in_stock' => true,
        'created_at' => now()->subDay(),
    ]);

    Finding::factory()->create([
        'supplier' => Supplier::AutoZitania,
        'brand' => 'Mahle',
        'article' => 'OC90',
        'price' => 9.0,
        'in_stock' => true,
        'created_at' => now()->subDay(),
    ]);

    $payload = resolve(BuildProcurementAnalytics::class)->execute(30);

    expect($payload['head_to_head']['pairs'])->toBe(1)
        ->and($payload['head_to_head']['ties'])->toBe(1)
        ->and($payload['head_to_head']['autodelta_wins'])->toBe(0)
        ->and($payload['head_to_head']['autozitania_wins'])->toBe(0);
});

it('returns empty ranked price stats and zero rates when no findings exist', function (): void {
    $payload = resolve(BuildProcurementAnalytics::class)->execute(30);

    expect($payload['scorecards']['findings'])->toBe(0)
        ->and($payload['scorecards']['stock_hit_rate'])->toBe(0.0)
        ->and($payload['ranked_brands'])->toBe([])
        ->and($payload['ranked_suppliers'])->toBe([])
        ->and($payload['head_to_head']['pairs'])->toBe(0)
        ->and($payload['suppliers_chart'])->toBe([])
        ->and($payload['brands_chart'])->toBe([]);
});

it('reports null price percentiles when ranked findings have no price', function (): void {
    Finding::factory()->create([
        'brand' => 'NoPriceBrand',
        'price' => null,
        'in_stock' => true,
        'created_at' => now()->subDay(),
    ]);

    $payload = resolve(BuildProcurementAnalytics::class)->execute(30);
    $row = collect($payload['ranked_brands'])->firstWhere('label', 'NoPriceBrand');

    expect($row)->not->toBeNull()
        ->and($row['min_price'])->toBeNull()
        ->and($row['p25_price'])->toBeNull()
        ->and($row['median_price'])->toBeNull();
});

it('normalizes supplier and string keys for aggregate labels', function (): void {
    $action = resolve(BuildProcurementAnalytics::class);
    $stringKey = new ReflectionMethod(BuildProcurementAnalytics::class, 'stringKey');
    $supplierKey = new ReflectionMethod(BuildProcurementAnalytics::class, 'supplierKey');
    $supplierLabel = new ReflectionMethod(BuildProcurementAnalytics::class, 'supplierLabel');

    expect($stringKey->invoke($action, 'Mahle'))->toBe('Mahle')
        ->and($stringKey->invoke($action, 42))->toBe('42')
        ->and($stringKey->invoke($action, 3.5))->toBe('3.5')
        ->and($stringKey->invoke($action, Supplier::AutoDelta))->toBe('autodelta')
        ->and($stringKey->invoke($action, null))->toBe('')
        ->and($supplierKey->invoke($action, Supplier::AutoZitania))->toBe('autozitania')
        ->and($supplierKey->invoke($action, 'autodelta'))->toBe('autodelta')
        ->and($supplierLabel->invoke($action, 'autodelta'))->toBe('Auto Delta')
        ->and($supplierLabel->invoke($action, 'autozitania'))->toBe('Auto Zitânia')
        ->and($supplierLabel->invoke($action, 'other'))->toBe('other');
});
