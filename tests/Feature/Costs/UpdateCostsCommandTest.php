<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $base = storage_path('app/private/costs-command-test');
    File::deleteDirectory($base);
    File::ensureDirectoryExists($base);

    config([
        'costs.xai_ledger' => $base.'/xai-usage.jsonl',
        'costs.output.json' => $base.'/costs.json',
        'costs.output.markdown' => $base.'/costs.md',
        'costs.project_start_month' => '2026-07',
        'costs.cloudflare.wrangler_config' => $base.'/missing-wrangler.toml',
    ]);
});

it('runs costs:update successfully', function (): void {
    Process::fake([
        '*' => Process::result(errorOutput: 'cloud not available in test', exitCode: 1),
    ]);

    $this->artisan('costs:update', ['--months' => 1])
        ->assertSuccessful()
        ->expectsOutputToContain('Updated docs/costs/costs.md')
        ->expectsOutputToContain('Months:');

    expect(File::exists((string) config('costs.output.markdown')))->toBeTrue();
});

it('rejects non-positive months', function (): void {
    $this->artisan('costs:update', ['--months' => 0])
        ->assertFailed();
});
