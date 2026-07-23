<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\RecordXaiInferenceCost;
use Illuminate\Http\Client\Events\ResponseReceived;
use Throwable;

/**
 * Persist billed xAI inference cost from every api.x.ai HTTP response.
 *
 * @see https://docs.x.ai/developers/cost-tracking
 */
final readonly class CaptureXaiInferenceCost
{
    public function __construct(
        private RecordXaiInferenceCost $recordXaiInferenceCost,
    ) {}

    public function handle(ResponseReceived $event): void
    {
        try {
            $url = $event->request->url();
            $host = parse_url($url, PHP_URL_HOST);
            $expectedHost = config()->string('costs.xai.api_host', 'api.x.ai');

            if (! is_string($host) || $host !== $expectedHost) {
                return;
            }

            /** @var array<string, mixed>|null $json */
            $json = $event->response->json();
            if (! is_array($json)) {
                return;
            }

            $usage = is_array($json['usage'] ?? null) ? $json['usage'] : null;
            if ($usage === null || ! array_key_exists('cost_in_usd_ticks', $usage)) {
                return;
            }

            $ticks = $usage['cost_in_usd_ticks'];
            if (! is_int($ticks) && ! is_float($ticks) && ! is_string($ticks)) {
                return;
            }

            $promptTokens = $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? null;
            $completionTokens = $usage['completion_tokens'] ?? $usage['output_tokens'] ?? null;
            $totalTokens = $usage['total_tokens'] ?? null;
            $path = parse_url($url, PHP_URL_PATH);

            $this->recordXaiInferenceCost->execute([
                'cost_in_usd_ticks' => $ticks,
                'model' => is_string($json['model'] ?? null) ? $json['model'] : null,
                'path' => is_string($path) ? $path : null,
                'prompt_tokens' => is_numeric($promptTokens) ? (int) $promptTokens : null,
                'completion_tokens' => is_numeric($completionTokens) ? (int) $completionTokens : null,
                'total_tokens' => is_numeric($totalTokens) ? (int) $totalTokens : null,
            ]);
        } catch (Throwable) {
            // Never break inference because cost accounting failed.
        }
    }
}
