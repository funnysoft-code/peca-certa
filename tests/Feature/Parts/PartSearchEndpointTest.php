<?php

declare(strict_types=1);

use App\Enums\SearchRunKind;
use App\Enums\SearchRunStatus;
use App\Enums\Supplier;
use App\Enums\SupplierLookupStatus;
use App\Jobs\PriceSupplierJob;
use App\Models\SearchRun;
use App\Models\User;
use App\Queries\ListSearchRunsQuery;
use App\Services\AutoDelta\AutoDeltaToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the parts index page for authenticated users', function (): void {
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->get('/parts')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('parts/index')
            ->has('runs')
            ->has('filters')
            ->where('filters.scope', 'mine')
            ->where('filters.q', ''),
        );
});

it('redirects guests away from parts pages', function (): void {
    $this->get('/parts')->assertRedirect(route('login'));

    $run = SearchRun::factory()->parts()->create();

    $this->get(route('parts.show', $run))->assertRedirect(route('login'));
});

it('lists own parts runs by default, newest first, 10 per page', function (): void {
    $viewer = User::factory()->create(['email_verified_at' => now(), 'name' => 'Viewer User']);
    $author = User::factory()->create(['email_verified_at' => now(), 'name' => 'Author Alice']);
    $baseTime = now();

    SearchRun::factory()->for($author)->parts()->count(5)->create();

    SearchRun::factory()->for($viewer)->parts()->count(11)->sequence(
        ...collect(range(1, 11))->map(fn (int $i): array => [
            'reference' => 'ref-'.$i,
            'created_at' => $baseTime->copy()->subMinutes(12 - $i),
        ])->all(),
    )->create();

    $this->actingAs($viewer)
        ->get('/parts')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('parts/index')
            ->has('runs.data', ListSearchRunsQuery::PER_PAGE)
            ->where('runs.meta.per_page', ListSearchRunsQuery::PER_PAGE)
            ->where('runs.meta.total', 11)
            ->where('runs.data.0.reference', 'ref-11')
            ->where('runs.data.0.authorName', 'Viewer User')
            ->where('filters.scope', 'mine'),
        );
});

it('limits the parts list to mine when scope=mine', function (): void {
    $userA = User::factory()->create(['email_verified_at' => now(), 'name' => 'User A']);
    $userB = User::factory()->create(['email_verified_at' => now(), 'name' => 'User B']);

    SearchRun::factory()->for($userA)->parts()->create(['reference' => 'from-A']);
    SearchRun::factory()->for($userB)->parts()->create(['reference' => 'from-B']);

    $this->actingAs($userB)
        ->get('/parts?scope=mine')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('parts/index')
            ->has('runs.data', 1)
            ->where('runs.data.0.reference', 'from-B')
            ->where('runs.data.0.authorName', 'User B')
            ->where('filters.scope', 'mine'),
        );
});

it('searches own parts runs by reference', function (): void {
    $viewer = User::factory()->create(['email_verified_at' => now(), 'name' => 'Viewer']);
    $alice = User::factory()->create(['email_verified_at' => now(), 'name' => 'Alice Workshop']);

    SearchRun::factory()->for($alice)->parts()->create(['reference' => 'OC90']);
    SearchRun::factory()->for($viewer)->parts()->create(['reference' => 'W712/95']);
    SearchRun::factory()->for($viewer)->parts()->create(['reference' => 'OTHER']);

    $this->actingAs($viewer)
        ->get('/parts?q=W712')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('runs.data', 1)
            ->where('runs.data.0.reference', 'W712/95')
            ->where('filters.q', 'W712'),
        );
});

it('paginates parts runs with page query', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $baseTime = now();

    SearchRun::factory()->for($user)->parts()->count(11)->sequence(
        ...collect(range(1, 11))->map(fn (int $i): array => [
            'reference' => 'page-ref-'.$i,
            'created_at' => $baseTime->copy()->subMinutes(12 - $i),
        ])->all(),
    )->create();

    $this->actingAs($user)
        ->get('/parts?page=2')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('runs.data', 1)
            ->where('runs.meta.current_page', 2)
            ->where('runs.meta.per_page', 10)
            ->where('runs.meta.last_page', 2)
            ->where('runs.data.0.reference', 'page-ref-1'),
        );
});

it('requires auth for the store endpoint', function (): void {
    $this->post('/parts', ['reference' => 'OC90'])->assertRedirect(route('login'));
});

