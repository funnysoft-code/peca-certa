<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\SearchRunStatus;
use App\Jobs\IdentifyAgentJob;
use App\Models\SearchRun;
use InvalidArgumentException;

final readonly class ResumeIdentifyRun
{
    public function execute(SearchRun $run, string $answer, ?string $selectedOption = null): SearchRun
    {
        throw_if($run->status !== SearchRunStatus::NeedsInput, InvalidArgumentException::class, 'Search run is not waiting for operator input.');

        $parts = array_values(array_filter([
            $selectedOption !== null && $selectedOption !== '' ? 'Opção escolhida: '.$selectedOption : null,
            $answer !== '' ? $answer : null,
        ]));

        $content = $parts === [] ? 'Continuar sem texto adicional.' : implode("\n", $parts);

        $messages = $run->messages ?? [];
        $messages[] = [
            'role' => 'user',
            'content' => $content,
        ];

        $run->messages = $messages;
        $run->pending_question = null;
        $run->status = SearchRunStatus::Pending;
        $run->save();

        dispatch(new IdentifyAgentJob($run));

        return $run;
    }
}
