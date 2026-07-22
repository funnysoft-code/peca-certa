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

        $options = [];

        $serviceTier = config('ai.providers.xai.service_tier');

        if (filled($serviceTier)) {
            $options['service_tier'] = $serviceTier;
        }

        $promptCacheKey = $this->xaiPromptCacheKey();

        if (filled($promptCacheKey)) {
            // Responses API sticky routing for prompt-cache hits.
            // https://docs.x.ai/developers/advanced-api-usage/prompt-caching/maximizing-cache-hits
            $options['prompt_cache_key'] = $promptCacheKey;
        }

        return $options;
    }

    /**
     * Stable key for xAI Responses API prompt_cache_key.
     * Override per agent (per-run for multi-turn; shared for single-shot agents).
     */
    protected function xaiPromptCacheKey(): ?string
    {
        return null;
    }
}
