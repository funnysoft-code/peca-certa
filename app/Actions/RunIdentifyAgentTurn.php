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
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;

final readonly class RunIdentifyAgentTurn
{
    public function __construct(
        private FanOutOePricing $fanOutOePricing,
    ) {}

    public function execute(SearchRun $run): IdentifyAgentResult
    {
        $run->status = SearchRunStatus::Running;
        $run->pending_question = null;
        $run->save();
        event(new SearchRunAdvanced($run));

        [$prompt, $historyRows, $promptAlreadyStored] = $this->promptAndHistory($run);

        $history = $this->toMessages($historyRows);
        $maxSteps = config()->integer('identify.max_tool_steps');
        $timeout = config()->integer('identify.turn_timeout_seconds');

        $response = new IdentifyPartAgent($history, $maxSteps, $timeout)->prompt(
            $prompt,
            timeout: $timeout,
        );

        throw_unless(
            $response instanceof StructuredAgentResponse,
            RuntimeException::class,
            'IdentifyPartAgent did not return structured output.',
        );

        /** @var array<array-key, mixed> $payload */
        $payload = $response->toArray();
        $result = IdentifyAgentResult::fromArray($payload);

        $messages = $run->messages ?? [];

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
        $run->messages = $messages;

        if ($result->needsInput()) {
            $run->pending_question = new IdentifyClarification(
                question: (string) $result->question,
                options: $result->options,
            )->jsonSerialize();
            $run->status = SearchRunStatus::NeedsInput;
            $run->save();
            event(new SearchRunAdvanced($run));

            return $result;
        }

        if ($result->hasSelectedParts()) {
            $run->save();
            $this->fanOutOePricing->execute($run, $result->oeParts);

            return $result;
        }

        $run->oe_parts = array_map(
            fn (OePart $part): array => $part->jsonSerialize(),
            $result->oeParts,
        );
        $run->status = SearchRunStatus::Done;
        $run->pending_question = null;
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
