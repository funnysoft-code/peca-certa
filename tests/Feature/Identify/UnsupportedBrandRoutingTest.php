<?php

declare(strict_types=1);

use App\Actions\ResumeIdentifyRun;
use App\Actions\RunIdentifyAgentTurn;
use App\Ai\Agents\IdentifyPartAgent;
use App\Ai\Tools\PartsLink24\ResolveBrand;
use App\Data\IdentifyClarification;
use App\Enums\SearchRunStatus;
use App\Jobs\IdentifyAgentJob;
use App\Jobs\PriceSupplierJob;
use App\Models\SearchRun;
use App\Models\User;
use App\Services\PartsLink24\VinBrandResolver;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Laravel\Ai\Tools\Request;

it('stops identify with unsupported_brand kind instead of model/year clarification', function (): void {
    Bus::fake([PriceSupplierJob::class]);
    IdentifyPartAgent::fake([
        [
            'status' => 'needs_input',
            'oeParts' => [],
            'question' => 'Qual o modelo e ano?',
            'options' => ['2020', '2021'],
            'confidence' => 0.2,
        ],
    ]);

    $run = SearchRun::factory()->create([
        'request_text' => 'turbo',
        'vin' => 'ZZZ99999999999999',
        'messages' => [],
        'status' => SearchRunStatus::Pending,
    ]);

    new IdentifyAgentJob($run)->handle(resolve(RunIdentifyAgentTurn::class));

    $run->refresh();

    expect($run->status)->toBe(SearchRunStatus::NeedsInput)
        ->and($run->pending_question['kind'] ?? null)->toBe(IdentifyClarification::KIND_UNSUPPORTED_BRAND)
        ->and($run->pending_question['question'] ?? '')->toContain('Catálogo PartsLink24')
        ->and($run->pending_question['question'] ?? '')->not->toContain('modelo nem ano?')
        ->and($run->pending_question['options'] ?? [])->toContain('opel', 'man', 'mini')
        ->and($run->lookups()->count())->toBe(0);

    // Agent must not have been invoked when WMI cannot route.
    IdentifyPartAgent::assertNeverPrompted();
    Bus::assertNotDispatched(PriceSupplierJob::class);
});

it('resumes unsupported brand with catalog override and can resolve tools', function (): void {
    Bus::fake([PriceSupplierJob::class, IdentifyAgentJob::class]);
    IdentifyPartAgent::fake([
        [
            'status' => 'selected',
            'oeParts' => [
                ['oeNumber' => '1234567890', 'description' => 'Left mirror', 'brand' => 'OE'],
            ],
            'question' => null,
            'options' => [],
            'confidence' => 0.9,
        ],
    ]);

    $run = SearchRun::factory()->create([
        'request_text' => 'espelho',
        'vin' => 'ZZZ99999999999999',
        'messages' => [],
        'status' => SearchRunStatus::Pending,
    ]);

    new IdentifyAgentJob($run)->handle(resolve(RunIdentifyAgentTurn::class));
    expect($run->refresh()->status)->toBe(SearchRunStatus::NeedsInput);

    resolve(ResumeIdentifyRun::class)->execute($run->fresh(), '', 'opel');

    $run->refresh();
    expect($run->status)->toBe(SearchRunStatus::Pending)
        ->and($run->brand_override)->toBe('opel')
        ->and($run->pending_question)->toBeNull();

    Bus::assertDispatched(IdentifyAgentJob::class);

    new IdentifyAgentJob($run)->handle(resolve(RunIdentifyAgentTurn::class));

    $run->refresh();
    expect($run->oe_parts)->toHaveCount(1)
        ->and($run->brand_override)->toBe('opel');
});

it('resolve_brand returns rich unsupported_brand payload with available brands', function (): void {
    Http::fake();
    $json = json_decode((string) resolve(ResolveBrand::class)->handle(new Request([
        'vin' => 'ZZZ99999999999999',
    ])), true);

    expect($json['ok'])->toBeFalse()
        ->and($json['error'])->toBe('unsupported_brand')
        ->and($json['message'] ?? '')->toContain('catalog')
        ->and($json['availableBrands'] ?? [])->toContain('man', 'opel');
    Http::assertNothingSent();
});

it('Opel VXK and MAN WMA resolve_brand without HTTP and without unsupported_brand', function (): void {
    Http::fake();

    $opel = json_decode((string) resolve(ResolveBrand::class)->handle(new Request([
        'vin' => 'VXKUBYHTKM4025404',
    ])), true);
    $man = json_decode((string) resolve(ResolveBrand::class)->handle(new Request([
        'vin' => 'WMA06XZZ8HM753386',
    ])), true);

    expect($opel['ok'])->toBeTrue()
        ->and($opel['brandKey'])->toBe('opel')
        ->and($opel['service'])->toBe('psa_opel_parts')
        ->and($opel['group'])->toBe('p5psa')
        ->and($man['ok'])->toBeTrue()
        ->and($man['brandKey'])->toBe('man')
        ->and($man['service'])->toBe('man_parts')
        ->and($man['group'])->toBe('p5man');

    Http::assertNothingSent();
});

it('brand override on SearchRun reaches the resolver path for tools', function (): void {
    $brand = resolve(VinBrandResolver::class)->resolve('ZZZ99999999999999', 'man');

    expect($brand?->service)->toBe('man_parts')->and($brand?->group)->toBe('p5man');
});

