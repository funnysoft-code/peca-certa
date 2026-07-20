<?php

declare(strict_types=1);

it('defaults to the xai provider with grok-4.3', function (): void {
    expect(config('ai.default'))->toBe('xai')
        ->and(config('ai.default_for_images'))->toBe('xai')
        ->and(config('ai.providers.xai.driver'))->toBe('xai')
        ->and(config('ai.providers.xai.models.text.default'))->toBe('grok-4.3')
        ->and(config('ai.providers.xai.models.image.default'))->toBe('grok-imagine-image');
});
