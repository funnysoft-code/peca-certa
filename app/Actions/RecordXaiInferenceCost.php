<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Facades\File;

/**
 * Append one xAI inference cost line to the project JSONL ledger.
 *
 * Source of truth: response `usage.cost_in_usd_ticks` from the Inference API.
 *
 * @see https://docs.x.ai/developers/cost-tracking
 */
final readonly class RecordXaiInferenceCost
{
    /**
     * @param  array{
     *     cost_in_usd_ticks?: int|float|string|null,
     *     model?: string|null,
     *     prompt_tokens?: int|null,
     *     completion_tokens?: int|null,
     *     total_tokens?: int|null,
     *     input_tokens?: int|null,
     *     output_tokens?: int|null,
     *     path?: string|null,
     *     recorded_at?: string|null,
     * }  $payload
     */
    public function execute(array $payload): void
    {
        $ticks = $payload['cost_in_usd_ticks'] ?? null;

        if ($ticks === null || $ticks === '') {
            return;
        }

        $ticksInt = (int) $ticks;

        if ($ticksInt < 0) {
            return;
        }

        $ticksPerUsd = config()->integer('costs.xai.ticks_per_usd', 10_000_000_000);
        $usd = $ticksPerUsd > 0 ? $ticksInt / $ticksPerUsd : 0.0;

        $path = config()->string('costs.xai_ledger');
        $directory = dirname($path);

        if (! File::isDirectory($directory)) {
            File::makeDirectory($directory, recursive: true);
        }

        $line = json_encode([
            'recorded_at' => $payload['recorded_at'] ?? now()->utc()->toIso8601String(),
            'cost_in_usd_ticks' => $ticksInt,
            'usd' => $usd,
            'model' => $payload['model'] ?? null,
            'path' => $payload['path'] ?? null,
            'prompt_tokens' => $payload['prompt_tokens'] ?? $payload['input_tokens'] ?? null,
            'completion_tokens' => $payload['completion_tokens'] ?? $payload['output_tokens'] ?? null,
            'total_tokens' => $payload['total_tokens'] ?? null,
        ], JSON_THROW_ON_ERROR);

        File::append($path, $line.PHP_EOL);
    }
}