it('resume endpoint accepts catalog override for unsupported_brand kind', function (): void {
    Bus::fake([IdentifyAgentJob::class]);
    $user = User::factory()->create(['email_verified_at' => now()]);
    $run = SearchRun::factory()->for($user)->create([
        'status' => SearchRunStatus::NeedsInput,
        'vin' => 'ZZZ99999999999999',
        'pending_question' => [
            'question' => 'Catálogo PartsLink24 não configurado',
            'options' => ['opel', 'man'],
            'kind' => IdentifyClarification::KIND_UNSUPPORTED_BRAND,
        ],
        'messages' => [],
    ]);

    test()->actingAs($user)
        ->post(route('identify.resume', $run), ['option' => 'man', 'answer' => ''])
        ->assertRedirect(route('identify.show', $run));

    $run->refresh();
    expect($run->brand_override)->toBe('man')
        ->and($run->status)->toBe(SearchRunStatus::Pending)
        ->and($run->pending_question)->toBeNull();

    Bus::assertDispatched(IdentifyAgentJob::class);
});

it('resume endpoint rejects invalid catalog override with session error', function (): void {
    Bus::fake([IdentifyAgentJob::class]);
    $user = User::factory()->create(['email_verified_at' => now()]);
    $run = SearchRun::factory()->for($user)->create([
        'status' => SearchRunStatus::NeedsInput,
        'vin' => 'ZZZ99999999999999',
        'pending_question' => [
            'question' => 'Catálogo PartsLink24 não configurado',
            'options' => ['opel', 'man'],
            'kind' => IdentifyClarification::KIND_UNSUPPORTED_BRAND,
        ],
    ]);

    test()->actingAs($user)
        ->from(route('identify.show', $run))
        ->post(route('identify.resume', $run), ['option' => 'not-a-catalog', 'answer' => ''])
        ->assertRedirect(route('identify.show', $run))
        ->assertSessionHasErrors('option');

    expect($run->refresh()->brand_override)->toBeNull()
        ->and($run->status)->toBe(SearchRunStatus::NeedsInput);

    Bus::assertNotDispatched(IdentifyAgentJob::class);
});

it('resumes unsupported brand with free-text catalog key when option is empty', function (): void {
    Bus::fake([IdentifyAgentJob::class]);

    $run = SearchRun::factory()->create([
        'status' => SearchRunStatus::NeedsInput,
        'vin' => 'ZZZ99999999999999',
        'pending_question' => [
            'question' => 'Catálogo PartsLink24 não configurado',
            'options' => ['opel', 'man'],
            'kind' => IdentifyClarification::KIND_UNSUPPORTED_BRAND,
        ],
        'messages' => [],
    ]);

    resolve(ResumeIdentifyRun::class)->execute($run->fresh(), 'MAN');

    expect($run->refresh()->brand_override)->toBe('man')
        ->and($run->status)->toBe(SearchRunStatus::Pending);

    Bus::assertDispatched(IdentifyAgentJob::class);
});

it('resumes needs_input when pending_question is null without brand override path', function (): void {
    Bus::fake([IdentifyAgentJob::class]);

    $run = SearchRun::factory()->create([
        'status' => SearchRunStatus::NeedsInput,
        'pending_question' => null,
        'messages' => [],
    ]);

    resolve(ResumeIdentifyRun::class)->execute($run->fresh(), 'continuar');

    expect($run->refresh()->status)->toBe(SearchRunStatus::Pending)
        ->and($run->brand_override)->toBeNull()
        ->and($run->messages[0]['content'] ?? '')->toContain('continuar');

    Bus::assertDispatched(IdentifyAgentJob::class);
});

it('includes brand override in the agent prompt when forced catalog is set', function (): void {
    Bus::fake([PriceSupplierJob::class]);
    IdentifyPartAgent::fake([
        [
            'status' => 'selected',
            'oeParts' => [
                ['oeNumber' => '999', 'description' => 'Part', 'brand' => 'OE'],
            ],
            'question' => null,
            'options' => [],
            'confidence' => 0.9,
        ],
    ]);

    $run = SearchRun::factory()->create([
        'request_text' => 'turbo',
        'vin' => 'ZZZ99999999999999',
        'brand_override' => 'man',
        'messages' => [],
        'status' => SearchRunStatus::Pending,
    ]);

    new IdentifyAgentJob($run)->handle(resolve(RunIdentifyAgentTurn::class));

    $run->refresh();
    expect($run->messages[0]['content'] ?? '')->toContain('Catálogo PartsLink24 forçado pelo operador: man')
        ->and($run->oe_parts)->toHaveCount(1);
});

it('exposes unsupported_brand kind on the identify show payload', function (): void {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $run = SearchRun::factory()->for($user)->create([
        'status' => SearchRunStatus::NeedsInput,
        'vin' => 'ZZZ99999999999999',
        'pending_question' => [
            'question' => 'Catálogo PartsLink24 não configurado para este VIN',
            'options' => ['opel', 'man'],
            'kind' => IdentifyClarification::KIND_UNSUPPORTED_BRAND,
        ],
    ]);

    test()->actingAs($user)
        ->get(route('identify.show', $run))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('identify/show')
            ->where('run.pendingQuestion.kind', 'unsupported_brand')
            ->where('run.pendingQuestion.options.0', 'opel')
            ->where('run.status', 'needs_input'),
        );
});
