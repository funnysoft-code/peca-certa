<?php

declare(strict_types=1);

use App\Actions\RecordIdentifyAgentStep;
use App\Actions\RunIdentifyAgentTurn;
use App\Ai\Agents\IdentifyPartAgent;
use App\Ai\Agents\PartRequestUnderstander;
use App\Ai\Tools\PartsLink24\SearchPartsByVin;
use App\Data\AgentStep;
use App\Data\SearchRunData;
use App\Enums\SearchRunStatus;
use App\Events\SearchRunAgentStep;
use App\Jobs\PriceSupplierJob;
use App\Listeners\RecordIdentifyAgentToolProgress;
use App\Models\SearchRun;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolInvoked;

it('records and broadcasts agent steps for a search run', function (): void {
    Event::fake([SearchRunAgentStep::class]);

    $run = SearchRun::factory()->create([
        'status' => SearchRunStatus::Running,
        'agent_steps' => null,
    ]);

    $action = resolve(RecordIdentifyAgentStep::class);

    $action->execute(
        runId: $run->id,
        stepId: 'step-1',
        tool: 'search_parts_by_vin',
        status: 'running',
        detail: 'oil filter',
    );

    $action->execute(
        runId: $run->id,
        stepId: 'step-1',
        tool: 'search_parts_by_vin',
        status: 'done',
        detail: 'oil filter',
    );

    $run->refresh();

    expect($run->agent_steps)->toHaveCount(1)
        ->and($run->agent_steps[0]['id'])->toBe('step-1')
        ->and($run->agent_steps[0]['status'])->toBe('done')
        ->and($run->agent_steps[0]['label'])->toBe('A pesquisar no catálogo OE…')
        ->and($run->agent_steps[0]['detail'])->toBe('oil filter');

    Event::assertDispatched(SearchRunAgentStep::class, 2);
    Event::assertDispatched(fn (SearchRunAgentStep $event): bool => $event->run->is($run)
        && $event->step instanceof AgentStep
        && $event->step->status === 'done'
        && $event->broadcastAs() === 'agent.step'
        && $event->broadcastWith()['runId'] === $run->id);
});

it('maps tool names to portuguese operator labels', function (): void {
    $labels = resolve(RecordIdentifyAgentStep::class);

    expect($labels->labelFor('resolve_brand'))->toBe('A resolver a marca…')
        ->and($labels->labelFor('decode_vin'))->toBe('A descodificar o VIN…')
        ->and($labels->labelFor('search_parts_by_vin'))->toBe('A pesquisar no catálogo OE…')
        ->and($labels->labelFor('list_main_groups'))->toBe('A listar grupos principais…')
        ->and($labels->labelFor('list_sub_groups'))->toBe('A listar subgrupos…')
        ->and($labels->labelFor('list_bom_parts'))->toBe('A listar peças do esquema…')
        ->and($labels->labelFor('get_part_info'))->toBe('A obter detalhe da peça…')
        ->and($labels->labelFor('unknown_tool'))->toBe('A executar ferramenta…')
        ->and($labels->detailFromArguments(['query' => 'brake disc']))->toBe('brake disc')
        ->and($labels->detailFromArguments(['vin' => 'WMWSU91010T717700']))->toBeNull();
});

it('no-ops when recording a step for a missing search run', function (): void {
    Event::fake([SearchRunAgentStep::class]);

    resolve(RecordIdentifyAgentStep::class)->execute(
        runId: '00000000-0000-4000-8000-000000000099',
        stepId: 'ghost',
        tool: 'decode_vin',
        status: 'running',
    );

    Event::assertNotDispatched(SearchRunAgentStep::class);
});

it('updates an existing step while leaving other steps untouched', function (): void {
    Event::fake([SearchRunAgentStep::class]);

    $run = SearchRun::factory()->create([
        'status' => SearchRunStatus::Running,
        'agent_steps' => [
            [
                'id' => 'step-a',
                'tool' => 'decode_vin',
                'label' => 'A descodificar o VIN…',
                'status' => 'done',
                'detail' => null,
                'at' => now()->toIso8601String(),
            ],
            [
                'id' => 'step-b',
                'tool' => 'search_parts_by_vin',
                'label' => 'A pesquisar no catálogo OE…',
                'status' => 'running',
                'detail' => 'oil',
                'at' => now()->toIso8601String(),
            ],
        ],
    ]);

    resolve(RecordIdentifyAgentStep::class)->execute(
        runId: $run->id,
        stepId: 'step-b',
        tool: 'search_parts_by_vin',
        status: 'done',
        detail: 'oil filter',
    );

    $run->refresh();

    expect($run->agent_steps)->toHaveCount(2)
        ->and($run->agent_steps[0]['id'])->toBe('step-a')
        ->and($run->agent_steps[1]['status'])->toBe('done')
        ->and($run->agent_steps[1]['detail'])->toBe('oil filter');
});

