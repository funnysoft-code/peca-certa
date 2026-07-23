<?php

declare(strict_types=1);

namespace App\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Request;
use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Fetch available cost sources and rewrite docs/costs/costs.md + docs/costs/costs.json.
 *
 * @phpstan-type MonthRow array{
 *     from: string,
 *     to: string,
 *     partial: bool,
 *     updated_at: string,
 *     pl24: array{eur: float, source: string},
 *     xai: array<string, mixed>,
 *     cloudflare: array<string, mixed>,
 *     laravel_cloud: array<string, mixed>,
 *     total: array{eur: float, usd_eur_rate: float, complete: bool}
 * }
 */
final readonly class UpdateProjectCosts
{
    /**
     * @return array{months: list<string>, sources: array<string, array<string, mixed>>, total_eur: float|null}
     */
    public function execute(int $monthsBack = 6, bool $dryRun = false): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $rate = config()->float('costs.usd_eur_rate');
        $projectStart = config()->string('costs.project_start_month');
        $monthKeys = $this->monthKeys($monthsBack, $today, $projectStart);

        $existing = $this->loadExisting();
        /** @var array<string, array<string, mixed>> $months */
        $months = [];
        if (is_array($existing['months'] ?? null)) {
            foreach ($existing['months'] as $ym => $row) {
                if (is_string($ym) && $ym >= $projectStart && is_array($row)) {
                    $months[$ym] = $row;
                }
            }
        }

        $sources = [
            'laravel_cloud' => ['status' => 'pending'],
            'cloudflare' => ['status' => 'pending'],
            'xai' => ['status' => 'pending'],
        ];

        try {
            $lcByMonth = $this->fetchLaravelCloudByMonth($monthKeys, $today);
            $sources['laravel_cloud'] = ['status' => 'ok'];
        } catch (Throwable $throwable) {
            $lcByMonth = [];
            $sources['laravel_cloud'] = ['status' => 'error', 'reason' => $throwable->getMessage()];
        }

        $cfToken = $this->wranglerOauthToken();
        if ($cfToken === null) {
            $sources['cloudflare'] = [
                'status' => 'skipped',
                'reason' => 'No wrangler OAuth token (run bunx wrangler login in workers/zitania-browser)',
            ];
        } else {
            $sources['cloudflare'] = ['status' => 'ok'];
        }

        $hasManagement = $this->xaiManagementKey() !== null && $this->xaiTeamId() !== null;
        $hasLedger = File::exists(config()->string('costs.xai_ledger'));
        $sources['xai'] = match (true) {
            $hasManagement => [
                'status' => 'ok',
                'reason' => 'Management Billing API + local inference ledger',
            ],
            $hasLedger => [
                'status' => 'partial',
                'reason' => 'Inference ledger only (usage after capture started). Set XAI_MANAGEMENT_KEY + XAI_TEAM_ID to match console.x.ai',
            ],
            default => [
                'status' => 'empty',
                'reason' => 'No xAI costs yet. App will record cost_in_usd_ticks going forward; set XAI_MANAGEMENT_KEY + XAI_TEAM_ID for full console totals',
            ],
        };

        foreach ($monthKeys as $ym) {
            [$start, $end] = $this->monthBounds($ym);
            $partial = $ym === $today->format('Y-m');
            if ($partial) {
                $end = $today;
            }

            $pl24Eur = config()->float('costs.pl24.eur_per_month');

            $xai = $this->resolveXaiMonth($ym, $start, $end);
            $xaiUsd = isset($xai['usd']) && is_numeric($xai['usd']) ? (float) $xai['usd'] : null;

            if ($cfToken !== null) {
                try {
                    $cf = $this->fetchCloudflareMonth($start, $end, $cfToken, $partial);
                    $estimate = $cf['estimate_usd'] ?? null;
                    $cfUsd = is_array($estimate) && is_numeric($estimate['total'] ?? null)
                        ? (float) $estimate['total']
                        : null;
                } catch (Throwable $e) {
                    $cf = ['status' => 'error', 'reason' => $e->getMessage()];
                    $cfUsd = null;
                }
            } else {
                $cf = $sources['cloudflare'];
                $cfUsd = null;
            }

            $lc = $lcByMonth[$ym] ?? ['periods' => [], 'usd' => null];
            $lcUsd = is_numeric($lc['usd'] ?? null) ? (float) $lc['usd'] : null;
            if ($sources['laravel_cloud']['status'] === 'error') {
                $lcUsd = null;
            }

            $xaiEur = $this->eurFromUsd($xaiUsd, $rate);
            $cfEur = $this->eurFromUsd($cfUsd, $rate);
            $lcEur = $this->eurFromUsd($lcUsd, $rate);

            $total = $pl24Eur;
            foreach ([$xaiEur, $cfEur, $lcEur] as $part) {
                if ($part !== null) {
                    $total += $part;
                }
            }

            $months[$ym] = [
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
                'partial' => $partial,
                'updated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                'pl24' => ['eur' => $pl24Eur, 'source' => 'fixed'],
                'xai' => [
                    'usd' => $xaiUsd,
                    'eur' => $xaiEur,
                    ...$xai,
                ],
                'cloudflare' => [
                    'usd' => $cfUsd,
                    'eur' => $cfEur,
                    ...$cf,
                ],
                'laravel_cloud' => [
                    'usd' => $lcUsd,
                    'eur' => $lcEur,
                    'periods' => $lc['periods'],
                ],
                'total' => [
                    'eur' => round($total, 4),
                    'usd_eur_rate' => $rate,
                    'complete' => $xaiUsd !== null && $cfUsd !== null && $lcUsd !== null,
                ],
            ];
        }

        ksort($months);

        $payload = [
            'meta' => [
                'updated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                'usd_eur_rate' => $rate,
                'sources' => $sources,
                'config' => [
                    'pl24_eur' => config()->float('costs.pl24.eur_per_month'),
                    'laravel_cloud_app' => config()->string('costs.laravel_cloud.app_slug'),
                    'shared_allocation' => config()->float('costs.laravel_cloud.shared_allocation'),
                    'cloudflare_workers' => config()->array('costs.cloudflare.workers'),
                ],
            ],
            'months' => $months,
        ];

        if (! $dryRun) {
            $jsonPath = config()->string('costs.output.json');
            $mdPath = config()->string('costs.output.markdown');
            File::ensureDirectoryExists(dirname($jsonPath));
            File::put($jsonPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
            File::put($mdPath, $this->renderMarkdown($payload));
        }

        $latestYm = array_key_last($months);
        $totalEur = null;
        if (is_string($latestYm)) {
            $total = $months[$latestYm]['total'] ?? null;
            if (is_array($total) && is_numeric($total['eur'] ?? null)) {
                $totalEur = (float) $total['eur'];
            }
        }

        return [
            'months' => array_keys($months),
            'sources' => $sources,
            'total_eur' => $totalEur,
        ];
    }

    /**
     * @return list<string>
     */
    private function monthKeys(int $monthsBack, CarbonImmutable $today, string $projectStart): array
    {
        $keys = [];
        $cursor = $today->startOfMonth();

        for ($i = 0; $i < max(1, $monthsBack); $i++) {
            $ym = $cursor->format('Y-m');
            if ($ym >= $projectStart) {
                $keys[] = $ym;
            }

            $cursor = $cursor->subMonth();
        }

        sort($keys);

        if ($keys === []) {
            $keys[] = $projectStart;
        }

        return $keys;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function monthBounds(string $ym): array
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', $ym.'-01', 'UTC')?->startOfDay()
            ?? throw new RuntimeException('Invalid month key: '.$ym);

        return [$start, $start->endOfMonth()->startOfDay()];
    }

    private function eurFromUsd(?float $usd, float $rate): ?float
    {
        if ($usd === null) {
            return null;
        }

        // Keep sub-cent precision for small xAI amounts; round only in display helpers.
        return round($usd * $rate, 6);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadExisting(): array
    {
        $path = config()->string('costs.output.json');

        if (! File::exists($path)) {
            return ['months' => []];
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param  list<string>  $monthKeys
     * @return array<string, array{periods: list<array<string, mixed>>, usd: float|null}>
     */
    private function fetchLaravelCloudByMonth(array $monthKeys, CarbonImmutable $today): array
    {
        $cli = config()->string('costs.laravel_cloud.cli');
        $appId = config()->string('costs.laravel_cloud.app_id');
        $sharedAlloc = config()->float('costs.laravel_cloud.shared_allocation');
        /** @var list<string> $prefixes */
        $prefixes = array_values(config()->array('costs.laravel_cloud.shared_name_prefixes'));

        $periods = [];
        $seen = [];

        foreach (['current', '1', '2', '3'] as $period) {
            $result = Process::timeout(120)->run([$cli, 'usage', '--json', '--period='.$period]);

            if (! $result->successful()) {
                if ($period === 'current') {
                    throw new RuntimeException(mb_trim($result->errorOutput() ?: $result->output()) ?: 'cloud usage failed');
                }

                continue;
            }

            /** @var array<string, mixed> $data */
            $data = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);
            $summary = $this->summarizeLaravelCloudPeriod($data, $appId, $sharedAlloc, $prefixes);
            $key = implode('|', [
                $this->stringOr($summary['from'] ?? null, ''),
                $this->stringOr($summary['to'] ?? null, ''),
                $this->stringOr($summary['attributed_usd'] ?? null, ''),
                $this->stringOr($summary['dedicated_app_cents'] ?? null, ''),
            ]);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $periods[] = $summary;
        }

        $out = [];
        foreach ($monthKeys as $ym) {
            $out[$ym] = ['periods' => [], 'usd' => 0.0];
        }

        $currentYm = $today->format('Y-m');

        foreach ($periods as $period) {
            $attributed = is_numeric($period['attributed_usd'] ?? null) ? (float) $period['attributed_usd'] : 0.0;
            $dedicated = is_numeric($period['dedicated_app_cents'] ?? null) ? (int) $period['dedicated_app_cents'] : 0;

            if ($attributed === 0.0 && $dedicated === 0) {
                continue;
            }

            $ym = $this->parseLaravelCloudFromMonth(
                is_string($period['from'] ?? null) ? $period['from'] : null,
                $currentYm,
            );

            if (! isset($out[$ym])) {
                $periodIndex = is_numeric($period['period_index'] ?? null) ? (int) $period['period_index'] : -1;
                if ($periodIndex === 0 && isset($out[$currentYm])) {
                    $ym = $currentYm;
                } else {
                    continue;
                }
            }

            $out[$ym]['periods'][] = $period;
            $currentUsd = is_numeric($out[$ym]['usd'] ?? null) ? $out[$ym]['usd'] : 0.0;
            $out[$ym]['usd'] = round($currentUsd + $attributed, 4);
        }

        foreach ($out as $ym => $row) {
            if ($row['periods'] === []) {
                $out[$ym] = ['periods' => [], 'usd' => null];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $prefixes
     * @return array<string, mixed>
     */
    private function summarizeLaravelCloudPeriod(
        array $data,
        string $appId,
        float $sharedAlloc,
        array $prefixes,
    ): array {
        $periodIndex = is_numeric($data['period'] ?? null) ? (int) $data['period'] : 0;
        /** @var list<array{from?: string, to?: string}> $available */
        $available = is_array($data['availablePeriods'] ?? null) ? $data['availablePeriods'] : [];
        $label = $available[$periodIndex] ?? [];

        $dedicatedCents = 0;
        foreach (is_array($data['applications'] ?? null) ? $data['applications'] : [] as $app) {
            if (! is_array($app)) {
                continue;
            }

            if (($app['identifier'] ?? null) === $appId) {
                $dedicatedCents = is_numeric($app['totalCostCents'] ?? null) ? (int) $app['totalCostCents'] : 0;
                break;
            }
        }

        $sharedCents = 0;
        $breakdown = [];

        foreach (['databases', 'caches', 'websockets', 'buckets'] as $group) {
            foreach (is_array($data[$group] ?? null) ? $data[$group] : [] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $name = is_string($item['name'] ?? null) ? $item['name'] : null;
                if ($name === null) {
                    continue;
                }
                if (! $this->isSharedName($name, $prefixes)) {
                    continue;
                }

                if (! is_numeric($item['totalCents'] ?? null)) {
                    continue;
                }

                $cents = (int) $item['totalCents'];
                $sharedCents += $cents;
                $breakdown[] = ['type' => $group, 'name' => $name, 'total_cents' => $cents];
            }
        }

        $bandwidth = is_array($data['bandwidth'] ?? null) ? $data['bandwidth'] : [];
        if (is_numeric($bandwidth['costCents'] ?? null) && (int) $bandwidth['costCents'] > 0) {
            $bw = (int) $bandwidth['costCents'];
            $sharedCents += $bw;
            $breakdown[] = ['type' => 'bandwidth', 'name' => 'org_bandwidth', 'total_cents' => $bw];
        }

        $attributedCents = $dedicatedCents + (int) round($sharedCents * $sharedAlloc);

        return [
            'period_index' => $periodIndex,
            'from' => $label['from'] ?? null,
            'to' => $label['to'] ?? null,
            'currency' => $data['currency'] ?? 'USD',
            'org_spend_cents' => $data['currentSpendCents'] ?? null,
            'dedicated_app_cents' => $dedicatedCents,
            'shared_resources_cents' => $sharedCents,
            'shared_allocation' => $sharedAlloc,
            'attributed_cents' => $attributedCents,
            'attributed_usd' => $attributedCents / 100,
            'shared_breakdown' => $breakdown,
            'last_updated_at' => $data['lastUpdatedAt'] ?? null,
        ];
    }

    /**
     * @param  list<string>  $prefixes
     */
    private function isSharedName(string $name, array $prefixes): bool
    {
        return array_any($prefixes, fn (string $prefix): bool => str_starts_with($name, $prefix));
    }

    private function parseLaravelCloudFromMonth(?string $label, string $fallback): string
    {
        if ($label === null || $label === '') {
            return $fallback;
        }

        if (preg_match('/^([A-Za-z]+)\s+(\d{1,2})$/', mb_trim($label), $m) !== 1) {
            return $fallback;
        }

        $year = (int) CarbonImmutable::now()->year;
        $parsed = CarbonImmutable::createFromFormat('M j Y', sprintf('%s %s %d', $m[1], $m[2], $year));

        if (! $parsed instanceof CarbonImmutable) {
            return $fallback;
        }

        if ($parsed->greaterThan(CarbonImmutable::now()->addDays(60))) {
            $parsed = $parsed->subYear();
        }

        return $parsed->format('Y-m');
    }

    private function wranglerOauthToken(): ?string
    {
        $configured = config('costs.cloudflare.wrangler_config');
        $home = Request::server('HOME') ?? getenv('HOME');
        $home = is_string($home) ? $home : '';

        $path = is_string($configured) && $configured !== ''
            ? $configured
            : ($home === '' ? '' : $home.'/Library/Preferences/.wrangler/config/default.toml');

        if ($path === '' || ! File::exists($path)) {
            return null;
        }

        if (preg_match('/oauth_token\s*=\s*"([^"]+)"/', File::get($path), $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchCloudflareMonth(
        CarbonImmutable $start,
        CarbonImmutable $end,
        #[SensitiveParameter] string $token,
        bool $partial,
    ): array {
        $accountId = config()->string('costs.cloudflare.account_id');
        /** @var list<string> $workers */
        $workers = array_values(config()->array('costs.cloudflare.workers'));
        /** @var array<string, float|int> $pricing */
        $pricing = config()->array('costs.cloudflare.pricing');

        $startDt = $start->startOfDay()->toIso8601String();
        $endDt = $end->endOfDay()->toIso8601String();

        $perWorker = [];
        $totalRequests = 0;
        $totalDuration = 0.0;
        $totalWallUs = 0.0;
        $totalErrors = 0;
        $totalSubrequests = 0;

        foreach ($workers as $script) {
            $query = <<<GRAPHQL
            query {
              viewer {
                accounts(filter: {accountTag: "{$accountId}"}) {
                  workersInvocationsAdaptive(
                    limit: 1
                    filter: {
                      datetime_geq: "{$startDt}"
                      datetime_leq: "{$endDt}"
                      scriptName: "{$script}"
                    }
                  ) {
                    sum { requests errors subrequests duration wallTime }
                  }
                }
              }
            }
            GRAPHQL;

            $response = Http::withToken($token)
                ->timeout(60)
                ->acceptJson()
                ->post('https://api.cloudflare.com/client/v4/graphql', ['query' => $query]);

            if ($response->failed()) {
                $perWorker[] = ['script' => $script, 'error' => $response->body()];

                continue;
            }

            /** @var array<string, mixed> $json */
            $json = $response->json() ?? [];
            if (isset($json['errors'])) {
                $perWorker[] = ['script' => $script, 'error' => json_encode($json['errors'])];

                continue;
            }

            $rows = data_get($json, 'data.viewer.accounts.0.workersInvocationsAdaptive', []);
            $sum = [];
            if (is_array($rows) && isset($rows[0]) && is_array($rows[0]) && is_array($rows[0]['sum'] ?? null)) {
                /** @var array<string, mixed> $sum */
                $sum = $rows[0]['sum'];
            }

            $reqs = is_numeric($sum['requests'] ?? null) ? (int) $sum['requests'] : 0;
            $duration = is_numeric($sum['duration'] ?? null) ? (float) $sum['duration'] : 0.0;
            $wall = is_numeric($sum['wallTime'] ?? null) ? (float) $sum['wallTime'] : 0.0;
            $errors = is_numeric($sum['errors'] ?? null) ? (int) $sum['errors'] : 0;
            $sub = is_numeric($sum['subrequests'] ?? null) ? (int) $sum['subrequests'] : 0;

            $totalRequests += $reqs;
            $totalDuration += $duration;
            $totalWallUs += $wall;
            $totalErrors += $errors;
            $totalSubrequests += $sub;

            $perWorker[] = [
                'script' => $script,
                'requests' => $reqs,
                'errors' => $errors,
                'subrequests' => $sub,
                'duration_gb_s' => $duration,
                'wall_time_us' => $wall,
                'wall_time_hours' => round($wall / 1_000_000 / 3600, 6),
            ];
        }

        $billableReqs = max(0, $totalRequests - (int) ($pricing['included_requests'] ?? 0));
        $billableGbS = max(0.0, $totalDuration - (float) ($pricing['included_duration_gb_s'] ?? 0));
        $usageUsd = ($billableReqs / 1_000_000) * (float) ($pricing['usd_per_million_requests'] ?? 0)
            + ($billableGbS / 1_000_000) * (float) ($pricing['usd_per_million_gb_s'] ?? 0);

        $wallHours = $totalWallUs / 1_000_000 / 3600;
        $browserBillable = max(0.0, $wallHours - (float) ($pricing['browser_included_hours'] ?? 0));
        $browserUsd = $browserBillable * (float) ($pricing['usd_per_browser_hour'] ?? 0);

        $planUsd = config()->float('costs.cloudflare.workers_paid_plan_usd_per_month')
            * config()->float('costs.cloudflare.workers_plan_allocation');

        if ($totalRequests === 0 && ! $partial) {
            $planUsd = 0.0;
        }

        $totalUsd = round($usageUsd + $browserUsd + $planUsd, 4);

        return [
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'workers' => $perWorker,
            'totals' => [
                'requests' => $totalRequests,
                'errors' => $totalErrors,
                'subrequests' => $totalSubrequests,
                'duration_gb_s' => $totalDuration,
                'wall_time_hours' => round($wallHours, 6),
            ],
            'estimate_usd' => [
                'workers_usage' => round($usageUsd, 4),
                'browser_rendering' => round($browserUsd, 4),
                'paid_plan_share' => round($planUsd, 4),
                'total' => $totalUsd,
            ],
            'note' => 'Browser hours estimated from worker wallTime (approx for Playwright binding).',
        ];
    }

    /**
     * Prefer Management Billing (console totals); fall back to local inference ledger.
     *
     * @return array<string, mixed>
     */
    private function resolveXaiMonth(string $ym, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $ledger = $this->aggregateXaiLedgerMonth($start, $end);
        $management = $this->fetchXaiManagementMonth($ym, $start, $end);

        if (($management['status'] ?? null) === 'ok' && is_numeric($management['usd'] ?? null)) {
            $keyLabel = is_string($management['api_key_name'] ?? null)
                ? $management['api_key_name']
                : 'project key';

            return [
                'status' => 'ok',
                'source' => 'management_api',
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
                'usd' => round((float) $management['usd'], 6),
                'by_model' => $management['by_model'] ?? [],
                'api_key_id' => $management['api_key_id'] ?? null,
                'api_key_name' => $management['api_key_name'] ?? null,
                'ledger_usd' => $ledger['usd'] ?? 0.0,
                'ledger_requests' => $ledger['requests'] ?? 0,
                'ledger_ticks' => $ledger['ticks'] ?? 0,
                'note' => sprintf('Management Billing usage filtered to API key "%s" only (not whole team). Ledger is app-captured post-capture only.', $keyLabel),
            ];
        }

        $ledgerUsd = is_numeric($ledger['usd'] ?? null) ? (float) $ledger['usd'] : 0.0;
        $requests = is_numeric($ledger['requests'] ?? null) ? (int) $ledger['requests'] : 0;

        return [
            'status' => $requests > 0 ? 'partial' : ($ledger['status'] ?? 'empty'),
            'source' => 'inference_ledger',
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'usd' => round($ledgerUsd, 6),
            'ticks' => $ledger['ticks'] ?? 0,
            'requests' => $requests,
            'by_model' => $ledger['by_model'] ?? [],
            'management' => $management,
            'note' => 'App-captured usage.cost_in_usd_ticks only (starts when capture was enabled). Console totals need XAI_MANAGEMENT_KEY + XAI_TEAM_ID.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchXaiManagementMonth(string $ym, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $key = $this->xaiManagementKey();
        $teamId = $this->xaiTeamId();

        if ($key === null || $teamId === null) {
            return [
                'status' => 'skipped',
                'reason' => 'Set XAI_MANAGEMENT_KEY and XAI_TEAM_ID (console.x.ai → Settings)',
            ];
        }

        $resolvedKey = $this->resolveXaiApiKey($key, $teamId);
        if (($resolvedKey['status'] ?? null) !== 'ok') {
            return $resolvedKey;
        }

        if (! is_string($resolvedKey['api_key_id'] ?? null) || $resolvedKey['api_key_id'] === '') {
            return [
                'status' => 'error',
                'reason' => 'Resolved API key is missing api_key_id',
            ];
        }

        $apiKeyId = $resolvedKey['api_key_id'];
        $apiKeyName = is_string($resolvedKey['api_key_name'] ?? null)
            ? $resolvedKey['api_key_name']
            : null;

        $endExclusive = $end->addDay()->startOfDay();
        $timezone = config()->string('costs.xai.timezone', 'Europe/Lisbon');
        $base = mb_rtrim(config()->string('costs.xai.management_base_url', 'https://management-api.x.ai'), '/');

        $body = [
            'analyticsRequest' => [
                'timeRange' => [
                    'startTime' => $start->format('Y-m-d').' 00:00:00',
                    'endTime' => $endExclusive->format('Y-m-d').' 00:00:00',
                    'timezone' => $timezone,
                ],
                'timeUnit' => 'TIME_UNIT_MONTH',
                'values' => [
                    ['name' => 'usd', 'aggregation' => 'AGGREGATION_SUM'],
                ],
                'groupBy' => ['description'],
                // Filter syntax confirmed: api_key_id=<uuid> scopes to one key.
                'filters' => ['api_key_id='.$apiKeyId],
            ],
        ];

        try {
            $response = Http::withToken($key)
                ->timeout(60)
                ->acceptJson()
                ->post(sprintf('%s/v1/billing/teams/%s/usage', $base, $teamId), $body);

            if ($response->failed()) {
                return [
                    'status' => 'error',
                    'reason' => 'HTTP '.$response->status().': '.mb_substr($response->body(), 0, 300),
                ];
            }

            /** @var array<string, mixed> $data */
            $data = $response->json() ?? [];
            $series = is_array($data['timeSeries'] ?? null) ? $data['timeSeries'] : [];
            $total = 0.0;
            $breakdown = [];

            foreach ($series as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $label = 'unknown';
                if (is_array($entry['group'] ?? null) && isset($entry['group'][0]) && is_string($entry['group'][0])) {
                    $label = $entry['group'][0];
                } elseif (is_array($entry['groupLabels'] ?? null) && isset($entry['groupLabels'][0]) && is_string($entry['groupLabels'][0])) {
                    $label = $entry['groupLabels'][0];
                }

                $sum = 0.0;
                foreach (is_array($entry['dataPoints'] ?? null) ? $entry['dataPoints'] : [] as $point) {
                    if (! is_array($point)) {
                        continue;
                    }

                    $values = is_array($point['values'] ?? null) ? $point['values'] : [];
                    if (isset($values[0]) && is_numeric($values[0])) {
                        $sum += (float) $values[0];
                    }
                }

                if ($sum <= 0) {
                    continue;
                }

                $total += $sum;
                $breakdown[] = [
                    'model' => $label,
                    'usd' => round($sum, 6),
                ];
            }

            usort($breakdown, static fn (array $a, array $b): int => $b['usd'] <=> $a['usd']);

            return [
                'status' => 'ok',
                'usd' => round($total, 6),
                'by_model' => $breakdown,
                'month' => $ym,
                'api_key_id' => $apiKeyId,
                'api_key_name' => $apiKeyName,
            ];
        } catch (Throwable $throwable) {
            return [
                'status' => 'error',
                'reason' => $throwable->getMessage(),
            ];
        }
    }

    /**
     * Resolve the project API key id to filter billing usage.
     *
     * Priority: XAI_API_KEY_ID → name match (XAI_API_KEY_NAME) → suffix match of XAI_API_KEY.
     *
     * @return array<string, mixed>
     */
    private function resolveXaiApiKey(#[SensitiveParameter] string $managementKey, string $teamId): array
    {
        $configuredId = config('costs.xai.api_key_id');
        if (is_string($configuredId) && $configuredId !== '') {
            return [
                'status' => 'ok',
                'api_key_id' => $configuredId,
                'api_key_name' => config('costs.xai.api_key_name'),
            ];
        }

        $base = mb_rtrim(config()->string('costs.xai.management_base_url', 'https://management-api.x.ai'), '/');
        $wantedName = config('costs.xai.api_key_name');
        $wantedName = is_string($wantedName) && $wantedName !== '' ? $wantedName : 'peca-certa';

        $inferenceKey = config('ai.providers.xai.key');
        $suffix = is_string($inferenceKey) && mb_strlen($inferenceKey) >= 4
            ? mb_substr($inferenceKey, -4)
            : null;

        try {
            $response = Http::withToken($managementKey)
                ->timeout(60)
                ->acceptJson()
                ->get(sprintf('%s/auth/teams/%s/api-keys', $base, $teamId), ['pageSize' => 100]);

            if ($response->failed()) {
                return [
                    'status' => 'error',
                    'reason' => 'Failed to list API keys: HTTP '.$response->status(),
                ];
            }

            $keys = is_array($response->json('apiKeys')) ? $response->json('apiKeys') : [];
            $byName = null;
            $bySuffix = null;

            foreach ($keys as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $id = is_string($entry['apiKeyId'] ?? null) ? $entry['apiKeyId'] : null;
                $name = is_string($entry['name'] ?? null) ? $entry['name'] : null;
                $redacted = is_string($entry['redactedApiKey'] ?? null) ? $entry['redactedApiKey'] : null;
                if ($id === null) {
                    continue;
                }

                if ($name === $wantedName) {
                    $byName = ['api_key_id' => $id, 'api_key_name' => $name];
                }

                if ($suffix !== null && is_string($redacted) && str_ends_with($redacted, $suffix)) {
                    $bySuffix = ['api_key_id' => $id, 'api_key_name' => $name];
                }
            }

            $match = $byName ?? $bySuffix;
            if ($match === null) {
                return [
                    'status' => 'error',
                    'reason' => sprintf('Could not resolve project API key (looked for name "%s"). Set XAI_API_KEY_ID.', $wantedName),
                ];
            }

            return [
                'status' => 'ok',
                ...$match,
            ];
        } catch (Throwable $throwable) {
            return [
                'status' => 'error',
                'reason' => $throwable->getMessage(),
            ];
        }
    }

    private function xaiManagementKey(): ?string
    {
        $key = config('costs.xai.management_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function xaiTeamId(): ?string
    {
        $id = config('costs.xai.team_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Aggregate recorded inference costs for a calendar month.
     *
     * @return array<string, mixed>
     */
    private function aggregateXaiLedgerMonth(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $path = config()->string('costs.xai_ledger');

        if (! File::exists($path)) {
            return [
                'status' => 'empty',
                'reason' => 'No inference ledger yet',
                'usd' => 0.0,
                'requests' => 0,
                'ticks' => 0,
                'by_model' => [],
            ];
        }

        $endExclusive = $end->addDay()->startOfDay();
        $totalTicks = 0;
        $requests = 0;
        /** @var array<string, array{ticks: int, usd: float, requests: int}> $byModel */
        $byModel = [];

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return ['status' => 'error', 'reason' => 'Cannot read '.$path, 'usd' => null];
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = mb_trim($line);
                if ($line === '') {
                    continue;
                }

                /** @var array<string, mixed>|null $row */
                $row = json_decode($line, true);
                if (! is_array($row)) {
                    continue;
                }

                $at = is_string($row['recorded_at'] ?? null)
                    ? CarbonImmutable::parse($row['recorded_at'])->utc()
                    : null;
                if (! $at instanceof CarbonImmutable) {
                    continue;
                }
                if ($at->lt($start)) {
                    continue;
                }
                if ($at->gte($endExclusive)) {
                    continue;
                }

                $ticks = is_numeric($row['cost_in_usd_ticks'] ?? null) ? (int) $row['cost_in_usd_ticks'] : 0;
                $totalTicks += $ticks;
                $requests++;

                $model = is_string($row['model'] ?? null) && $row['model'] !== ''
                    ? $row['model']
                    : 'unknown';
                $byModel[$model] ??= ['ticks' => 0, 'usd' => 0.0, 'requests' => 0];
                $byModel[$model]['ticks'] += $ticks;
                $byModel[$model]['requests']++;
            }
        } finally {
            fclose($handle);
        }

        $ticksPerUsd = config()->integer('costs.xai.ticks_per_usd', 10_000_000_000);
        $usd = $ticksPerUsd > 0 ? $totalTicks / $ticksPerUsd : 0.0;

        $breakdown = [];
        foreach ($byModel as $model => $stats) {
            $modelUsd = $ticksPerUsd > 0 ? $stats['ticks'] / $ticksPerUsd : 0.0;
            $breakdown[] = [
                'model' => $model,
                'requests' => $stats['requests'],
                'usd' => round($modelUsd, 6),
            ];
        }

        usort($breakdown, static fn (array $a, array $b): int => $b['usd'] <=> $a['usd']);

        return [
            'status' => 'ok',
            'source' => 'inference_ledger',
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'usd' => round($usd, 6),
            'ticks' => $totalTicks,
            'requests' => $requests,
            'by_model' => $breakdown,
            'note' => 'Sum of usage.cost_in_usd_ticks from api.x.ai responses (this app, after capture enabled).',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderMarkdown(array $data): string
    {
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $rate = $this->floatOr($meta['usd_eur_rate'] ?? null, config()->float('costs.usd_eur_rate'));
        /** @var array<string, array<string, mixed>> $months */
        $months = [];
        if (is_array($data['months'] ?? null)) {
            foreach ($data['months'] as $ym => $row) {
                if (is_string($ym) && is_array($row)) {
                    $months[$ym] = $row;
                }
            }
        }

        krsort($months);

        $pl24 = config()->float('costs.pl24.eur_per_month');
        $appSlug = config()->string('costs.laravel_cloud.app_slug');
        $sharedPct = (int) (config()->float('costs.laravel_cloud.shared_allocation') * 100);
        /** @var list<string> $workers */
        $workers = array_values(array_map(
            static fn (mixed $w): string => is_string($w) ? $w : '',
            config()->array('costs.cloudflare.workers'),
        ));
        $workers = array_values(array_filter($workers, static fn (string $w): bool => $w !== ''));

        $planPct = (int) (config()->float('costs.cloudflare.workers_plan_allocation') * 100);
        $updatedAt = $this->stringOr($meta['updated_at'] ?? null, '');

        $lines = [];
        $lines[] = '# Project cost ledger';
        $lines[] = '';
        $lines[] = sprintf('_Last updated: %s (UTC). USD→EUR rate: **%s**. Generated by `php artisan costs:update`._', $updatedAt, $rate);
        $lines[] = '';
        $lines[] = '## What we track';
        $lines[] = '';
        $lines[] = '| Source | Rule | Auto-fetch |';
        $lines[] = '| --- | --- | --- |';
        $lines[] = sprintf('| **PartsLink24** | Fixed **%s €/month** | No (config) |', $pl24);
        $lines[] = '| **xAI** | Management Billing for this project API key only + local `cost_in_usd_ticks` ledger | Yes |';
        $lines[] = '| **Cloudflare** | Workers `'.implode(', ', $workers).sprintf('` usage + %d%% of Workers Paid plan base | Yes (wrangler login) |', $planPct);
        $lines[] = sprintf('| **Laravel Cloud** | 100%% of app `%s` + %d%% of shared `funnysoft_*` resources | Yes (`cloud` CLI) |', $appSlug, $sharedPct);
        $lines[] = '';
        $lines[] = '## Monthly summary (EUR)';
        $lines[] = '';
        $lines[] = '| Month | Status | PL24 | xAI | Cloudflare | Laravel Cloud | **Total** |';
        $lines[] = '| --- | --- | ---: | ---: | ---: | ---: | ---: |';

        foreach ($months as $ym => $m) {
            $total = is_array($m['total'] ?? null) ? $m['total'] : [];
            $status = ($m['partial'] ?? false) ? 'MTD' : 'full';
            if (! ($total['complete'] ?? false)) {
                $status .= ', partial data';
            }

            $pl24Row = is_array($m['pl24'] ?? null) ? $m['pl24'] : [];
            $xaiRow = is_array($m['xai'] ?? null) ? $m['xai'] : [];
            $cfRow = is_array($m['cloudflare'] ?? null) ? $m['cloudflare'] : [];
            $lcRow = is_array($m['laravel_cloud'] ?? null) ? $m['laravel_cloud'] : [];
            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s | %s | **%s** |',
                $ym,
                $status,
                $this->money($this->floatOrNull($pl24Row['eur'] ?? null)),
                $this->money($this->floatOrNull($xaiRow['eur'] ?? null)),
                $this->money($this->floatOrNull($cfRow['eur'] ?? null)),
                $this->money($this->floatOrNull($lcRow['eur'] ?? null)),
                $this->money($this->floatOrNull($total['eur'] ?? null)),
            );
        }

        $lines[] = '';
        $lines[] = '## Month detail';
        $lines[] = '';

        foreach ($months as $ym => $m) {
            $title = '### '.$ym;
            if ($m['partial'] ?? false) {
                $title .= ' (through '.$this->stringOr($m['to'] ?? null, '').')';
            }

            $total = is_array($m['total'] ?? null) ? $m['total'] : [];
            $pl24Row = is_array($m['pl24'] ?? null) ? $m['pl24'] : [];
            $lines[] = $title;
            $lines[] = '';
            $lines[] = '- **Total (EUR):** '.$this->money($this->floatOrNull($total['eur'] ?? null));
            $lines[] = '- **PL24:** '.$this->money($this->floatOrNull($pl24Row['eur'] ?? null)).' € (fixed)';

            $xai = is_array($m['xai'] ?? null) ? $m['xai'] : [];
            $xaiSource = $this->stringOr($xai['source'] ?? null, 'n/a');
            $xaiStatus = $this->stringOr($xai['status'] ?? null, 'n/a');
            if (in_array($xaiStatus, ['ok', 'partial'], true) || in_array($xaiSource, ['management_api', 'inference_ledger'], true)) {
                $reqLabel = isset($xai['requests'])
                    ? $this->stringOr($xai['requests'], '0').' ledger req'
                    : (isset($xai['ledger_requests'])
                        ? $this->stringOr($xai['ledger_requests'], '0').' ledger req'
                        : 'n/a');
                $lines[] = sprintf(
                    '- **xAI:** %s USD ≈ %s € (source: %s, %s)',
                    $this->moneyUsd($this->floatOrNull($xai['usd'] ?? null)),
                    $this->money($this->floatOrNull($xai['eur'] ?? null)),
                    $xaiSource,
                    $reqLabel,
                );
                if (isset($xai['ledger_usd']) && is_numeric($xai['ledger_usd'])) {
                    $lines[] = sprintf(
                        '  - app ledger (post-capture only): %s USD',
                        $this->moneyUsd((float) $xai['ledger_usd']),
                    );
                }

                if (is_string($xai['note'] ?? null) && $xai['note'] !== '') {
                    $lines[] = '  - note: '.$xai['note'];
                }

                foreach (is_array($xai['by_model'] ?? null) ? $xai['by_model'] : [] as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $reqPart = isset($row['requests'])
                        ? ' ('.$this->stringOr($row['requests'], '0').' req)'
                        : '';
                    $lines[] = sprintf(
                        '  - %s: %s USD%s',
                        $this->stringOr($row['model'] ?? null, 'unknown'),
                        $this->moneyUsd($this->floatOrNull($row['usd'] ?? null)),
                        $reqPart,
                    );
                }
            } else {
                $management = is_array($xai['management'] ?? null) ? $xai['management'] : [];
                $lines[] = sprintf(
                    '- **xAI:** %s (%s: %s)',
                    $this->moneyUsd($this->floatOrNull($xai['usd'] ?? null) ?? 0.0),
                    $xaiStatus,
                    $this->stringOr($xai['reason'] ?? ($management['reason'] ?? null), 'n/a'),
                );
            }

            $cf = is_array($m['cloudflare'] ?? null) ? $m['cloudflare'] : [];
            if (isset($cf['estimate_usd']) && is_array($cf['estimate_usd'])) {
                $est = $cf['estimate_usd'];
                $tot = is_array($cf['totals'] ?? null) ? $cf['totals'] : [];
                $lines[] = sprintf(
                    '- **Cloudflare:** %s USD ≈ %s €',
                    $this->money($this->floatOrNull($cf['usd'] ?? null)),
                    $this->money($this->floatOrNull($cf['eur'] ?? null)),
                );
                $lines[] = sprintf(
                    '  - requests=%s, wall≈%s h, plan share=%s USD, usage=%s USD, browser≈%s USD',
                    $this->stringOr($tot['requests'] ?? null, '0'),
                    $this->stringOr($tot['wall_time_hours'] ?? null, '0'),
                    $this->money($this->floatOrNull($est['paid_plan_share'] ?? null)),
                    $this->money($this->floatOrNull($est['workers_usage'] ?? null)),
                    $this->money($this->floatOrNull($est['browser_rendering'] ?? null)),
                );
            } else {
                $lines[] = sprintf(
                    '- **Cloudflare:** n/a (%s: %s)',
                    $this->stringOr($cf['status'] ?? null, 'n/a'),
                    $this->stringOr($cf['reason'] ?? null, 'n/a'),
                );
            }

            $lc = is_array($m['laravel_cloud'] ?? null) ? $m['laravel_cloud'] : [];
            $lcPeriods = is_array($lc['periods'] ?? null) ? $lc['periods'] : [];
            if ($lcPeriods !== []) {
                $lines[] = sprintf(
                    '- **Laravel Cloud:** %s USD ≈ %s € (app 100%% + shared %s%%)',
                    $this->money($this->floatOrNull($lc['usd'] ?? null)),
                    $this->money($this->floatOrNull($lc['eur'] ?? null)),
                    (string) $sharedPct,
                );
                foreach ($lcPeriods as $p) {
                    if (! is_array($p)) {
                        continue;
                    }

                    $lines[] = sprintf(
                        '  - period %s → %s: app %s¢ + shared %s¢ × %s = **%s USD**',
                        $this->stringOr($p['from'] ?? null, '?'),
                        $this->stringOr($p['to'] ?? null, '?'),
                        $this->stringOr($p['dedicated_app_cents'] ?? null, '0'),
                        $this->stringOr($p['shared_resources_cents'] ?? null, '0'),
                        $this->stringOr($p['shared_allocation'] ?? null, (string) ($sharedPct / 100)),
                        $this->stringOr($p['attributed_usd'] ?? null, '0'),
                    );
                }
            } else {
                $lines[] = '- **Laravel Cloud:** n/a (no periods mapped to this month)';
            }

            $lines[] = '';
        }

        $lines[] = '## How to refresh';
        $lines[] = '';
        $lines[] = '```bash';
        $lines[] = '# Prerequisites: cloud CLI auth, wrangler login (CF workers)';
        $lines[] = '# xAI costs accumulate automatically from inference responses.';
        $lines[] = 'php artisan costs:update';
        $lines[] = 'php artisan costs:update --dry-run';
        $lines[] = '```';
        $lines[] = '';
        $lines[] = 'Config: `config/costs.php`. Machine history: `docs/costs/costs.json`.';
        $lines[] = 'xAI ledger: `storage/app/private/costs/xai-usage.jsonl` (Inference API `cost_in_usd_ticks`).';
        $lines[] = 'xAI key-scoped totals: set `XAI_MANAGEMENT_KEY` + `XAI_TEAM_ID` (optional `XAI_API_KEY_ID` / `XAI_API_KEY_NAME=peca-certa`).';
        $lines[] = '';
        $lines[] = '## Notes';
        $lines[] = '';
        $lines[] = '- xAI Management Billing is filtered with `api_key_id=` for this project key only (not garagem85 / fisio-flow / apex-scout).';
        $lines[] = '- Local ledger only has calls made after capture was enabled in this app.';
        $lines[] = '- Each inference response includes `usage.cost_in_usd_ticks` (1 USD = 1e10 ticks). See https://docs.x.ai/developers/cost-tracking';
        $lines[] = '- Laravel Cloud billing windows are not calendar months; periods map to the calendar month of their start label.';
        $lines[] = '- Cloudflare Browser Rendering is estimated from worker wall time; real invoice may differ.';
        $lines[] = '';

        return implode(PHP_EOL, $lines);
    }

    private function money(?float $n): string
    {
        if ($n === null) {
            return 'n/a';
        }

        if (abs($n) > 0 && abs($n) < 0.01) {
            return number_format($n, 4, '.', ',');
        }

        return number_format($n, 2, '.', ',');
    }

    /**
     * xAI amounts are often sub-cent; keep enough precision to not show false 0.00.
     */
    private function moneyUsd(?float $n): string
    {
        if ($n === null) {
            return 'n/a';
        }

        if (abs($n) > 0 && abs($n) < 0.01) {
            return number_format($n, 6, '.', ',');
        }

        return number_format($n, 4, '.', ',');
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function floatOr(mixed $value, float $default): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }

    private function stringOr(mixed $value, string $default): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $default;
    }
}
