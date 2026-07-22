<?php

declare(strict_types=1);

use App\Enums\SearchRunKind;
use App\Enums\SearchRunStatus;
use App\Enums\Supplier;
use App\Enums\SupplierLookupStatus;
use App\Jobs\PriceSupplierJob;
use App\Models\SearchRun;
use App\Models\User;
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
            ->has('recentRuns'),
        );
});

it("lists the user's last 5 parts runs newest first", function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $baseTime = now();

    SearchRun::factory()->for($user)->parts()->count(6)->sequence(
        ['reference' => 'ref-1', 'created_at' => $baseTime->copy()->subMinutes(6)],
        ['reference' => 'ref-2', 'created_at' => $baseTime->copy()->subMinutes(5)],
        ['reference' => 'ref-3', 'created_at' => $baseTime->copy()->subMinutes(4)],
        ['reference' => 'ref-4', 'created_at' => $baseTime->copy()->subMinutes(3)],
        ['reference' => 'ref-5', 'created_at' => $baseTime->copy()->subMinutes(2)],
        ['reference' => 'ref-6', 'created_at' => $baseTime->copy()->subMinute()],
    )->create();

    $this->actingAs($user)
        ->get('/parts')
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('parts/index')
            ->has('recentRuns', 5)
            ->where('recentRuns.0.reference', 'ref-6'),
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

it('shows the run page for the owner', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $run = SearchRun::factory()->for($user)->parts()->create();

    $this->actingAs($user)
        ->get(route('parts.show', $run))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('parts/show')
            ->where('run.id', $run->id),
        );
});

it("forbids showing another user's run", function (): void {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $run = SearchRun::factory()->for($owner)->parts()->create();
    $other = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($other)
        ->get(route('parts.show', $run))
        ->assertForbidden();
});

it('does not show identify runs on the parts show route', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $run = SearchRun::factory()->for($user)->create(['kind' => SearchRunKind::Identify]);

    $this->actingAs($user)
        ->get(route('parts.show', $run))
        ->assertNotFound();
});
