<?php

declare(strict_types=1);

use App\Actions\SearchAutoDeltaParts;
use App\Actions\SearchAutoZitaniaParts;
use App\Enums\SearchRunStatus;
use App\Enums\Supplier;
use App\Enums\SupplierLookupStatus;
use App\Events\SearchRunAdvanced;
use App\Events\SupplierResultReady;
use App\Jobs\PriceSupplierJob;
use App\Models\SearchRun;
use App\Models\SupplierLookup;
use App\Services\AutoDelta\AutoDeltaToken;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

// SearchAutoDeltaParts / SearchAutoZitaniaParts are `final readonly class`
// (no interface), so Mockery/`$this->mock()` cannot subclass them. Tests
// fake the underlying HTTP / Process boundary instead, matching the
// convention already used in SearchAutoDeltaPartsTest / SearchAutoZitaniaPartsTest.

it('prices a lookup, stores the result, broadcasts, and completes the run when last', function (): void {
    Event::fake([SupplierResultReady::class, SearchRunAdvanced::class]);
    config()->set('suppliers.autodelta.catalog_url', 'https://cat.test/WebCat30WS');
    config()->set('suppliers.autodelta.search_url', 'https://cat.test/Tecdoc');
    config()->set('suppliers.autodelta.catalog_id', 'CAT');
    config()->set('suppliers.autodelta.provider', 1066);
    config()->set('suppliers.autodelta.webshop_url', 'https://shop.test/pt');
    Cache::put('autodelta.token', new AutoDeltaToken('KEY', 'USER', now()->addDay()), now()->addDay());
    $search = json_decode((string) file_get_contents(base_path('tests/Fixtures/AutoDelta/search-by-number.json')), true);
    $prices = json_decode((string) file_get_contents(base_path('tests/Fixtures/AutoDelta/trade-prices.json')), true);
    Http::fakeSequence('cat.test/*')->push($search['response'])->push($prices['response']);

    $run = SearchRun::factory()->create(['status' => SearchRunStatus::Running]);
    $lookup = SupplierLookup::factory()->for($run, 'run')->create(['supplier' => Supplier::AutoDelta, 'query' => 'OC90', 'status' => SupplierLookupStatus::Pending]);

    new PriceSupplierJob($lookup)->handle(resolve(SearchAutoDeltaParts::class), resolve(SearchAutoZitaniaParts::class));

    $lookup->refresh();
    $run->refresh();
    expect($lookup->status)->toBe(SupplierLookupStatus::Done)
        ->and($lookup->result['query'])->toBe('OC90')
        ->and($run->status)->toBe(SearchRunStatus::Done); // only lookup -> run completes
    Event::assertDispatched(SupplierResultReady::class);
    Event::assertDispatched(SearchRunAdvanced::class); // completion
});

it('marks the lookup empty when no variants come back', function (): void {
    config()->set('suppliers.autodelta.catalog_url', 'https://cat.test/WebCat30WS');
    config()->set('suppliers.autodelta.search_url', 'https://cat.test/Tecdoc');
    config()->set('suppliers.autodelta.catalog_id', 'CAT');
    config()->set('suppliers.autodelta.provider', 1066);
    Cache::put('autodelta.token', new AutoDeltaToken('KEY', 'USER', now()->addDay()), now()->addDay());
    Http::fake(['cat.test/*' => Http::response(['articles' => [], 'status' => 200])]);

    $run = SearchRun::factory()->create(['status' => SearchRunStatus::Running]);
    $lookup = SupplierLookup::factory()->for($run, 'run')->create(['supplier' => Supplier::AutoDelta, 'query' => 'NOPE']);

    new PriceSupplierJob($lookup)->handle(resolve(SearchAutoDeltaParts::class), resolve(SearchAutoZitaniaParts::class));

    expect($lookup->refresh()->status)->toBe(SupplierLookupStatus::Empty);
});

it('does not complete the run while a sibling lookup is still pending', function (): void {
    config()->set('suppliers.autodelta.catalog_url', 'https://cat.test/WebCat30WS');
    config()->set('suppliers.autodelta.search_url', 'https://cat.test/Tecdoc');
    config()->set('suppliers.autodelta.catalog_id', 'CAT');
    config()->set('suppliers.autodelta.provider', 1066);
    Cache::put('autodelta.token', new AutoDeltaToken('KEY', 'USER', now()->addDay()), now()->addDay());
    Http::fake(['cat.test/*' => Http::response(['articles' => [], 'status' => 200])]);

    $run = SearchRun::factory()->create(['status' => SearchRunStatus::Running]);
    $done = SupplierLookup::factory()->for($run, 'run')->create(['supplier' => Supplier::AutoDelta, 'query' => 'A']);
    SupplierLookup::factory()->for($run, 'run')->create(['supplier' => Supplier::AutoZitania, 'query' => 'A', 'status' => SupplierLookupStatus::Pending]);

    new PriceSupplierJob($done)->handle(resolve(SearchAutoDeltaParts::class), resolve(SearchAutoZitaniaParts::class));

    expect($run->refresh()->status)->toBe(SearchRunStatus::Running);
});

