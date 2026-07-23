<?php

declare(strict_types=1);

use App\Actions\FinalizeFailedIdentifySteps;
use App\Models\SearchRun;

test('it maps session lock messages for operator detail', function (): void {
    $run = SearchRun::factory()->create([
        'agent_steps' => [
            ['id' => '1', 'status' => 'running', 'detail' => null],
        ],
        'tool_traces' => [],
    ]);

    $result = resolve(FinalizeFailedIdentifySteps::class)->execute(
        $run,
        new RuntimeException('USER_ALREADY_LOGGED_IN via squeezeOut'),
    );

    $steps = $result->agent_steps ?? [];
    expect($steps[0]['status'] ?? null)->toBe('failed')
        ->and($steps[0]['detail'] ?? '')->toContain('Catálogo OE ocupado');
});

test('it maps login 403 messages for operator detail', function (): void {
    $run = SearchRun::factory()->create([
        'agent_steps' => [
            ['id' => '1', 'status' => 'running', 'detail' => null],
        ],
        'tool_traces' => [],
    ]);

    $result = resolve(FinalizeFailedIdentifySteps::class)->execute(
        $run,
        new RuntimeException('POST /pl24-appgtw/ext/api/1.0/login returned 403 Forbidden'),
    );

    $steps = $result->agent_steps ?? [];
    expect($steps[0]['detail'] ?? '')->toContain('autenticação PartsLink24');
});
