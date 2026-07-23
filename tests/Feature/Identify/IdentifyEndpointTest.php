<?php

declare(strict_types=1);

use App\Enums\SearchRunKind;
use App\Enums\SearchRunStatus;
use App\Jobs\IdentifyAgentJob;
use App\Jobs\IdentifyOePartsJob;
use App\Jobs\UnderstandRequestJob;
use App\Models\SearchRun;
use App\Models\User;
use App\Queries\ListSearchRunsQuery;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the identify page for authed users', function (): void {
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->get('/identify')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('identify/index')
            ->has('runs')
            ->has('filters')
            ->where('filters.scope', 'mine')
            ->where('filters.q', ''),
        );
});

it('redirects guests away from identify pages', function (): void {
    $this->get('/identify')->assertRedirect(route('login'));

    $run = SearchRun::factory()->create();

    $this->get(route('identify.show', $run))->assertRedirect(route('login'));
});

it('lists own identify runs by default, newest first, 10 per page', function (): void {
    $viewer = User::factory()->create(['email_verified_at' => now(), 'name' => 'Viewer User']);
    $author = User::factory()->create(['email_verified_at' => now(), 'name' => 'Author Alice']);
    $baseTime = now();

    SearchRun::factory()->for($author)->count(5)->create();

    SearchRun::factory()->for($viewer)->count(11)->sequence(
        ...collect(range(1, 11))->map(fn (int $i): array => [
            'request_text' => 'run '.$i,
            'created_at' => $baseTime->copy()->subMinutes(12 - $i),
        ])->all(),
    )->create();

    $this->actingAs($viewer)
        ->get('/identify')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('identify/index')
            ->has('runs.data', ListSearchRunsQuery::PER_PAGE)
            ->where('runs.meta.per_page', ListSearchRunsQuery::PER_PAGE)
            ->where('runs.meta.total', 11)
            ->where('runs.data.0.requestText', 'run 11')
            ->where('runs.data.0.authorName', 'Viewer User')
            ->where('filters.scope', 'mine'),
        );
});

it('limits the list to mine when scope=mine', function (): void {
    $userA = User::factory()->create(['email_verified_at' => now(), 'name' => 'User A']);
    $userB = User::factory()->create(['email_verified_at' => now(), 'name' => 'User B']);

    SearchRun::factory()->for($userA)->create(['request_text' => 'from A']);
    SearchRun::factory()->for($userB)->create(['request_text' => 'from B']);

    $this->actingAs($userB)
        ->get('/identify?scope=mine')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('identify/index')
            ->has('runs.data', 1)
            ->where('runs.data.0.requestText', 'from B')
            ->where('runs.data.0.authorName', 'User B')
            ->where('filters.scope', 'mine'),
        );
});

it('searches own identify runs by request text and vin', function (): void {
    $viewer = User::factory()->create(['email_verified_at' => now(), 'name' => 'Viewer']);
    $alice = User::factory()->create(['email_verified_at' => now(), 'name' => 'Alice Workshop']);

    SearchRun::factory()->for($alice)->create([
        'request_text' => 'filtro de oleo motor',
        'vin' => 'WVWZZZ1JZXW000001',
    ]);
    SearchRun::factory()->for($viewer)->create([
        'request_text' => 'pastilhas travao',
        'vin' => 'WBAZZZ1JZXW999999',
    ]);
    SearchRun::factory()->for($viewer)->create([
        'request_text' => 'outro pedido',
        'vin' => 'WVWZZZ1JZXW000001',
    ]);

    $this->actingAs($viewer)
        ->get('/identify?q=WVWZZZ1JZXW000001')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('runs.data', 1)
            ->where('runs.data.0.vin', 'WVWZZZ1JZXW000001')
            ->where('filters.q', 'WVWZZZ1JZXW000001'),
        );

    $this->actingAs($viewer)
        ->get('/identify?q=pastilhas')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('runs.data', 1)
            ->where('runs.data.0.requestText', 'pastilhas travao'),
        );
});

it('paginates identify runs with page query', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $baseTime = now();

    SearchRun::factory()->for($user)->count(11)->sequence(
        ...collect(range(1, 11))->map(fn (int $i): array => [
            'request_text' => 'page-run '.$i,
            'created_at' => $baseTime->copy()->subMinutes(12 - $i),
        ])->all(),
    )->create();

    $this->actingAs($user)
        ->get('/identify?page=2')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('runs.data', 1)
            ->where('runs.meta.current_page', 2)
            ->where('runs.meta.per_page', 10)
            ->where('runs.meta.last_page', 2)
            ->where('runs.data.0.requestText', 'page-run 1'),
        );
});

it('requires a vin', function (): void {
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->postJson('/identify', ['request' => 'filtro', 'vin' => ''])
        ->assertJsonValidationErrorFor('vin');
});

it('creates a search run, dispatches IdentifyAgentJob, and redirects to the run page', function (): void {
    Bus::fake();
    $user = User::factory()->create(['email_verified_at' => now()]);

    $response = $this->actingAs($user)
        ->post('/identify', ['request' => 'filtro de óleo', 'vin' => 'WVWZZZ1JZXW000001']);

    $run = SearchRun::query()->firstOrFail();

    expect($run->user_id)->toBe($user->id)
        ->and($run->kind)->toBe(SearchRunKind::Identify)
        ->and($run->request_text)->toBe('filtro de óleo')
        ->and($run->vin)->toBe('WVWZZZ1JZXW000001')
        ->and($run->status)->toBe(SearchRunStatus::Pending);

    $response->assertRedirect(route('identify.show', $run));

    Bus::assertDispatched(IdentifyAgentJob::class);
    Bus::assertNotDispatched(UnderstandRequestJob::class);
    Bus::assertNotDispatched(IdentifyOePartsJob::class);
});

it('shows the run page for the owner with author name', function (): void {
    $user = User::factory()->create(['email_verified_at' => now(), 'name' => 'Owner Name']);
    $run = SearchRun::factory()->for($user)->create();

    $this->actingAs($user)
        ->get(route('identify.show', $run))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('identify/show')
            ->where('run.id', $run->id)
            ->where('run.authorName', 'Owner Name'),
        );
});

it('forbids another regular user from opening an identify run', function (): void {
    $owner = User::factory()->create(['email_verified_at' => now(), 'name' => 'Owner Alice']);
    $run = SearchRun::factory()->for($owner)->create(['request_text' => 'shared identify']);
    $other = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($other)
        ->get(route('identify.show', $run))
        ->assertForbidden();
});

it('allows an admin to open another users identify run', function (): void {
    $owner = User::factory()->create(['email_verified_at' => now(), 'name' => 'Owner Alice']);
    $run = SearchRun::factory()->for($owner)->create(['request_text' => 'shared identify']);
    $admin = User::factory()->admin()->create(['email_verified_at' => now()]);

    $this->actingAs($admin)
        ->get(route('identify.show', $run))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('identify/show')
            ->where('run.id', $run->id)
            ->where('run.authorName', 'Owner Alice')
            ->where('run.requestText', 'shared identify'),
        );
});