it('prices via the Auto Zitania action when the lookup supplier is Auto Zitania', function (): void {
    config()->set('suppliers.autozitania.username', 'user');
    config()->set('suppliers.autozitania.password', 'secret');
    config()->set('suppliers.autozitania.entry_url', 'https://portal.test/entry?11=102');

    $fixture = (string) file_get_contents(base_path('tests/Fixtures/AutoZitania/search-output.json'));
    Process::fake(['*' => Process::result(output: $fixture)]);

    $run = SearchRun::factory()->create(['status' => SearchRunStatus::Running]);
    $lookup = SupplierLookup::factory()->for($run, 'run')->create(['supplier' => Supplier::AutoZitania, 'query' => 'OC 90', 'status' => SupplierLookupStatus::Pending]);

    new PriceSupplierJob($lookup)->handle(resolve(SearchAutoDeltaParts::class), resolve(SearchAutoZitaniaParts::class));

    expect($lookup->refresh()->status)->toBe(SupplierLookupStatus::Done);
});

it('sets the correct queue and timeout for each supplier', function (): void {
    $autoDeltaLookup = SupplierLookup::factory()->make(['supplier' => Supplier::AutoDelta]);
    $autoZitaniaLookup = SupplierLookup::factory()->make(['supplier' => Supplier::AutoZitania]);

    $autoDeltaJob = new PriceSupplierJob($autoDeltaLookup);
    $autoZitaniaJob = new PriceSupplierJob($autoZitaniaLookup);

    $triesAttribute = new ReflectionClass(PriceSupplierJob::class)->getAttributes(Tries::class)[0]->newInstance();

    expect($autoDeltaJob->queue)->toBe('autodelta')
        ->and($autoDeltaJob->timeout)->toBe(30)
        ->and($autoZitaniaJob->queue)->toBe('zitania')
        ->and($autoZitaniaJob->timeout)->toBe(90)
        ->and($triesAttribute->tries)->toBe(2);
});

it('serializes without overlapping middleware for Auto Zitania only, with an expiry past the job timeout', function (): void {
    $autoDeltaLookup = SupplierLookup::factory()->make(['supplier' => Supplier::AutoDelta]);
    $autoZitaniaLookup = SupplierLookup::factory()->make(['supplier' => Supplier::AutoZitania]);

    $autoZitaniaMiddleware = new PriceSupplierJob($autoZitaniaLookup)->middleware();

    expect(new PriceSupplierJob($autoDeltaLookup)->middleware())->toBe([])
        ->and($autoZitaniaMiddleware)->toHaveCount(1)
        ->and($autoZitaniaMiddleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($autoZitaniaMiddleware[0]->expiresAfter)->toBe(150);
});

it('marks the lookup failed, broadcasts, and completes the run on failure', function (): void {
    Event::fake([SupplierResultReady::class, SearchRunAdvanced::class]);
    $run = SearchRun::factory()->create(['status' => SearchRunStatus::Running]);
    $lookup = SupplierLookup::factory()->for($run, 'run')->create(['supplier' => Supplier::AutoDelta, 'query' => 'OC 90', 'status' => SupplierLookupStatus::Pending]);

    new PriceSupplierJob($lookup)->failed(new RuntimeException('supplier timed out'));

    $lookup->refresh();
    $run->refresh();
    expect($lookup->status)->toBe(SupplierLookupStatus::Failed)
        ->and($lookup->error)->toBe('supplier timed out')
        ->and($run->status)->toBe(SearchRunStatus::Done);
    Event::assertDispatched(SupplierResultReady::class);
    Event::assertDispatched(SearchRunAdvanced::class);
});

it('does not complete the run on failure while a sibling lookup is still pending', function (): void {
    $run = SearchRun::factory()->create(['status' => SearchRunStatus::Running]);
    $failing = SupplierLookup::factory()->for($run, 'run')->create(['supplier' => Supplier::AutoDelta, 'query' => 'A']);
    SupplierLookup::factory()->for($run, 'run')->create(['supplier' => Supplier::AutoZitania, 'query' => 'A', 'status' => SupplierLookupStatus::Pending]);

    new PriceSupplierJob($failing)->failed(new RuntimeException('boom'));

    expect($run->refresh()->status)->toBe(SearchRunStatus::Running);
});

it('does not re-fire SearchRunAdvanced when the run is already terminal', function (): void {
    Event::fake([SupplierResultReady::class, SearchRunAdvanced::class]);
    $run = SearchRun::factory()->create(['status' => SearchRunStatus::Done]);
    $lookup = SupplierLookup::factory()->for($run, 'run')->create(['supplier' => Supplier::AutoDelta, 'query' => 'A', 'status' => SupplierLookupStatus::Pending]);

    new PriceSupplierJob($lookup)->failed(new RuntimeException('boom'));

    expect($run->refresh()->status)->toBe(SearchRunStatus::Done);
    Event::assertDispatched(SupplierResultReady::class);
    Event::assertNotDispatched(SearchRunAdvanced::class);
});