it('listener records steps only when context has the search run id', function (): void {
    Event::fake([SearchRunAgentStep::class]);

    $run = SearchRun::factory()->create(['status' => SearchRunStatus::Running]);
    $tool = resolve(SearchPartsByVin::class);
    $agent = new IdentifyPartAgent([]);
    $listener = resolve(RecordIdentifyAgentToolProgress::class);

    Context::add('identify.search_run_id', $run->id);

    try {
        $listener->handleInvoking(new InvokingTool(
            invocationId: 'inv-1',
            toolInvocationId: 'tool-call-1',
            agent: $agent,
            tool: $tool,
            arguments: ['vin' => 'WMWSU91010T717700', 'query' => 'oil filter'],
        ));

        $listener->handleInvoked(new ToolInvoked(
            invocationId: 'inv-1',
            toolInvocationId: 'tool-call-1',
            agent: $agent,
            tool: $tool,
            arguments: ['vin' => 'WMWSU91010T717700', 'query' => 'oil filter'],
            result: '{"ok":true}',
        ));
    } finally {
        Context::forget('identify.search_run_id');
    }

    $run->refresh();

    expect($run->agent_steps)->toHaveCount(1)
        ->and($run->agent_steps[0]['tool'])->toBe('search_parts_by_vin')
        ->and($run->agent_steps[0]['status'])->toBe('done')
        ->and($run->agent_steps[0]['detail'])->toBe('oil filter');

    Event::assertDispatched(SearchRunAgentStep::class, 2);
});

it('listener ignores tool events without search run context', function (): void {
    Event::fake([SearchRunAgentStep::class]);

    $run = SearchRun::factory()->create(['status' => SearchRunStatus::Running]);
    $tool = resolve(SearchPartsByVin::class);
    $listener = resolve(RecordIdentifyAgentToolProgress::class);

    Context::forget('identify.search_run_id');

    $listener->handleInvoking(new InvokingTool(
        invocationId: 'inv-1',
        toolInvocationId: 'tool-call-orphan',
        agent: new IdentifyPartAgent([]),
        tool: $tool,
        arguments: ['query' => 'oil filter'],
    ));

    $run->refresh();

    expect($run->agent_steps)->toBeNull();
    Event::assertNotDispatched(SearchRunAgentStep::class);
});

it('listener ignores tool events from non-identify agents', function (): void {
    Event::fake([SearchRunAgentStep::class]);

    $run = SearchRun::factory()->create(['status' => SearchRunStatus::Running]);
    $tool = resolve(SearchPartsByVin::class);
    $listener = resolve(RecordIdentifyAgentToolProgress::class);

    Context::add('identify.search_run_id', $run->id);

    try {
        $listener->handleInvoking(new InvokingTool(
            invocationId: 'inv-2',
            toolInvocationId: 'tool-call-other-agent',
            agent: new PartRequestUnderstander,
            tool: $tool,
            arguments: ['query' => 'oil filter'],
        ));
    } finally {
        Context::forget('identify.search_run_id');
    }

    $run->refresh();

    expect($run->agent_steps)->toBeNull();
    Event::assertNotDispatched(SearchRunAgentStep::class);
});

it('broadcasts agent steps on the private run channel with a tiny payload', function (): void {
    $run = SearchRun::factory()->create(['status' => SearchRunStatus::Running]);
    $step = new AgentStep(
        id: 'step-1',
        tool: 'search_parts_by_vin',
        label: 'A pesquisar no catálogo OE…',
        status: 'running',
        detail: 'oil filter',
        at: now()->toIso8601String(),
    );

    $event = new SearchRunAgentStep($run, $step);
    $channels = $event->broadcastOn();

    expect($event)
        ->toBeInstanceOf(ShouldBroadcastNow::class)
        ->toBeInstanceOf(ShouldRescue::class)
        ->and($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($channels[0]->name)->toBe('private-search-run.'.$run->id)
        ->and($event->broadcastAs())->toBe('agent.step')
        ->and($event->broadcastWith())->toMatchArray([
            'runId' => $run->id,
            'step' => $step->jsonSerialize(),
        ]);
});

it('clears agent steps at the start of an identify agent turn', function (): void {
    Bus::fake([PriceSupplierJob::class]);
    IdentifyPartAgent::fake([
        [
            'status' => 'selected',
            'oeParts' => [
                ['oeNumber' => '11427622446', 'description' => 'Oil filter', 'brand' => 'OE'],
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
        'agent_steps' => [
            [
                'id' => 'old',
                'tool' => 'search_parts_by_vin',
                'label' => 'old',
                'status' => 'done',
                'detail' => null,
                'at' => now()->toIso8601String(),
            ],
        ],
    ]);

    resolve(RunIdentifyAgentTurn::class)->execute($run);

    $run->refresh();

    expect($run->agent_steps)->toBe([])
        ->and($run->status)->toBe(SearchRunStatus::Running);
});

it('includes agent steps on SearchRunData for the show page', function (): void {
    $run = SearchRun::factory()->create([
        'agent_steps' => [
            [
                'id' => 's1',
                'tool' => 'search_parts_by_vin',
                'label' => 'A pesquisar no catálogo OE…',
                'status' => 'running',
                'detail' => 'oil filter',
                'at' => '2026-07-22T12:00:00+00:00',
            ],
        ],
    ]);

    $data = SearchRunData::fromModel($run->load(['lookups', 'user']));

    expect($data->agentSteps)->toHaveCount(1)
        ->and($data->agentSteps[0]->tool)->toBe('search_parts_by_vin')
        ->and($data->agentSteps[0]->detail)->toBe('oil filter')
        ->and($data->jsonSerialize()['agentSteps'][0]->detail)->toBe('oil filter');
});
