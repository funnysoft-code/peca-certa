<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\Usage;

final readonly class LogAgentTokenUsage
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function execute(string $agent, Usage $usage, array $context = []): void
    {
        Log::info('ai.agent.usage', [
            ...$context,
            'agent' => $agent,
            'prompt_tokens' => $usage->promptTokens,
            'completion_tokens' => $usage->completionTokens,
            'cache_read_input_tokens' => $usage->cacheReadInputTokens,
            'cache_write_input_tokens' => $usage->cacheWriteInputTokens,
            'reasoning_tokens' => $usage->reasoningTokens,
        ]);
    }
}
