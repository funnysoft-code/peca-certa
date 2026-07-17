<?php

declare(strict_types=1);

use App\Data\SearchRunData;
use App\Data\SupplierLookupData;
use App\Events\SearchRunAdvanced;
use App\Events\SupplierResultReady;
use App\Models\SearchRun;
use App\Models\SupplierLookup;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;

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

it('names the run advanced broadcast and serializes the run via the data DTO', function (): void {
    $run = SearchRun::factory()->create();
    SupplierLookup::factory()->for($run, 'run')->create();

    $event = new SearchRunAdvanced($run);

    expect($event->broadcastAs())->toBe('run.advanced')
        ->and($event->broadcastWith())->toEqual([
            'run' => SearchRunData::fromModel($run->load('lookups'))->jsonSerialize(),
        ]);
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

it('names the supplier result broadcast and serializes the lookup via the data DTO', function (): void {
    $lookup = SupplierLookup::factory()->create();

    $event = new SupplierResultReady($lookup);

    expect($event->broadcastAs())->toBe('lookup.ready')
        ->and($event->broadcastWith())->toEqual([
            'lookup' => SupplierLookupData::fromModel($lookup)->jsonSerialize(),
        ]);
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
