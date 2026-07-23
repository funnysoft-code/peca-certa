<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Project cost ledger
    |--------------------------------------------------------------------------
    |
    | Used by `php artisan costs:update` to attribute shared infra and write
    | docs/costs/costs.md + docs/costs/costs.json. Amounts in original currency
    | are converted to EUR with usd_eur_rate for the monthly total column.
    |
    */

    'usd_eur_rate' => (float) env('COSTS_USD_EUR_RATE', 0.92),

    'project_start_month' => env('COSTS_PROJECT_START_MONTH', '2026-07'),

    'output' => [
        'markdown' => base_path('docs/costs/costs.md'),
        'json' => base_path('docs/costs/costs.json'),
    ],

    'xai_ledger' => storage_path('app/private/costs/xai-usage.jsonl'),

    'pl24' => [
        'label' => 'PartsLink24 subscription',
        'eur_per_month' => 23.0,
    ],

    'laravel_cloud' => [
        'app_id' => env('COSTS_LARAVEL_CLOUD_APP_ID', 'app-a251a036-9b53-4ae4-8b20-ed5ce39da147'),
        'app_slug' => env('COSTS_LARAVEL_CLOUD_APP_SLUG', 'r2cz-auto-finder'),
        'app_name' => 'R2CZ Auto Finder',
        'shared_allocation' => 0.25,
        'shared_name_prefixes' => ['funnysoft_'],
        'cli' => env('COSTS_LARAVEL_CLOUD_CLI', 'cloud'),
    ],

    'cloudflare' => [
        'account_id' => env('COSTS_CLOUDFLARE_ACCOUNT_ID', 'da3f40685e92d734477ba4afcf6ce727'),
        'workers' => ['zitania-browser'],
        'workers_paid_plan_usd_per_month' => 5.0,
        'workers_plan_allocation' => 0.25,
        'wrangler_config' => env('COSTS_WRANGLER_CONFIG'),
        'pricing' => [
            'included_requests' => 10_000_000,
            'included_duration_gb_s' => 30_000_000,
            'usd_per_million_requests' => 0.3,
            'usd_per_million_gb_s' => 0.0000125,
            'browser_included_hours' => 10.0,
            'usd_per_browser_hour' => 0.09,
        ],
    ],

    /*
    | xAI costs:
    | 1) Preferred: Management Billing usage API (matches console.x.ai totals).
    |    Requires XAI_MANAGEMENT_KEY + XAI_TEAM_ID from console settings.
    | 2) Fallback / detail: live inference ledger (usage.cost_in_usd_ticks) for
    |    calls made through this app after capture was enabled.
    |
    | See https://docs.x.ai/developers/cost-tracking
    | See https://docs.x.ai/developers/rest-api-reference/management/billing
    |
    | 1 USD = 10_000_000_000 ticks
    */
    'xai' => [
        'ticks_per_usd' => 10_000_000_000,
        'api_host' => 'api.x.ai',
        'management_base_url' => env('XAI_MANAGEMENT_BASE_URL', 'https://management-api.x.ai'),
        'management_key' => env('XAI_MANAGEMENT_KEY'),
        'team_id' => env('XAI_TEAM_ID'),
        // Scope Management Billing usage to this project key only (not whole team).
        'api_key_id' => env('XAI_API_KEY_ID'),
        'api_key_name' => env('XAI_API_KEY_NAME', 'peca-certa'),
        'timezone' => env('XAI_BILLING_TIMEZONE', 'Europe/Lisbon'),
    ],

];