it('creates a parts run, dispatches supplier jobs, and redirects to the run page', function (): void {
    Queue::fake([PriceSupplierJob::class]);
    $user = User::factory()->create(['email_verified_at' => now()]);

    $response = $this->actingAs($user)
        ->post('/parts', ['reference' => 'OC 90']);

    $run = SearchRun::query()->firstOrFail();

    expect($run->user_id)->toBe($user->id)
        ->and($run->kind)->toBe(SearchRunKind::Parts)
        ->and($run->reference)->toBe('OC 90')
        ->and($run->status)->toBe(SearchRunStatus::Running)
        ->and($run->lookups)->toHaveCount(2)
        ->and($run->lookups->pluck('supplier')->all())->toEqualCanonicalizing([
            Supplier::AutoDelta,
            Supplier::AutoZitania,
        ])
        ->and($run->lookups->every(fn ($lookup): bool => $lookup->query === 'OC 90'
            && $lookup->status === SupplierLookupStatus::Pending))->toBeTrue();

    $response->assertRedirect(route('parts.show', $run));

    Queue::assertPushed(PriceSupplierJob::class, 2);
    Queue::assertPushedOn('autodelta', PriceSupplierJob::class);
    Queue::assertPushedOn('zitania', PriceSupplierJob::class);
});

it('runs supplier pricing when the queue is sync', function (): void {
    config()->set('suppliers.autodelta.catalog_url', 'https://cat.test/WebCat30WS');
    config()->set('suppliers.autodelta.search_url', 'https://cat.test/Tecdoc');
    config()->set('suppliers.autodelta.catalog_id', 'CAT');
    config()->set('suppliers.autodelta.provider', 1066);
    config()->set('suppliers.autozitania.username', 'user');
    config()->set('suppliers.autozitania.password', 'secret');
    Cache::put('autodelta.token', new AutoDeltaToken('KEY', 'USER', now()->addDay()), now()->addDay());

    $search = json_decode((string) file_get_contents(base_path('tests/Fixtures/AutoDelta/search-by-number.json')), true);
    $prices = json_decode((string) file_get_contents(base_path('tests/Fixtures/AutoDelta/trade-prices.json')), true);
    Http::fakeSequence('cat.test/*')->push($search['response'])->push($prices['response']);
    Process::fake(['*' => Process::result(output: (string) file_get_contents(base_path('tests/Fixtures/AutoZitania/search-output.json')))]);

    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)
        ->post('/parts', ['reference' => 'OC 90']);

    $run = SearchRun::query()->firstOrFail();
    $run->refresh();
    $run->load('lookups');

    expect($run->status)->toBe(SearchRunStatus::Done)
        ->and($run->lookups->firstWhere('supplier', Supplier::AutoDelta)?->status)
        ->toBe(SupplierLookupStatus::Done)
        ->and($run->lookups->firstWhere('supplier', Supplier::AutoZitania)?->status)
        ->toBe(SupplierLookupStatus::Done);
});

it('validates the reference is present', function (): void {
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->post('/parts', ['reference' => ''])
        ->assertSessionHasErrors('reference');
});

it('shows the run page for the owner with author name', function (): void {
    $user = User::factory()->create(['email_verified_at' => now(), 'name' => 'Owner Name']);
    $run = SearchRun::factory()->for($user)->parts()->create();

    $this->actingAs($user)
        ->get(route('parts.show', $run))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('parts/show')
            ->where('run.id', $run->id)
            ->where('run.authorName', 'Owner Name'),
        );
});

it('forbids another regular user from opening a parts run', function (): void {
    $owner = User::factory()->create(['email_verified_at' => now(), 'name' => 'Owner Alice']);
    $run = SearchRun::factory()->for($owner)->parts()->create(['reference' => 'SHARED-REF']);
    $other = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($other)
        ->get(route('parts.show', $run))
        ->assertForbidden();
});

it('allows an admin to open another users parts run', function (): void {
    $owner = User::factory()->create(['email_verified_at' => now(), 'name' => 'Owner Alice']);
    $run = SearchRun::factory()->for($owner)->parts()->create(['reference' => 'SHARED-REF']);
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

    $this->actingAs($admin)
        ->get(route('parts.show', $run))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('parts/show')
            ->where('run.id', $run->id)
            ->where('run.authorName', 'Owner Alice')
            ->where('run.reference', 'SHARED-REF'),
        );
});

it('does not show identify runs on the parts show route', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $run = SearchRun::factory()->for($user)->create(['kind' => SearchRunKind::Identify]);

    $this->actingAs($user)
        ->get(route('parts.show', $run))
        ->assertNotFound();
});
