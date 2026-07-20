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
    | jobs (emails, scout indexing, notifications). Horizon defines a
    | dedicated `supervisor-ai` listening on this queue — keep both in sync.
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
        'anthropic' => [
            'driver' => 'anthropic',
            'name' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
            'models' => [
                'text' => [
                    'default' => env('ANTHROPIC_DEFAULT_MODEL', 'claude-sonnet-4-6'),
                    'smartest' => env('ANTHROPIC_SMARTEST_MODEL', 'claude-opus-4-7'),
                ],
            ],
        ],

        'openai' => [
            'driver' => 'openai',
            'name' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'models' => [
                'text' => [
                    'default' => env('OPENAI_DEFAULT_MODEL', 'gpt-4o'),
                    'cheapest' => env('OPENAI_CHEAPEST_MODEL', 'gpt-4o-mini'),
                ],
                'image' => [
                    'default' => env('OPENAI_DEFAULT_IMAGE_MODEL', 'dall-e-3'),
                ],
            ],
        ],

        'xai' => [
            'driver' => 'xai',
            'name' => 'xai',
            'key' => env('XAI_API_KEY'),
            'models' => [
                'text' => [
                    'default' => env('XAI_DEFAULT_MODEL', 'grok-4.3'),
                    'cheapest' => env('XAI_CHEAPEST_MODEL', 'grok-4.3-fast'),
                ],
                'image' => [
                    'default' => env('XAI_DEFAULT_IMAGE_MODEL', 'grok-imagine-image'),
                ],
            ],
        ],
    ],

];
