<?php

declare(strict_types=1);

namespace App\Ai\Concerns;

use Laravel\Ai\Enums\Lab;

trait UsesXaiProviderOptions
{
    /**
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $driver = $provider instanceof Lab ? $provider->value : $provider;

        if ($driver !== Lab::xAI->value) {
            return [];
        }

        $serviceTier = config('ai.providers.xai.service_tier');

        if (blank($serviceTier)) {
            return [];
        }

        return [
            'service_tier' => $serviceTier,
        ];
    }
}
