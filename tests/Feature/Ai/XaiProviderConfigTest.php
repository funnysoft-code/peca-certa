<?php

declare(strict_types=1);

use App\Ai\Agents\IdentifyPartAgent;
use App\Ai\Agents\PartRequestUnderstander;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\TextGenerationOptions;

it('defaults to the xai provider with grok-4.3', function (): void {
    expect(config('ai.default'))->toBe('xai')
        ->and(config('ai.default_for_images'))->toBe('xai')
        ->and(config('ai.providers.xai.driver'))->toBe('xai')
        ->and(config('ai.providers.xai.service_tier'))->toBe('priority')
        ->and(config('ai.providers.xai.models.text.default'))->toBe('grok-4.3')
        ->and(config('ai.providers.xai.models.image.default'))->toBe('grok-imagine-image')
        ->and(config('ai.providers.xai.prompt_cache_keys.part_request_understander'))
        ->toBe('peca-certa:part-request-understander');
});

it('passes priority service_tier and prompt_cache_key as xai provider options', function (): void {
    $identify = new IdentifyPartAgent(promptCacheKey: 'identify-run:abc');
    $understander = new PartRequestUnderstander;

    expect($identify->providerOptions(Lab::xAI))->toBe([
        'service_tier' => 'priority',
        'prompt_cache_key' => 'identify-run:abc',
    ])
        ->and($identify->providerOptions('xai'))->toBe([
            'service_tier' => 'priority',
            'prompt_cache_key' => 'identify-run:abc',
        ])
        ->and($identify->providerOptions(Lab::OpenAI))->toBe([])
        ->and($understander->providerOptions(Lab::xAI))->toBe([
            'service_tier' => 'priority',
            'prompt_cache_key' => 'peca-certa:part-request-understander',
        ])
        ->and(TextGenerationOptions::forAgent($identify)->providerOptions(Lab::xAI))
        ->toBe([
            'service_tier' => 'priority',
            'prompt_cache_key' => 'identify-run:abc',
        ]);
});

it('omits service_tier when the xai config value is empty but keeps prompt_cache_key', function (): void {
    config(['ai.providers.xai.service_tier' => null]);

    expect(new IdentifyPartAgent(promptCacheKey: 'identify-run:xyz')->providerOptions(Lab::xAI))
        ->toBe(['prompt_cache_key' => 'identify-run:xyz']);
});

it('omits prompt_cache_key when identify agent has none and understander config is blank', function (): void {
    config([
        'ai.providers.xai.service_tier' => null,
        'ai.providers.xai.prompt_cache_keys.part_request_understander' => null,
    ]);

    expect(new IdentifyPartAgent([])->providerOptions(Lab::xAI))->toBe([])
        ->and(new PartRequestUnderstander()->providerOptions(Lab::xAI))->toBe([]);
});
