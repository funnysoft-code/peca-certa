<?php

declare(strict_types=1);

use App\Actions\ResumeIdentifyRun;
use App\Actions\RunIdentifyAgentTurn;
use App\Ai\Agents\IdentifyPartAgent;
use App\Enums\SearchRunStatus;
use App\Enums\Supplier;
use App\Events\SearchRunAdvanced;
use App\Jobs\IdentifyAgentJob;
use App\Jobs\PriceSupplierJob;
use App\Models\SearchRun;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Timeout as AiTimeout;

it('auto-prices selected OEs without clarification and never prices mid-exploration', function (): void {
    Bus::fake([PriceSupplierJob::class]);
    IdentifyPartAgent::fake([
        [
            'status' => 'selected',
            'oeParts' => [
                ['oeNumber' => '11427622446', 'description' => 'Oil filter element', 'brand' => 'OE'],
            ],
            'question' => null,
            'options' => [],
            'confidence' => 0.95,
        ],
    ]);

    $run = SearchRun::factory()->create([
        'request_text' => 'filtro de óleo',
        'vin' => 'WMWSU91010T717700',
        'messages' => [],
    ]);

    new IdentifyAgentJob($run)->handle(resolve(RunIdentifyAgentTurn::class));

    $run->refresh();

    expect($run->status)->toBe(SearchRunStatus::Running)
        ->and($run->oe_parts)->toHaveCount(1)
        ->and($run->oe_parts[0]['oeNumber'])->toBe('11427622446')
        ->and($run->pending_question)->toBeNull()
        ->and($run->lookups()->count())->toBe(2)
        ->and($run->lookups()->pluck('query')->unique()->all())->toBe(['11427622446']);

    Bus::assertDispatchedTimes(PriceSupplierJob::class, 2);
});

it('stops with needs_input and does not fan out pricing', function (): void {
    Bus::fake([PriceSupplierJob::class]);
    IdentifyPartAgent::fake([
        [
            'status' => 'needs_input',
            'oeParts' => [],
            'question' => 'É o filtro de óleo do motor ou da caixa?',
            'options' => ['Motor', 'Caixa'],
            'confidence' => 0.4,
        ],
    ]);

    $run = SearchRun::factory()->create([
        'request_text' => 'filtro',
        'vin' => 'WMWSU91010T717700',
        'messages' => [],
    ]);

    new IdentifyAgentJob($run)->handle(resolve(RunIdentifyAgentTurn::class));

    $run->refresh();

    expect($run->status)->toBe(SearchRunStatus::NeedsInput)
        ->and($run->pending_question['question'])->toContain('filtro')
        ->and($run->pending_question['options'])->toBe(['Motor', 'Caixa'])
        ->and($run->oe_parts)->toBeNull()
        ->and($run->lookups()->count())->toBe(0)
        ->and($run->messages)->toHaveCount(2);

    Bus::assertNotDispatched(PriceSupplierJob::class);
});

it('resumes the same run history and then prices final multi-part OEs', function (): void {
    Bus::fake([PriceSupplierJob::class, IdentifyAgentJob::class]);
    IdentifyPartAgent::fake([
        [
            'status' => 'needs_input',
            'oeParts' => [],
            'question' => 'Quais peças?',
            'options' => ['Ambas', 'Só filtro'],
            'confidence' => 0.3,
        ],
        [
            'status' => 'selected',
            'oeParts' => [
                ['oeNumber' => '11427622446', 'description' => 'Oil filter', 'brand' => 'OE'],
                ['oeNumber' => '34116761280', 'description' => 'Brake disc', 'brand' => 'OE'],
            ],
            'question' => null,
            'options' => [],
            'confidence' => 0.9,
        ],
    ]);

    $run = SearchRun::factory()->create([
        'request_text' => 'filtro e disco',
        'vin' => 'WMWSU91010T717700',
        'messages' => [],
    ]);

    new IdentifyAgentJob($run)->handle(resolve(RunIdentifyAgentTurn::class));
    expect($run->refresh()->status)->toBe(SearchRunStatus::NeedsInput);

    resolve(ResumeIdentifyRun::class)->execute($run->fresh(), 'Ambas as peças', 'Ambas');

    $run->refresh();
    expect($run->status)->toBe(SearchRunStatus::Pending);
    Bus::assertDispatched(IdentifyAgentJob::class);

    new IdentifyAgentJob($run)->handle(resolve(RunIdentifyAgentTurn::class));

    $run->refresh();

    expect($run->oe_parts)->toHaveCount(2)
        ->and($run->lookups()->count())->toBe(4)
        ->and($run->lookups()->where('supplier', Supplier::AutoDelta)->count())->toBe(2)
        ->and($run->pending_question)->toBeNull()
        ->and(collect($run->messages)->pluck('role')->all())->toContain('user', 'assistant');

    Bus::assertDispatchedTimes(PriceSupplierJob::class, 4);
});

