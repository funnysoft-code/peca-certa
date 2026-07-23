<?php

declare(strict_types=1);

namespace App\Ai\Enums;

/**
 * Grok 4.3 reasoning effort levels (Responses API reasoning.effort).
 *
 * @see https://docs.x.ai/developers/model-capabilities/text/reasoning
 */
enum ReasoningEffort: string
{
    case None = 'none';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
