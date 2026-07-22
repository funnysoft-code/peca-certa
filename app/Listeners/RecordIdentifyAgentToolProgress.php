<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\RecordIdentifyAgentStep;
use App\Ai\Agents\IdentifyPartAgent;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Tools\ToolNameResolver;

/**
 * Surfaces IdentifyPartAgent tool calls as live SearchRun progress.
 *
 * Correlation: RunIdentifyAgentTurn sets Context key identify.search_run_id.
 * Structured agents cannot stream() in laravel/ai; tool events fill that gap.
 */
final readonly class RecordIdentifyAgentToolProgress
{
    public function __construct(
        private RecordIdentifyAgentStep $recordStep,
    ) {}

    public function handleInvoking(InvokingTool $event): void
    {
        $this->record($event, 'running');
    }

    public function handleInvoked(ToolInvoked $event): void
    {
        $this->record($event, 'done');
    }

    /**
     * @param  'running'|'done'  $status
     */
    private function record(InvokingTool|ToolInvoked $event, string $status): void
    {
        if (! $event->agent instanceof IdentifyPartAgent) {
            return;
        }

        $runId = Context::get('identify.search_run_id');

        if (! is_string($runId) || $runId === '') {
            return;
        }

        $tool = ToolNameResolver::resolve($event->tool);
        $detail = $this->recordStep->detailFromArguments($event->arguments);

        $this->recordStep->execute(
            runId: $runId,
            stepId: $event->toolInvocationId,
            tool: $tool,
            status: $status,
            detail: $detail,
        );
    }
}