it('no-ops when the run is already needs_input without resume', function (): void {
    Bus::fake([PriceSupplierJob::class]);
    IdentifyPartAgent::fake([
        ['status' => 'selected', 'oeParts' => [['oeNumber' => '1', 'description' => 'x', 'brand' => 'OE']], 'question' => null, 'options' => [], 'confidence' => 1],
    ]);

    $run = SearchRun::factory()->create(['status' => SearchRunStatus::NeedsInput]);

    new IdentifyAgentJob($run)->handle(resolve(RunIdentifyAgentTurn::class));

    expect($run->refresh()->oe_parts)->toBeNull();
    Bus::assertNotDispatched(PriceSupplierJob::class);
});

it('marks failed when the job exhausts retries', function (): void {
    Event::fake([SearchRunAdvanced::class]);
    $run = SearchRun::factory()->create(['status' => SearchRunStatus::Running]);

    new IdentifyAgentJob($run)->failed(new RuntimeException('boom'));

    expect($run->refresh()->status)->toBe(SearchRunStatus::Failed);
    Event::assertDispatched(SearchRunAdvanced::class);
});

it('enforces per-turn max tool steps and timeout via agent config and attributes', function (): void {
    config()->set([
        'identify.max_tool_steps' => 5,
        'identify.turn_timeout_seconds' => 42,
    ]);

    $agent = new IdentifyPartAgent([], 5, 42);

    expect($agent->maxSteps())->toBe(5)
        ->and($agent->timeout())->toBe(42);

    $reflection = new ReflectionClass(IdentifyPartAgent::class);
    $maxStepsAttr = $reflection->getAttributes(MaxSteps::class);
    $timeoutAttr = $reflection->getAttributes(AiTimeout::class);

    expect($maxStepsAttr)->not->toBeEmpty()
        ->and($timeoutAttr)->not->toBeEmpty()
        ->and($maxStepsAttr[0]->newInstance()->value)->toBeInt()->toBeGreaterThan(0)
        ->and($timeoutAttr[0]->newInstance()->value)->toBeInt()->toBeGreaterThan(0);
});

it('does not price when the agent returns needs_input even if oeParts sneaks in', function (): void {
    Bus::fake([PriceSupplierJob::class]);
    IdentifyPartAgent::fake([
        [
            'status' => 'needs_input',
            'oeParts' => [
                ['oeNumber' => '11427622446', 'description' => 'should not price', 'brand' => 'OE'],
            ],
            'question' => 'Confirma o lado?',
            'options' => ['Esquerdo', 'Direito'],
            'confidence' => 0.5,
        ],
    ]);

    $run = SearchRun::factory()->create([
        'request_text' => 'disco',
        'vin' => 'WMWSU91010T717700',
        'messages' => [],
    ]);

    new IdentifyAgentJob($run)->handle(resolve(RunIdentifyAgentTurn::class));

    expect($run->refresh()->status)->toBe(SearchRunStatus::NeedsInput)
        ->and($run->lookups()->count())->toBe(0);

    Bus::assertNotDispatched(PriceSupplierJob::class);
});
