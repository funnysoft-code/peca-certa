<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Supplier;
use App\Enums\SupplierLookupStatus;
use App\Models\SearchRun;
use App\Models\SupplierLookup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierLookup>
 */
final class SupplierLookupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'search_run_id' => SearchRun::factory(),
            'supplier' => Supplier::AutoDelta,
            'query' => fake()->bothify('OC ##'),
            'oe_description' => null,
            'status' => SupplierLookupStatus::Pending,
            'result' => null,
            'error' => null,
        ];
    }
}
