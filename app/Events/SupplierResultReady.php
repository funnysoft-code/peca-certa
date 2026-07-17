<?php

declare(strict_types=1);

namespace App\Events;

use App\Data\SupplierLookupData;
use App\Models\SupplierLookup;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class SupplierResultReady implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public SupplierLookup $lookup,
    ) {}

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('search-run.'.$this->lookup->search_run_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'lookup.ready';
    }

    /**
     * @return array{lookup: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        return [
            'lookup' => SupplierLookupData::fromModel($this->lookup)->jsonSerialize(),
        ];
    }
}
