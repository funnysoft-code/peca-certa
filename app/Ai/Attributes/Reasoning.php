<?php

declare(strict_types=1);

namespace App\Ai\Attributes;

use App\Enums\ReasoningEffort;
use Attribute;

/**
 * Explicit xAI reasoning effort for agents using UsesXaiProviderOptions.
 *
 * Usage: #[Reasoning(ReasoningEffort::Low)]
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Reasoning
{
    public function __construct(
        public ReasoningEffort $effort,
    ) {}
}
