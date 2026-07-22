<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Supplier;
use App\Models\Finding;
use App\Models\SearchRun;
use App\Models\SupplierLookup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Finding>
 */
final class FindingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'search_run_id' => SearchRun::factory(),
            'supplier_lookup_id' => fn (array $attributes): string => SupplierLookup::factory()->create([
                'search_run_id' => $attributes['search_run_id'],
            ])->id,
            'supplier' => Supplier::AutoDelta,
            'brand' => fake()->company(),
            'article' => fake()->bothify('??####'),
            'trader_article_number' => fake()->bothify('T-####'),
            'price' => fake()->randomFloat(2, 1, 200),
            'currency' => 'EUR',
            'available_quantity' => fake()->numberBetween(1, 50),
            'in_stock' => true,
            'warehouse' => 'WH1',
        ];
    }

    public function outOfStock(): self
    {
        return $this->state(fn (): array => [
            'available_quantity' => 0,
            'in_stock' => false,
        ]);
    }

    public function forLookup(SupplierLookup $lookup): self
    {
        return $this->state(fn (): array => [
            'search_run_id' => $lookup->search_run_id,
            'supplier_lookup_id' => $lookup->id,
            'supplier' => $lookup->supplier,
        ]);
    }
}
