<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use Spatie\LaravelTypeScriptTransformer\TypeScriptTransformerServiceProvider;

return array_filter([
    AppServiceProvider::class,
    FortifyServiceProvider::class,

    // Only register when the package is installed (--ts-transformer module).
    class_exists(TypeScriptTransformerServiceProvider::class)
        ? App\Providers\TypeScriptTransformerServiceProvider::class
        : null,
]);
