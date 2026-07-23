<?php

declare(strict_types=1);

use App\Ai\Agents\IdentifyPartAgent;
use App\Ai\Agents\PartRequestUnderstander;
use App\Ai\Attributes\Reasoning;
use App\Ai\Concerns\UsesXaiProviderOptions;
use App\Ai\Enums\ReasoningEffort;
use App\Ai\Enums\XaiModel;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\TextGenerationOptions;

it('defaults to the xai provider with grok-4.3', function (): void {
    expect(config('ai.default'))->toBe('xai')
        ->and(config('ai.default_for_images'))->toBe('xai')
        ->and(config('ai.providers.xai.driver'))->toBe('xai')
        ->and(config('ai.providers.xai.service_tier'))->toBe('priority')
        ->and(config('ai.providers.xai.models.text.default'))->toBe(XaiModel::Grok43->value)
        ->and(config('ai.providers.xai.models.text.cheapest'))->toBe(XaiModel::Grok43->value)
        ->and(config('ai.providers.xai.models.text.smartest'))->toBe(XaiModel::Grok45->value)
        ->and(config('ai.providers.xai.models.image.default'))->toBe('grok-imagine-image')
        ->and(config('ai.providers.xai.prompt_cache_keys.part_request_understander'))
        ->toBe('peca-certa:part-request-understander');
});

it('pins agents to xai, grok-4.3, and low reasoning via attributes', function (): void {
    foreach ([IdentifyPartAgent::class, PartRequestUnderstander::class] as $agentClass) {
        $reflection = new ReflectionClass($agentClass);
        $provider = $reflection->getAttributes(Provider::class);
        $model = $reflection->getAttributes(Model::class);
        $reasoning = $reflection->getAttributes(Reasoning::class);

        expect($provider)->not->toBeEmpty()
            ->and($provider[0]->newInstance()->value)->toBe(Lab::xAI)
            ->and($model)->not->toBeEmpty()
            ->and($model[0]->newInstance()->value)->toBe(XaiModel::Grok43->value)
            ->and($reasoning)->not->toBeEmpty()
            ->and($reasoning[0]->newInstance()->effort)->toBe(ReasoningEffort::Low);
    }
});

it('passes reasoning effort, priority service_tier and prompt_cache_key as xai provider options', function (): void {
    $identify = new IdentifyPartAgent(promptCacheKey: 'identify-run:abc');
    $understander = new PartRequestUnderstander;

    expect($identify->providerOptions(Lab::xAI))->toBe([
        'reasoning' => ['effort' => ReasoningEffort::Low->value],
        'service_tier' => 'priority',
        'prompt_cache_key' => 'identify-run:abc',
    ])
        ->and($identify->providerOptions('xai'))->toBe([
            'reasoning' => ['effort' => ReasoningEffort::Low->value],
            'service_tier' => 'priority',
            'prompt_cache_key' => 'identify-run:abc',
        ])
        ->and($identify->providerOptions(Lab::OpenAI))->toBe([])
        ->and($understander->providerOptions(Lab::xAI))->toBe([
            'reasoning' => ['effort' => ReasoningEffort::Low->value],
            'service_tier' => 'priority',
            'prompt_cache_key' => 'peca-certa:part-request-understander',
        ])
        ->and(TextGenerationOptions::forAgent($identify)->providerOptions(Lab::xAI))
        ->toBe([
            'reasoning' => ['effort' => ReasoningEffort::Low->value],
            'service_tier' => 'priority',
            'prompt_cache_key' => 'identify-run:abc',
        ]);
});

it('keeps explicit low reasoning effort when service_tier is empty', function (): void {
    config(['ai.providers.xai.service_tier' => null]);

    expect(new IdentifyPartAgent(promptCacheKey: 'identify-run:xyz')->providerOptions(Lab::xAI))
        ->toBe([
            'reasoning' => ['effort' => ReasoningEffort::Low->value],
            'prompt_cache_key' => 'identify-run:xyz',
        ]);
});

it('keeps reasoning effort when identify agent has no cache key and understander config is blank', function (): void {
    config([
        'ai.providers.xai.service_tier' => null,
        'ai.providers.xai.prompt_cache_keys.part_request_understander' => null,
    ]);

    expect(new IdentifyPartAgent([])->providerOptions(Lab::xAI))->toBe([
        'reasoning' => ['effort' => ReasoningEffort::Low->value],
    ])
        ->and(new PartRequestUnderstander()->providerOptions(Lab::xAI))->toBe([
            'reasoning' => ['effort' => ReasoningEffort::Low->value],
        ]);
});

it('defaults reasoning effort to low when the Reasoning attribute is omitted', function (): void {
    config(['ai.providers.xai.service_tier' => 'priority']);

    $agent = new class
    {
        use UsesXaiProviderOptions;

        public function options(Lab|string $provider): array
        {
            return $this->providerOptions($provider);
        }
    };

    expect($agent->options(Lab::xAI))->toBe([
        'reasoning' => ['effort' => ReasoningEffort::Low->value],
        'service_tier' => 'priority',
    ]);
});

it('honours a non-default Reasoning attribute effort', function (): void {
    $agent = new #[Reasoning(ReasoningEffort::High)] class
    {
        use UsesXaiProviderOptions;

        public function options(Lab|string $provider): array
        {
            return $this->providerOptions($provider);
        }
    };

    expect($agent->options(Lab::xAI)['reasoning'])->toBe([
        'effort' => ReasoningEffort::High->value,
    ]);
});
