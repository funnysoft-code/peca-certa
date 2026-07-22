<?php

declare(strict_types=1);

use App\Events\SearchRunAdvanced;
use App\Events\SupplierResultReady;
use App\Models\SearchRun;
use App\Models\SupplierLookup;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Support\Facades\Event;

it('broadcasts run advances immediately without a second queue hop and rescues failures', function (): void {
    $run = SearchRun::factory()->create();
    $lookup = SupplierLookup::factory()->for($run, 'run')->create();

    expect(new SearchRunAdvanced($run))
        ->toBeInstanceOf(ShouldBroadcastNow::class)
        ->toBeInstanceOf(ShouldRescue::class)
        ->and(new SupplierResultReady($lookup))
        ->toBeInstanceOf(ShouldBroadcastNow::class)
        ->toBeInstanceOf(ShouldRescue::class);
});

it('broadcasts run advances on the private run channel', function (): void {
    Event::fake();
    $run = SearchRun::factory()->create();

    event(new SearchRunAdvanced($run));

    Event::assertDispatched(SearchRunAdvanced::class, function (SearchRunAdvanced $e) use ($run): bool {
        $channels = $e->broadcastOn();

        return $channels[0] instanceof PrivateChannel
            && $channels[0]->name === 'private-search-run.'.$run->id;
    });
});

it('names the run advanced broadcast and sends a lightweight payload without results', function (): void {
    $run = SearchRun::factory()->create();
    SupplierLookup::factory()->for($run, 'run')->create([
        'result' => ['query' => 'OC90', 'variants' => array_fill(0, 50, ['brandName' => 'X'])],
    ]);

    $event = new SearchRunAdvanced($run);

    expect($event->broadcastAs())->toBe('run.advanced')
        ->and($event->broadcastWith())->toBe([
            'run' => [
                'id' => $run->id,
                'status' => $run->status->value,
                'kind' => $run->kind->value,
            ],
        ])
        ->and(mb_strlen(json_encode($event->broadcastWith()) ?: ''))->toBeLessThan(500);
});

it('broadcasts supplier results on the owning run private channel', function (): void {
    Event::fake();
    $lookup = SupplierLookup::factory()->create();

    event(new SupplierResultReady($lookup));

    Event::assertDispatched(SupplierResultReady::class, function (SupplierResultReady $e) use ($lookup): bool {
        $channels = $e->broadcastOn();

        return $channels[0] instanceof PrivateChannel
            && $channels[0]->name === 'private-search-run.'.$lookup->search_run_id;
    });
});

it('names the supplier result broadcast and omits full variant tables', function (): void {
    $lookup = SupplierLookup::factory()->create([
        'result' => ['query' => 'OC90', 'variants' => array_fill(0, 200, ['brandName' => 'X'])],
    ]);

    $event = new SupplierResultReady($lookup);
    $payload = $event->broadcastWith();

    expect($event->broadcastAs())->toBe('lookup.ready')
        ->and($payload)->toBe([
            'lookup' => [
                'id' => $lookup->id,
                'supplier' => $lookup->supplier->value,
                'status' => $lookup->status->value,
            ],
        ])
        ->and($payload)->not->toHaveKey('result')
        ->and(mb_strlen(json_encode($payload) ?: ''))->toBeLessThan(500);
});

it('authorizes the run channel only for the owner', function (): void {
    // The `null` broadcaster used in tests (see phpunit.xml) short-circuits
    // channel authorization entirely, so switch to a real Pusher-protocol
    // broadcaster (Reverb) and re-register routes/channels.php against it
    // to exercise the actual owner-check closure.
    config(['broadcasting.default' => 'reverb']);
    require base_path('routes/channels.php');

    $owner = User::factory()->create();
    $run = SearchRun::factory()->for($owner)->create();

    $this->actingAs($owner)
        ->post('/broadcasting/auth', ['channel_name' => 'private-search-run.'.$run->id, 'socket_id' => '1234.5678'])
        ->assertOk();

    $this->actingAs(User::factory()->create())
        ->post('/broadcasting/auth', ['channel_name' => 'private-search-run.'.$run->id, 'socket_id' => '1234.5678'])
        ->assertForbidden();
});
