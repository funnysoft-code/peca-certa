<?php

declare(strict_types=1);

use App\Actions\RecordXaiInferenceCost;
use App\Actions\UpdateProjectCosts;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $base = storage_path('app/private/costs-test');
    File::deleteDirectory($base);
    File::ensureDirectoryExists($base);

    config([
        'costs.xai_ledger' => $base.'/xai-usage.jsonl',
        'costs.output.json' => $base.'/costs.json',
        'costs.output.markdown' => $base.'/costs.md',
        'costs.project_start_month' => '2026-07',
        'costs.usd_eur_rate' => 1.0,
        'costs.pl24.eur_per_month' => 23.0,
        'costs.laravel_cloud.app_id' => 'app-test',
        'costs.laravel_cloud.shared_allocation' => 0.25,
        'costs.laravel_cloud.shared_name_prefixes' => ['funnysoft_'],
        'costs.cloudflare.wrangler_config' => $base.'/missing-wrangler.toml',
    ]);
});

it('aggregates xai inference ledger and pl24 into the cost docs', function (): void {
    Process::fake([
        '*' => Process::result(errorOutput: 'cloud not available in test', exitCode: 1),
    ]);

    resolve(RecordXaiInferenceCost::class)->execute([
        'cost_in_usd_ticks' => 10_000_000_000,
        'model' => 'grok-4.3',
        'recorded_at' => now()->utc()->toIso8601String(),
    ]);

    $result = resolve(UpdateProjectCosts::class)->execute(monthsBack: 1, dryRun: false);

    expect($result['months'])->toContain(now()->format('Y-m'));
    expect(File::exists((string) config('costs.output.markdown')))->toBeTrue();
    expect(File::exists((string) config('costs.output.json')))->toBeTrue();

    $json = json_decode((string) File::get((string) config('costs.output.json')), true);
    $ym = now()->format('Y-m');

    expect((float) $json['months'][$ym]['pl24']['eur'])->toBe(23.0)
        ->and((float) $json['months'][$ym]['xai']['usd'])->toBe(1.0)
        ->and($json['months'][$ym]['xai']['requests'])->toBe(1)
        ->and((float) $json['months'][$ym]['total']['eur'])->toBe(24.0);

    $md = (string) File::get((string) config('costs.output.markdown'));
    expect($md)->toContain('php artisan costs:update')
        ->and($md)->toContain('cost_in_usd_ticks')
        ->and($md)->toContain('XAI_MANAGEMENT_KEY');
});

it('prefers key-scoped management billing totals over the local ledger', function (): void {
    Process::fake([
        '*' => Process::result(errorOutput: 'cloud not available in test', exitCode: 1),
    ]);

    config([
        'costs.xai.management_key' => 'mgmt-test',
        'costs.xai.team_id' => 'team-test',
        'costs.xai.api_key_id' => 'key-peca',
        'costs.xai.api_key_name' => 'peca-certa',
        'costs.xai.management_base_url' => 'https://management-api.x.ai',
    ]);

    Http::fake(function (Request $request) {
        expect($request->url())->toContain('/usage');
        $body = $request->data();
        expect(data_get($body, 'analyticsRequest.filters'))->toBe(['api_key_id=key-peca']);

        return Http::response([
            'timeSeries' => [
                [
                    'group' => ['API grok-4.3'],
                    'groupLabels' => ['API grok-4.3'],
                    'dataPoints' => [
                        ['timestamp' => now()->toIso8601String(), 'values' => [0.10]],
                    ],
                ],
            ],
            'limitReached' => false,
        ]);
    });

    resolve(RecordXaiInferenceCost::class)->execute([
        'cost_in_usd_ticks' => 1_000_000, // $0.0001
        'model' => 'grok-4.3',
        'recorded_at' => now()->utc()->toIso8601String(),
    ]);

    resolve(UpdateProjectCosts::class)->execute(monthsBack: 1, dryRun: false);

    $json = json_decode((string) File::get((string) config('costs.output.json')), true);
    $ym = now()->format('Y-m');

    expect((float) $json['months'][$ym]['xai']['usd'])->toBe(0.1)
        ->and($json['months'][$ym]['xai']['source'])->toBe('management_api')
        ->and($json['months'][$ym]['xai']['api_key_id'])->toBe('key-peca')
        ->and((float) $json['months'][$ym]['xai']['ledger_usd'])->toBe(0.0001);
});

it('supports dry-run without writing docs', function (): void {
    Process::fake([
        '*' => Process::result(errorOutput: 'cloud not available', exitCode: 1),
    ]);
    Http::fake();

    resolve(UpdateProjectCosts::class)->execute(monthsBack: 1, dryRun: true);

    expect(File::exists((string) config('costs.output.markdown')))->toBeFalse()
        ->and(File::exists((string) config('costs.output.json')))->toBeFalse();
});
