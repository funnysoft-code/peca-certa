<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\SupplierLookup;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Lightweight signal that a supplier lookup finished.
 *
 * Full variant payloads exceed Reverb's max message size (hundreds of
 * Auto Delta rows). The client reloads the run from the server on this event.
 */
final class SupplierResultReady implements ShouldBroadcastNow, ShouldRescue
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
     * @return array{lookup: array{id: string, supplier: string, status: string}}
     */
    public function broadcastWith(): array
    {
        return [
            'lookup' => [
                'id' => $this->lookup->id,
                'supplier' => $this->lookup->supplier->value,
                'status' => $this->lookup->status->value,
            ],
        ];
    }
}
