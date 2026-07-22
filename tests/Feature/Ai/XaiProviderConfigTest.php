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
        ->and(config('ai.providers.xai.models.image.default'))->toBe('grok-imagine-image');
});

it('passes priority service_tier as xai provider options on agents', function (): void {
    $identify = new IdentifyPartAgent([]);
    $understander = new PartRequestUnderstander;

    expect($identify->providerOptions(Lab::xAI))->toBe(['service_tier' => 'priority'])
        ->and($identify->providerOptions('xai'))->toBe(['service_tier' => 'priority'])
        ->and($identify->providerOptions(Lab::OpenAI))->toBe([])
        ->and($understander->providerOptions(Lab::xAI))->toBe(['service_tier' => 'priority'])
        ->and(TextGenerationOptions::forAgent($identify)->providerOptions(Lab::xAI))
        ->toBe(['service_tier' => 'priority']);
});

it('omits service_tier when the xai config value is empty', function (): void {
    config(['ai.providers.xai.service_tier' => null]);

    expect(new IdentifyPartAgent([])->providerOptions(Lab::xAI))->toBe([]);
});
