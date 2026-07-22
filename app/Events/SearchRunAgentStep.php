<?php

declare(strict_types=1);

namespace App\Events;

use App\Data\AgentStep;
use App\Models\SearchRun;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Live agent tool-step progress for an identify run.
 *
 * Payload stays tiny (no PL24 tool results) so Reverb stays happy.
 * Steps are also persisted on search_runs.agent_steps for refresh safety.
 */
final class SearchRunAgentStep implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public SearchRun $run,
        public AgentStep $step,
    ) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('search-run.'.$this->run->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'agent.step';
    }

    /**
     * @return array{runId: string, step: array{id: string, tool: string, label: string, status: string, detail: string|null, at: string}}
     */
    public function broadcastWith(): array
    {
        return [
            'runId' => $this->run->id,
            'step' => $this->step->jsonSerialize(),
        ];
    }
}
