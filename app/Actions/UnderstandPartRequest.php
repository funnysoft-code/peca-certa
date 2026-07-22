<?php

declare(strict_types=1);

namespace App\Actions;

use App\Ai\Agents\PartRequestUnderstander;
use App\Data\PartRequestUnderstanding;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;

final readonly class UnderstandPartRequest
{
    public function __construct(
        private LogAgentTokenUsage $logAgentTokenUsage,
    ) {}

    public function execute(string $request): PartRequestUnderstanding
    {
        $response = (new PartRequestUnderstander)->prompt($request);

        throw_unless($response instanceof StructuredAgentResponse, RuntimeException::class, 'PartRequestUnderstander did not return structured output.');

        $this->logAgentTokenUsage->execute(PartRequestUnderstander::class, $response->usage, [
            'prompt_cache_key' => config('ai.providers.xai.prompt_cache_keys.part_request_understander'),
        ]);

        $clarifying = is_string($response['clarifyingQuestion'] ?? null) ? $response['clarifyingQuestion'] : null;
        $category = is_string($response['category'] ?? null) ? $response['category'] : '';
        $searchTerm = is_string($response['searchTerm'] ?? null) ? $response['searchTerm'] : '';

        return new PartRequestUnderstanding(
            category: $category,
            searchTerm: $searchTerm === '' ? $category : $searchTerm,
            keywords: $this->toStringList($response['keywords'] ?? []),
            clarifyingQuestion: $clarifying === '' ? null : $clarifying,
            confidence: is_numeric($response['confidence'] ?? null) ? (float) $response['confidence'] : 0.0,
        );
    }

    /**
     * @return list<string>
     */
    private function toStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            $value,
        ), fn (string $v): bool => $v !== ''));
    }
}
