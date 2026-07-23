<?php

declare(strict_types=1);

use App\Actions\RecordIdentifyAgentStep;
use App\Enums\SearchRunStatus;
use App\Events\SearchRunAgentStep;
use App\Models\SearchRun;
use App\Support\RedactToolTrace;
use Illuminate\Support\Facades\Event;

it('persists redacted tool traces when a step completes with a result', function (): void {
    Event::fake([SearchRunAgentStep::class]);

    $run = SearchRun::factory()->create([
        'status' => SearchRunStatus::Running,
        'agent_steps' => null,
        'tool_traces' => null,
    ]);

    $action = resolve(RecordIdentifyAgentStep::class);

    $action->execute(
        runId: $run->id,
        stepId: 'step-1',
        tool: 'list_main_groups',
        status: 'running',
    );

    $action->execute(
        runId: $run->id,
        stepId: 'step-1',
        tool: 'list_main_groups',
        status: 'done',
        result: json_encode([
            'ok' => false,
            'error' => 'http_error',
            'status' => 500,
            'body' => 'access_token=super-secret-token eyJhbGciOiJSUzI1NiJ9.aaa.bbb',
        ], JSON_THROW_ON_ERROR),
    );

    $run->refresh();

    expect($run->tool_traces)->toHaveCount(1)
        ->and($run->tool_traces[0]['tool'])->toBe('list_main_groups')
        ->and($run->tool_traces[0]['result'])->toContain('http_error')
        ->and($run->tool_traces[0]['result'])->toContain('[redacted]')
        ->and($run->tool_traces[0]['result'])->toContain('[redacted-jwt]')
        ->and($run->tool_traces[0]['result'])->not->toContain('super-secret-token')
        ->and($run->agent_steps)->toHaveCount(1)
        ->and($run->agent_steps[0]['status'])->toBe('done');
});

it('redacts unserializable tool results safely', function (): void {
    $redactor = resolve(RedactToolTrace::class);

    // Resource cannot be json_encoded.
    $handle = fopen('php://memory', 'r');
    expect($redactor->execute($handle))->toBe('[unserializable tool result]');
    if (is_resource($handle)) {
        fclose($handle);
    }

    $long = str_repeat('a', 2500);
    expect(mb_strlen($redactor->execute($long)))->toBeLessThanOrEqual(2000);
});

it('retains tool_traces across agent_steps reset on a later turn', function (): void {
    Event::fake([SearchRunAgentStep::class]);

    $run = SearchRun::factory()->create([
        'status' => SearchRunStatus::NeedsInput,
        'tool_traces' => [
            [
                'id' => 'old',
                'tool' => 'resolve_brand',
                'result' => '{"ok":false,"error":"unsupported_brand"}',
                'at' => now()->toIso8601String(),
            ],
        ],
        'agent_steps' => [
            ['id' => 'old', 'tool' => 'resolve_brand', 'label' => 'x', 'status' => 'done', 'detail' => null, 'at' => now()->toIso8601String()],
        ],
    ]);

    // Simulate next turn clearing UI steps but not ops traces:
    $run->agent_steps = [];
    $run->save();

    resolve(RecordIdentifyAgentStep::class)->execute(
        runId: $run->id,
        stepId: 'new',
        tool: 'decode_vin',
        status: 'done',
        result: '{"ok":true,"resultStatus":"VEHICLE_IDENTIFIED"}',
    );

    $run->refresh();

    expect($run->tool_traces)->toHaveCount(2)
        ->and($run->tool_traces[0]['id'])->toBe('old')
        ->and($run->tool_traces[1]['tool'])->toBe('decode_vin')
        ->and($run->agent_steps)->toHaveCount(1);
});
