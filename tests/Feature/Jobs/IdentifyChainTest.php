<?php

declare(strict_types=1);

use App\Actions\FanOutOePricing;
use App\Actions\IdentifyOeParts;
use App\Actions\UnderstandPartRequest;
use App\Ai\Agents\PartRequestUnderstander;
use App\Data\OePart;
use App\Enums\SearchRunStatus;
use App\Enums\Supplier;
use App\Events\SearchRunAdvanced;
use App\Jobs\IdentifyOePartsJob;
use App\Jobs\PriceSupplierJob;
use App\Jobs\UnderstandRequestJob;
use App\Models\SearchRun;
use App\Services\PartsLink24\Contracts\PartsLink24Catalog;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

it('understands the request, identifies OE parts, and fans out a supplier lookup per part per supplier', function (): void {
    Bus::fake([PriceSupplierJob::class]);
    PartRequestUnderstander::fake([
        ['category' => 'filtro de óleo', 'searchTerm' => 'oil filter', 'keywords' => ['óleo'], 'clarifyingQuestion' => null, 'confidence' => 0.9],
    ]);
    $this->mock(PartsLink24Catalog::class)
        ->shouldReceive('resolveOeParts')
        ->andReturn([new OePart('11427622446', 'oil filter element', 'OE')]);

    $run = SearchRun::factory()->create(['request_text' => 'filtro de óleo', 'vin' => 'WMWSU91010T717700']);

    new UnderstandRequestJob($run)->handle(resolve(UnderstandPartRequest::class));
    new IdentifyOePartsJob($run)->handle(resolve(IdentifyOeParts::class), resolve(FanOutOePricing::class));

    $run->refresh();

    expect($run->understanding['searchTerm'])->toBe('oil filter')
        ->and($run->oe_parts)->toHaveCount(1)
        ->and($run->oe_parts[0]['oeNumber'])->toBe('11427622446')
        ->and($run->status)->toBe(SearchRunStatus::Running)
        ->and($run->lookups()->count())->toBe(2)
        ->and($run->lookups()->where('supplier', Supplier::AutoDelta)->count())->toBe(1)
        ->and($run->lookups()->where('supplier', Supplier::AutoZitania)->count())->toBe(1)
        ->and($run->lookups()->pluck('query')->unique()->all())->toBe(['11427622446'])
        ->and($run->lookups()->pluck('oe_description')->unique()->all())->toBe(['oil filter element']);

    Bus::assertDispatchedTimes(PriceSupplierJob::class, 2);
});

it('fans out N OE parts into 2N lookups and 2N dispatched jobs', function (): void {
    Bus::fake([PriceSupplierJob::class]);
    PartRequestUnderstander::fake([
        ['category' => 'filtro de óleo', 'searchTerm' => 'oil filter', 'keywords' => ['óleo'], 'clarifyingQuestion' => null, 'confidence' => 0.9],
    ]);
    $this->mock(PartsLink24Catalog::class)
        ->shouldReceive('resolveOeParts')
        ->andReturn([
            new OePart('11427622446', 'oil filter element', 'OE'),
            new OePart('11427566327', 'oil filter housing', 'OE'),
            new OePart('11421433083', 'oil filter gasket', 'OE'),
        ]);

    $run = SearchRun::factory()->create(['request_text' => 'filtro de óleo', 'vin' => 'WMWSU91010T717700']);

    new UnderstandRequestJob($run)->handle(resolve(UnderstandPartRequest::class));
    new IdentifyOePartsJob($run)->handle(resolve(IdentifyOeParts::class), resolve(FanOutOePricing::class));

    expect($run->refresh()->oe_parts)->toHaveCount(3)
        ->and($run->lookups()->count())->toBe(6);
    Bus::assertDispatchedTimes(PriceSupplierJob::class, 6);
});

