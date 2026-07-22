<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Findings list pagination
    |--------------------------------------------------------------------------
    |
    | Default and maximum page sizes for server-driven findings DataTables.
    | The UI exposes presets (15 / 25 / 50); the server clamps to max.
    |
    */
    'pagination_size' => (int) env('PECA_PAGINATION_SIZE', 25),
    'max_pagination_size' => (int) env('PECA_MAX_PAGINATION_SIZE', 50),

    /*
    |--------------------------------------------------------------------------
    | Orphaned search-run reaper
    |--------------------------------------------------------------------------
    |
    | Runs still pending/running whose updated_at is older than this many
    | minutes are closed by `search-runs:reap-orphaned` (scheduled). Tuned for
    | Laravel Cloud managed queues + slow Zitânia pricing (~90s/job), with headroom.
    |
    */
    'orphan_reap_after_minutes' => (int) env('PECA_ORPHAN_REAP_AFTER_MINUTES', 30),
];
