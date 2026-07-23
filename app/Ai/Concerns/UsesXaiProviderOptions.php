<?php

declare(strict_types=1);

namespace App\Ai\Concerns;

use App\Ai\Attributes\Reasoning;
use App\Ai\Enums\ReasoningEffort;
use Laravel\Ai\Enums\Lab;
use ReflectionClass;

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

        $options = [
            // Responses API: https://docs.x.ai/developers/model-capabilities/text/reasoning
            'reasoning' => ['effort' => $this->xaiReasoningEffort()->value],
        ];

        $serviceTier = config('ai.providers.xai.service_tier');

        if (filled($serviceTier)) {
            $options['service_tier'] = $serviceTier;
        }

        $promptCacheKey = $this->xaiPromptCacheKey();

        if (filled($promptCacheKey)) {
            // Sticky routing for prompt-cache hits.
            // https://docs.x.ai/developers/advanced-api-usage/prompt-caching/maximizing-cache-hits
            $options['prompt_cache_key'] = $promptCacheKey;
        }

        return $options;
    }

    /**
     * Prefer #[Reasoning(ReasoningEffort::…)] on the agent; default Low if omitted.
     */
    protected function xaiReasoningEffort(): ReasoningEffort
    {
        $attributes = new ReflectionClass($this)->getAttributes(Reasoning::class);

        if ($attributes === []) {
            return ReasoningEffort::Low;
        }

        return $attributes[0]->newInstance()->effort;
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
