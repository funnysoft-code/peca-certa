<?php

declare(strict_types=1);

use App\Actions\LogAgentTokenUsage;
use App\Actions\RunIdentifyAgentTurn;
use App\Actions\UnderstandPartRequest;
use App\Ai\Agents\IdentifyPartAgent;
use App\Ai\Agents\PartRequestUnderstander;
use App\Enums\SearchRunStatus;
use App\Jobs\IdentifyAgentJob;
use App\Jobs\PriceSupplierJob;
use App\Models\SearchRun;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\Usage;
use Psr\Log\LoggerInterface;

it('logs structured token usage including cache counters', function (): void {
    $spy = Mockery::mock(LoggerInterface::class);
    $spy->shouldReceive('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'ai.agent.usage'
            && $context['agent'] === IdentifyPartAgent::class
            && $context['search_run_id'] === 'run-1'
            && $context['prompt_cache_key'] === 'identify-run:run-1'
            && $context['prompt_tokens'] === 100
            && $context['completion_tokens'] === 40
            && $context['cache_read_input_tokens'] === 80
            && $context['cache_write_input_tokens'] === 0
            && $context['reasoning_tokens'] === 12);

    Log::swap($spy);

    resolve(LogAgentTokenUsage::class)->execute(
        IdentifyPartAgent::class,
        new Usage(
            promptTokens: 100,
            completionTokens: 40,
            cacheWriteInputTokens: 0,
            cacheReadInputTokens: 80,
            reasoningTokens: 12,
        ),
        [
            'search_run_id' => 'run-1',
            'prompt_cache_key' => 'identify-run:run-1',
        ],
    );
});

it('records usage after a successful part-request understand turn', function (): void {
    $spy = Mockery::mock(LoggerInterface::class);
    $spy->shouldReceive('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'ai.agent.usage'
            && $context['agent'] === PartRequestUnderstander::class
            && $context['prompt_cache_key'] === 'peca-certa:part-request-understander'
            && array_key_exists('cache_read_input_tokens', $context));

    Log::swap($spy);

    PartRequestUnderstander::fake([
        ['category' => 'filtro de óleo', 'searchTerm' => 'oil filter', 'keywords' => ['óleo'], 'clarifyingQuestion' => null, 'confidence' => 0.9],
    ]);

    resolve(UnderstandPartRequest::class)->execute('filtro de óleo');
});

it('records usage with identify-run prompt_cache_key after an identify agent turn', function (): void {
    Bus::fake([PriceSupplierJob::class]);

    $spy = Mockery::mock(LoggerInterface::class);
    $spy->shouldReceive('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'ai.agent.usage'
            && $context['agent'] === IdentifyPartAgent::class
            && is_string($context['search_run_id'] ?? null)
            && is_string($context['prompt_cache_key'] ?? null)
            && str_starts_with($context['prompt_cache_key'], 'identify-run:'));

    Log::swap($spy);

    IdentifyPartAgent::fake([
        [
            'status' => 'selected',
            'oeParts' => [
                ['oeNumber' => '11427622446', 'description' => 'Oil filter element', 'brand' => 'OE'],
            ],
            'question' => null,
            'options' => [],
            'confidence' => 0.95,
        ],
    ]);

    $run = SearchRun::factory()->create([
        'request_text' => 'filtro de óleo',
        'vin' => 'WMWSU91010T717700',
        'messages' => [],
        'status' => SearchRunStatus::Pending,
    ]);

    new IdentifyAgentJob($run)->handle(resolve(RunIdentifyAgentTurn::class));
});