it('sets the run to Done on clarification and the identify job no-ops', function (): void {
    Bus::fake([PriceSupplierJob::class]);
    PartRequestUnderstander::fake([
        ['category' => '', 'searchTerm' => '', 'keywords' => [], 'clarifyingQuestion' => 'Qual o carro?', 'confidence' => 0.1],
    ]);
    $catalog = $this->mock(PartsLink24Catalog::class);
    $catalog->shouldNotReceive('resolveOeParts');

    $run = SearchRun::factory()->create(['request_text' => 'uma peça']);

    new UnderstandRequestJob($run)->handle(resolve(UnderstandPartRequest::class));
    new IdentifyOePartsJob($run)->handle(resolve(IdentifyOeParts::class), resolve(FanOutOePricing::class));

    expect($run->refresh()->status)->toBe(SearchRunStatus::Done)
        ->and($run->lookups()->count())->toBe(0)
        ->and($run->oe_parts)->toBeNull();

    Bus::assertNotDispatched(PriceSupplierJob::class);
});

it('sets the run to Done when no OE parts are identified', function (): void {
    Bus::fake([PriceSupplierJob::class]);
    PartRequestUnderstander::fake([
        ['category' => 'peça rara', 'searchTerm' => 'rare part', 'keywords' => ['rara'], 'clarifyingQuestion' => null, 'confidence' => 0.9],
    ]);
    $this->mock(PartsLink24Catalog::class)
        ->shouldReceive('resolveOeParts')
        ->andReturn([]);

    $run = SearchRun::factory()->create(['request_text' => 'peça rara', 'vin' => 'WMWSU91010T717700']);

    new UnderstandRequestJob($run)->handle(resolve(UnderstandPartRequest::class));
    new IdentifyOePartsJob($run)->handle(resolve(IdentifyOeParts::class), resolve(FanOutOePricing::class));

    expect($run->refresh()->status)->toBe(SearchRunStatus::Done)
        ->and($run->oe_parts)->toBe([])
        ->and($run->lookups()->count())->toBe(0);

    Bus::assertNotDispatched(PriceSupplierJob::class);
});

it('marks the run Running and stores understanding before identify runs', function (): void {
    PartRequestUnderstander::fake([
        ['category' => 'filtro de óleo', 'searchTerm' => 'oil filter', 'keywords' => ['óleo'], 'clarifyingQuestion' => null, 'confidence' => 0.9],
    ]);

    $run = SearchRun::factory()->create(['request_text' => 'filtro de óleo']);

    new UnderstandRequestJob($run)->handle(resolve(UnderstandPartRequest::class));

    expect($run->refresh()->status)->toBe(SearchRunStatus::Running)
        ->and($run->understanding['category'])->toBe('filtro de óleo')
        ->and($run->understanding['searchTerm'])->toBe('oil filter');
});

it('does not identify when the run is already terminal (aborted mid-chain)', function (): void {
    Bus::fake([PriceSupplierJob::class]);
    $catalog = $this->mock(PartsLink24Catalog::class);
    $catalog->shouldNotReceive('resolveOeParts');

    $run = SearchRun::factory()->create([
        'status' => SearchRunStatus::Failed,
        'understanding' => ['category' => 'x', 'searchTerm' => 'x', 'keywords' => [], 'clarifyingQuestion' => null, 'confidence' => 0.9],
    ]);

    new IdentifyOePartsJob($run)->handle(resolve(IdentifyOeParts::class), resolve(FanOutOePricing::class));

    expect($run->refresh()->oe_parts)->toBeNull()
        ->and($run->lookups()->count())->toBe(0);

    Bus::assertNotDispatched(PriceSupplierJob::class);
});

it('no-ops when the run no longer exists in the database', function (): void {
    Bus::fake([PriceSupplierJob::class]);
    $catalog = $this->mock(PartsLink24Catalog::class);
    $catalog->shouldNotReceive('resolveOeParts');

    $run = SearchRun::factory()->create();
    SearchRun::query()->whereKey($run->id)->delete();

    new IdentifyOePartsJob($run)->handle(resolve(IdentifyOeParts::class), resolve(FanOutOePricing::class));

    Bus::assertNotDispatched(PriceSupplierJob::class);
});

it('serializes with the partslink24 WithoutOverlapping middleware and an expiry past the job timeout', function (): void {
    $run = SearchRun::factory()->make();

    $middleware = new IdentifyOePartsJob($run)->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->expiresAfter)->toBe(150);
});

