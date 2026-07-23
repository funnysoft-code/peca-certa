<?php

declare(strict_types=1);

use App\Enums\SearchRunKind;
use App\Enums\SearchRunStatus;
use App\Enums\SupplierLookupStatus;
use App\Jobs\IdentifyAgentJob;
use App\Jobs\IdentifyOePartsJob;
use App\Jobs\UnderstandRequestJob;
use App\Models\SearchRun;
use App\Models\SupplierLookup;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;

it('creates a search run and dispatches IdentifyAgentJob only (no blind understand chain)', function (): void {
    Bus::fake();
    $user = User::factory()->create(['email_verified_at' => now()]);

    $response = $this->actingAs($user)
        ->post('/identify', ['request' => 'filtro de óleo', 'vin' => 'WMWSU91010T717700']);

    $run = SearchRun::query()->firstOrFail();

    expect($run->user_id)->toBe($user->id)
        ->and($run->kind)->toBe(SearchRunKind::Identify)
        ->and($run->status)->toBe(SearchRunStatus::Pending)
        ->and($run->messages)->toBe([]);

    $response->assertRedirect(route('identify.show', $run));

    Bus::assertDispatched(IdentifyAgentJob::class);
    Bus::assertNotDispatched(UnderstandRequestJob::class);
    Bus::assertNotDispatched(IdentifyOePartsJob::class);
});

it('resumes a needs_input run with option and free text', function (): void {
    Bus::fake([IdentifyAgentJob::class]);
    $user = User::factory()->create(['email_verified_at' => now()]);
    $run = SearchRun::factory()->for($user)->create([
        'status' => SearchRunStatus::NeedsInput,
        'pending_question' => [
            'question' => 'Qual o lado?',
            'options' => ['Esquerdo', 'Direito'],
        ],
        'messages' => [
            ['role' => 'user', 'content' => 'VIN: x'],
            ['role' => 'assistant', 'content' => '{}', 'status' => 'needs_input'],
        ],
    ]);

    $this->actingAs($user)
        ->post(route('identify.resume', $run), [
            'option' => 'Esquerdo',
            'answer' => 'o da frente',
        ])
        ->assertRedirect(route('identify.show', $run));

    $run->refresh();

    expect($run->status)->toBe(SearchRunStatus::Pending)
        ->and($run->pending_question)->toBeNull()
        ->and($run->messages)->toHaveCount(3)
        ->and($run->messages[2]['role'])->toBe('user')
        ->and($run->messages[2]['content'])->toContain('Esquerdo')
        ->and($run->messages[2]['content'])->toContain('o da frente');

    Bus::assertDispatched(IdentifyAgentJob::class);
});

it('rejects resume without option or answer', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $run = SearchRun::factory()->for($user)->create([
        'status' => SearchRunStatus::NeedsInput,
        'pending_question' => ['question' => 'x', 'options' => []],
    ]);

    $this->actingAs($user)
        ->from(route('identify.show', $run))
        ->post(route('identify.resume', $run), ['answer' => '', 'option' => ''])
        ->assertRedirect(route('identify.show', $run))
        ->assertSessionHasErrors('answer');
});

it('forbids resuming another users run', function (): void {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $run = SearchRun::factory()->for($owner)->create(['status' => SearchRunStatus::NeedsInput]);
    $other = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($other)
        ->post(route('identify.resume', $run), ['answer' => 'ok'])
        ->assertForbidden();
});

it('exposes pendingQuestion on the show page payload', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $run = SearchRun::factory()->for($user)->create([
        'status' => SearchRunStatus::NeedsInput,
        'pending_question' => [
            'question' => 'Motor ou caixa?',
            'options' => ['Motor', 'Caixa'],
        ],
    ]);

    $this->actingAs($user)
        ->get(route('identify.show', $run))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('identify/show')
            ->where('run.status', 'needs_input')
            ->where('run.pendingQuestion.question', 'Motor ou caixa?')
            ->where('run.pendingQuestion.options', ['Motor', 'Caixa']),
        );
});

it('cancels a needs_input run and clears the pending question', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $run = SearchRun::factory()->for($user)->create([
        'status' => SearchRunStatus::NeedsInput,
        'pending_question' => [
            'question' => 'Motor ou caixa?',
            'options' => ['Motor', 'Caixa'],
        ],
        'messages' => [
            ['role' => 'user', 'content' => 'VIN: x'],
        ],
    ]);

    $this->actingAs($user)
        ->post(route('identify.cancel', $run))
        ->assertRedirect(route('identify.show', $run))
        ->assertSessionHas('status', 'Identificação cancelada.');

    $run->refresh();

    expect($run->status)->toBe(SearchRunStatus::Cancelled)
        ->and($run->pending_question)->toBeNull()
        ->and($run->messages)->toHaveCount(2)
        ->and($run->messages[1]['content'])->toContain('cancelou');
});

it('cancels mid-pricing runs and fails unfinished lookups', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $run = SearchRun::factory()->for($user)->create([
        'status' => SearchRunStatus::Running,
    ]);

    $pending = SupplierLookup::factory()->for($run, 'run')->create([
        'status' => SupplierLookupStatus::Pending,
        'query' => 'OC 93-pending',
    ]);

    $done = SupplierLookup::factory()->for($run, 'run')->create([
        'status' => SupplierLookupStatus::Done,
        'query' => 'OC 93-done',
    ]);

    $this->actingAs($user)
        ->post(route('identify.cancel', $run))
        ->assertRedirect(route('identify.show', $run));

    expect($run->refresh()->status)->toBe(SearchRunStatus::Cancelled)
        ->and($pending->refresh()->status)->toBe(SupplierLookupStatus::Failed)
        ->and($pending->error)->toContain('Cancelado')
        ->and($done->refresh()->status)->toBe(SupplierLookupStatus::Done);
});

it('rejects cancel when the run is already done', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $run = SearchRun::factory()->for($user)->create([
        'status' => SearchRunStatus::Done,
    ]);

    $this->actingAs($user)
        ->from(route('identify.show', $run))
        ->post(route('identify.cancel', $run))
        ->assertRedirect(route('identify.show', $run))
        ->assertSessionHasErrors('run');

    expect($run->refresh()->status)->toBe(SearchRunStatus::Done);
});

it('forbids cancelling another users run', function (): void {
    $owner = User::factory()->create(['email_verified_at' => now()]);
    $run = SearchRun::factory()->for($owner)->create([
        'status' => SearchRunStatus::NeedsInput,
    ]);
    $other = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($other)
        ->post(route('identify.cancel', $run))
        ->assertForbidden();
});
