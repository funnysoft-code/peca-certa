<?php

declare(strict_types=1);

namespace App\Events;

use App\Data\SearchRunData;
use App\Models\SearchRun;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class SearchRunAdvanced implements ShouldBroadcast
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
     * @return array{run: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        return [
            'run' => SearchRunData::fromModel($this->run->load('lookups'))->jsonSerialize(),
        ];
    }
}