it('flips a running run to Failed and broadcasts when UnderstandRequestJob exhausts retries', function (): void {
    Event::fake([SearchRunAdvanced::class]);

    $run = SearchRun::factory()->create(['status' => SearchRunStatus::Running]);

    new UnderstandRequestJob($run)->failed(new RuntimeException('boom'));

    expect($run->refresh()->status)->toBe(SearchRunStatus::Failed);

    Event::assertDispatched(SearchRunAdvanced::class, fn (SearchRunAdvanced $event): bool => $event->run->id === $run->id);
});

it('does not flip an already Done run when UnderstandRequestJob fails', function (): void {
    Event::fake([SearchRunAdvanced::class]);

    $run = SearchRun::factory()->create(['status' => SearchRunStatus::Done]);

    new UnderstandRequestJob($run)->failed(new RuntimeException('boom'));

    expect($run->refresh()->status)->toBe(SearchRunStatus::Done);

    Event::assertNotDispatched(SearchRunAdvanced::class);
});

it('no-ops when UnderstandRequestJob fails after the run was deleted from the database', function (): void {
    Event::fake([SearchRunAdvanced::class]);

    $run = SearchRun::factory()->create(['status' => SearchRunStatus::Running]);
    SearchRun::query()->whereKey($run->id)->delete();

    new UnderstandRequestJob($run)->failed(new RuntimeException('boom'));

    Event::assertNotDispatched(SearchRunAdvanced::class);
});

it('flips a running run to Failed and broadcasts when IdentifyOePartsJob exhausts retries', function (): void {
    Event::fake([SearchRunAdvanced::class]);

    $run = SearchRun::factory()->create(['status' => SearchRunStatus::Running]);

    new IdentifyOePartsJob($run)->failed(new RuntimeException('boom'));

    expect($run->refresh()->status)->toBe(SearchRunStatus::Failed);

    Event::assertDispatched(SearchRunAdvanced::class, fn (SearchRunAdvanced $event): bool => $event->run->id === $run->id);
});

it('does not flip an already Done run when IdentifyOePartsJob fails', function (): void {
    Event::fake([SearchRunAdvanced::class]);

    $run = SearchRun::factory()->create(['status' => SearchRunStatus::Done]);

    new IdentifyOePartsJob($run)->failed(new RuntimeException('boom'));

    expect($run->refresh()->status)->toBe(SearchRunStatus::Done);

    Event::assertNotDispatched(SearchRunAdvanced::class);
});

it('no-ops when IdentifyOePartsJob fails after the run was deleted from the database', function (): void {
    Event::fake([SearchRunAdvanced::class]);

    $run = SearchRun::factory()->create(['status' => SearchRunStatus::Running]);
    SearchRun::query()->whereKey($run->id)->delete();

    new IdentifyOePartsJob($run)->failed(new RuntimeException('boom'));

    Event::assertNotDispatched(SearchRunAdvanced::class);
});

it('is idempotent under retry: running the fan-out twice still yields exactly 2N lookups and 2N dispatches', function (): void {
    Bus::fake([PriceSupplierJob::class]);
    PartRequestUnderstander::fake([
        ['category' => 'filtro de óleo', 'searchTerm' => 'oil filter', 'keywords' => ['óleo'], 'clarifyingQuestion' => null, 'confidence' => 0.9],
    ]);
    $this->mock(PartsLink24Catalog::class)
        ->shouldReceive('resolveOeParts')
        ->twice()
        ->andReturn([
            new OePart('11427622446', 'oil filter element', 'OE'),
            new OePart('11427566327', 'oil filter housing', 'OE'),
        ]);

    $run = SearchRun::factory()->create(['request_text' => 'filtro de óleo', 'vin' => 'WMWSU91010T717700']);

    new UnderstandRequestJob($run)->handle(resolve(UnderstandPartRequest::class));

    new IdentifyOePartsJob($run)->handle(resolve(IdentifyOeParts::class), resolve(FanOutOePricing::class));
    new IdentifyOePartsJob($run)->handle(resolve(IdentifyOeParts::class), resolve(FanOutOePricing::class));

    expect($run->refresh()->status)->toBe(SearchRunStatus::Running)
        ->and($run->lookups()->count())->toBe(4);

    Bus::assertDispatchedTimes(PriceSupplierJob::class, 4);
});
