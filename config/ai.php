<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    */

    'default' => env('AI_DEFAULT_PROVIDER', 'xai'),

    'default_for_images' => env('AI_DEFAULT_IMAGE_PROVIDER', 'xai'),

    /*
    |--------------------------------------------------------------------------
    | Agent Queue
    |--------------------------------------------------------------------------
    |
    | Name of the queue that BroadcastAgent jobs are dispatched to. Isolated
    | from `default` so long-running streaming workloads do not starve fast
    | jobs (emails, scout indexing, notifications). Production uses a Laravel
    | Cloud managed queue named `ai` — keep both in sync.
    |
    */

    'queue' => env('AI_QUEUE', 'ai'),

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    */

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Fill API keys via .env. Add or remove providers as needed.
    |
    */

    'providers' => [
        'xai' => [
            'driver' => 'xai',
            'name' => 'xai',
            'key' => env('XAI_API_KEY'),
            // Priority Processing: higher scheduling priority (premium token rate).
            // See https://docs.x.ai/developers/advanced-api-usage/priority-processing
            'service_tier' => env('XAI_SERVICE_TIER', 'priority'),
            // Sticky prompt-cache keys (Responses API prompt_cache_key).
            // Identify runs use identify-run:{search_run_id} from the action, not config.
            'prompt_cache_keys' => [
                'part_request_understander' => env(
                    'XAI_PROMPT_CACHE_KEY_PART_REQUEST',
                    'peca-certa:part-request-understander',
                ),
            ],
            'models' => [
                'text' => [
                    'default' => env('XAI_DEFAULT_MODEL', 'grok-4.3'),
                    'cheapest' => env('XAI_CHEAPEST_MODEL', 'grok-4.3'),
                    'smartest' => env('XAI_SMARTEST_MODEL', 'grok-4.5'),
                ],
                'image' => [
                    'default' => env('XAI_DEFAULT_IMAGE_MODEL', 'grok-imagine-image'),
                ],
            ],
        ],
    ],

];
