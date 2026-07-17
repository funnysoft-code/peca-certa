<?php

declare(strict_types=1);

use App\Enums\SearchRunKind;
use App\Enums\SearchRunStatus;
use App\Jobs\IdentifyOePartsJob;
use App\Jobs\UnderstandRequestJob;
use App\Models\SearchRun;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the identify page for authed users', function (): void {
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->get('/identify')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('identify/index')
            ->has('recentRuns'),
        );
});

it("lists the user's last 5 identify runs newest first", function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $baseTime = now();

    // Explicit, strictly increasing created_at values: Pest.php freezes time
    // for every test, so factory-default timestamps would tie and make the
    // "newest first" ordering assertion flaky.
    SearchRun::factory()->for($user)->count(6)->sequence(
        ['request_text' => 'run 1', 'created_at' => $baseTime->copy()->subMinutes(6)],
        ['request_text' => 'run 2', 'created_at' => $baseTime->copy()->subMinutes(5)],
        ['request_text' => 'run 3', 'created_at' => $baseTime->copy()->subMinutes(4)],
        ['request_text' => 'run 4', 'created_at' => $baseTime->copy()->subMinutes(3)],
        ['request_text' => 'run 5', 'created_at' => $baseTime->copy()->subMinutes(2)],
        ['request_text' => 'run 6', 'created_at' => $baseTime->copy()->subMinute()],
    )->create();

    $this->actingAs($user)
        ->get('/identify')
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('identify/index')
            ->has('recentRuns', 5)
            ->where('recentRuns.0.requestText', 'run 6'),
        );
});

it('requires a vin', function (): void {
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->postJson('/identify', ['request' => 'filtro', 'vin' => ''])
        ->assertJsonValidationErrorFor('vin');
});

it('creates a search run, dispatches the job chain, and redirects to the run page', function (): void {
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

    Bus::assertChained([UnderstandRequestJob::class, IdentifyOePartsJob::class]);
});

it('shows the run page for the owner', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $run = SearchRun::factory()->for($user)->create();

    $this->actingAs($user)
        ->get(route('identify.show', $run))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('identify/show')
            ->where('run.id', $run->id),
        );
});

it("forbids showing another user's run", function (): void {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $run = SearchRun::factory()->for($owner)->create();
    $other = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($other)
        ->get(route('identify.show', $run))
        ->assertForbidden();
});
