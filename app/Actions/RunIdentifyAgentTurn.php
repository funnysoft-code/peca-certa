<?php

declare(strict_types=1);

namespace App\Actions;

use App\Ai\Agents\IdentifyPartAgent;
use App\Data\IdentifyAgentResult;
use App\Data\IdentifyClarification;
use App\Data\OePart;
use App\Enums\SearchRunStatus;
use App\Events\SearchRunAdvanced;
use App\Models\SearchRun;
use App\Services\PartsLink24\PartsLink24Brand;
use App\Services\PartsLink24\VinBrandResolver;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;

final readonly class RunIdentifyAgentTurn
{
    public function __construct(
        private FanOutOePricing $fanOutOePricing,
        private LogAgentTokenUsage $logAgentTokenUsage,
        private VinBrandResolver $vinBrandResolver,
    ) {}

    public function execute(SearchRun $run): IdentifyAgentResult
    {
        $run->status = SearchRunStatus::Running;
        $run->pending_question = null;
        $run->agent_steps = [];
        $run->save();
        event(new SearchRunAdvanced($run));

        $brand = $this->vinBrandResolver->resolve(
            (string) $run->vin,
            $run->brand_override,
        );

        if (! $brand instanceof PartsLink24Brand) {
            return $this->stopForUnsupportedBrand($run);
        }

        [$prompt, $historyRows, $promptAlreadyStored] = $this->promptAndHistory($run);

        $history = $this->toMessages($historyRows);
        $maxSteps = config()->integer('identify.max_tool_steps');
        $timeout = config()->integer('identify.turn_timeout_seconds');
        $promptCacheKey = 'identify-run:'.$run->id;

        Context::add('identify.search_run_id', $run->id);
        Context::add('identify.brand_override', $brand->key);

        try {
            $response = new IdentifyPartAgent($history, $maxSteps, $timeout, $promptCacheKey)->prompt(
                $prompt,
                timeout: $timeout,
            );
        } finally {
            Context::forget('identify.search_run_id');
            Context::forget('identify.brand_override');
        }

        throw_unless(
            $response instanceof StructuredAgentResponse,
            RuntimeException::class,
            'IdentifyPartAgent did not return structured output.',
        );

        $this->logAgentTokenUsage->execute(IdentifyPartAgent::class, $response->usage, [
            'search_run_id' => $run->id,
            'prompt_cache_key' => $promptCacheKey,
        ]);

        /** @var array<array-key, mixed> $payload */
        $payload = $response->toArray();
        $result = IdentifyAgentResult::fromArray($payload);

        // Operator may cancel while the LLM turn is still in flight.
        $fresh = $run->fresh();

        if ($fresh instanceof SearchRun && $fresh->status->isTerminal()) {
            return $result;
        }

        $target = $fresh instanceof SearchRun ? $fresh : $run;
        $messages = $target->messages ?? [];

        if (! $promptAlreadyStored) {
            $messages[] = [
                'role' => 'user',
                'content' => $prompt,
            ];
        }

        $messages[] = [
            'role' => 'assistant',
            'content' => json_encode($result->jsonSerialize(), JSON_THROW_ON_ERROR),
            'status' => $result->status,
        ];

        $target->messages = $messages;

        if ($result->needsInput()) {
            $target->pending_question = new IdentifyClarification(
                question: (string) $result->question,
                options: $result->options,
            )->jsonSerialize();
            $target->status = SearchRunStatus::NeedsInput;
            $target->save();
            event(new SearchRunAdvanced($target));

            return $result;
        }

        if ($result->hasSelectedParts()) {
            $target->save();
            $this->fanOutOePricing->execute($target, $result->oeParts);

            return $result;
        }

        $target->oe_parts = array_map(
            fn (OePart $part): array => $part->jsonSerialize(),
            $result->oeParts,
        );
        $target->status = SearchRunStatus::Done;
        $target->pending_question = null;
        $target->save();
        event(new SearchRunAdvanced($target));

        return $result;
    }

    private function stopForUnsupportedBrand(SearchRun $run): IdentifyAgentResult
    {
        $options = $this->vinBrandResolver->availableBrandKeys();
        $question = 'Catálogo PartsLink24 não configurado para este VIN (WMI desconhecido ou marca sem catálogo). Escolha a marca do catálogo para continuar. Não é necessário modelo nem ano.';

        $result = new IdentifyAgentResult(
            status: 'needs_input',
            oeParts: [],
            question: $question,
            options: $options,
            confidence: 0.0,
        );

        $messages = $run->messages ?? [];
        $messages[] = [
            'role' => 'assistant',
            'content' => json_encode([
                'status' => 'needs_input',
                'error' => IdentifyClarification::KIND_UNSUPPORTED_BRAND,
                'question' => $question,
                'options' => $options,
            ], JSON_THROW_ON_ERROR),
            'status' => 'needs_input',
            'kind' => IdentifyClarification::KIND_UNSUPPORTED_BRAND,
        ];

        $run->messages = $messages;
        $run->pending_question = new IdentifyClarification(
            question: $question,
            options: $options,
            kind: IdentifyClarification::KIND_UNSUPPORTED_BRAND,
        )->jsonSerialize();
        $run->status = SearchRunStatus::NeedsInput;
        $run->save();
        event(new SearchRunAdvanced($run));

        return $result;
    }

    /**
     * @return array{0: string, 1: list<array{role: string, content: string}>, 2: bool}
     */
    private function promptAndHistory(SearchRun $run): array
    {
        /** @var list<array<string, mixed>> $stored */
        $stored = $run->messages ?? [];
        $last = $stored === [] ? null : array_last($stored);

        // Resume: ResumeIdentifyRun already appended the operator answer as the last user message.
        if (is_array($last) && ($last['role'] ?? null) === 'user' && is_string($last['content'] ?? null)) {
            return [$last['content'], $this->normalizeMessageRows(array_slice($stored, 0, -1)), true];
        }

        $vin = (string) $run->vin;
        $request = (string) $run->request_text;
        $prompt = "VIN: {$vin}\nPedido do operador: {$request}";

        if (is_string($run->brand_override) && $run->brand_override !== '') {
            $prompt .= '
Catálogo PartsLink24 forçado pelo operador: '.$run->brand_override;
        }

        return [$prompt, $this->normalizeMessageRows($stored), false];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{role: string, content: string}>
     */
    private function normalizeMessageRows(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            $role = $row['role'] ?? null;
            $content = $row['content'] ?? null;

            if (is_string($role) && is_string($content) && $content !== '') {
                $normalized[] = ['role' => $role, 'content' => $content];
            }
        }

        return $normalized;
    }

    /**
     * @param  list<array{role: string, content: string}>  $rows
     * @return list<Message>
     */
    private function toMessages(array $rows): array
    {
        $messages = [];

        foreach ($rows as $row) {
            $messageRole = match ($row['role']) {
                'assistant' => MessageRole::Assistant,
                default => MessageRole::User,
            };

            $messages[] = new Message($messageRole, $row['content']);
        }

        return $messages;
    }
}
