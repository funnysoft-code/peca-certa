<?php

declare(strict_types=1);

use App\Enums\SearchRunKind;
use App\Enums\SearchRunStatus;
use App\Http\Middleware\HandleInertiaRequests;
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
    SearchRun::factory()->for($user)->count(6)->sequence(
        ['request_text' => 'run 1'],
        ['request_text' => 'run 2'],
        ['request_text' => 'run 3'],
        ['request_text' => 'run 4'],
        ['request_text' => 'run 5'],
        ['request_text' => 'run 6'],
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

    // Task 9 builds the identify/show frontend page; this XHR-style Inertia
    // request avoids the full HTML render (and thus the Vite manifest lookup
    // for a page that does not exist yet) while still exercising the
    // controller's real Inertia response shape.
    $response = $this->actingAs($user)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => new HandleInertiaRequests()->version(request()),
        ])
        ->get(route('identify.show', $run));

    $response->assertOk();

    $page = $response->json();

    expect($page['component'])->toBe('identify/show')
        ->and($page['props']['run']['id'])->toBe($run->id);
});

it("forbids showing another user's run", function (): void {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $run = SearchRun::factory()->for($owner)->create();
    $other = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($other)
        ->get(route('identify.show', $run))
        ->assertForbidden();
});
