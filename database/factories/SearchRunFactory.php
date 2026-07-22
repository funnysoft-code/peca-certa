<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SearchRunKind;
use App\Enums\SearchRunStatus;
use App\Models\SearchRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SearchRun>
 */
final class SearchRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kind' => SearchRunKind::Identify,
            'request_text' => fake()->sentence(),
            'vin' => fake()->regexify('[A-HJ-NPR-Z0-9]{17}'),
            'reference' => null,
            'understanding' => null,
            'oe_parts' => null,
            'status' => SearchRunStatus::Pending,
        ];
    }

    public function parts(): self
    {
        return $this->state(fn (): array => [
            'kind' => SearchRunKind::Parts,
            'request_text' => null,
            'vin' => null,
            'reference' => fake()->bothify('?? ###'),
        ]);
    }
}
