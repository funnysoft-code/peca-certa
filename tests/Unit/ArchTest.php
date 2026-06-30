<?php

declare(strict_types=1);

use App\Http\Controllers\Controller;
use App\Http\Requests\Request;
use App\Providers\HorizonServiceProvider;
use App\Providers\TypeScriptTransformerServiceProvider;

// -------------------------------------------------------------------------
// Architecture presets
// -------------------------------------------------------------------------

arch()->preset()->php();
arch()->preset()->strict()->ignoring([
    Controller::class,
    Request::class,
    HorizonServiceProvider::class,
    TypeScriptTransformerServiceProvider::class,
]);
arch()->preset()->laravel()->ignoring([
    Request::class,
]);
arch()->preset()->security()->ignoring([
    'assert',
]);

// Controllers must never be called directly (Actions are the entry point for
// business logic; controllers only orchestrate request → Action → response).
arch('controllers')
    ->expect('App\Http\Controllers')
    ->not->toBeUsed()
    ->ignoring(Controller::class);

// -------------------------------------------------------------------------
// Doc-coverage: every app/ subdirectory containing PHP files must have a
// sibling CLAUDE.md so agents always have local context available.
// -------------------------------------------------------------------------

test('every app subdirectory with PHP files has a CLAUDE.md', function (): void {
    $appDir = base_path('app');
    $missing = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    /** @var SplFileInfo $item */
    foreach ($iterator as $item) {
        if (! $item->isDir()) {
            continue;
        }

        $dir = $item->getPathname();

        // Does this directory contain at least one PHP file directly?
        $phpFiles = glob($dir.'/*.php');
        if ($phpFiles === false) {
            continue;
        }

        if ($phpFiles === []) {
            continue;
        }

        // Require a CLAUDE.md (or AGENTS.md symlink — either satisfies intent).
        $hasDocs = file_exists($dir.'/CLAUDE.md') || file_exists($dir.'/AGENTS.md');

        if (! $hasDocs) {
            $missing[] = str_replace(base_path().'/', '', $dir);
        }
    }

    expect($missing)->toBeEmpty(
        'These app/ subdirectories contain PHP files but are missing a CLAUDE.md: '
        .implode(', ', $missing),
    );
});
