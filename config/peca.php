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
];
