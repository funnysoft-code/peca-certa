<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\SearchRun;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Lightweight signal that a search run advanced.
 *
 * Never embeds supplier result tables — those exceed Reverb message limits.
 * The client reloads the run from the server on this event.
 */
final class SearchRunAdvanced implements ShouldBroadcastNow, ShouldRescue
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public SearchRun $run,
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
        return 'run.advanced';
    }

    /**
     * @return array{run: array{id: string, status: string, kind: string}}
     */
    public function broadcastWith(): array
    {
        return [
            'run' => [
                'id' => $this->run->id,
                'status' => $this->run->status->value,
                'kind' => $this->run->kind->value,
            ],
        ];
    }
}
