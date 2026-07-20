<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum Supplier: string
{
    case AutoDelta = 'autodelta';
    case AutoZitania = 'autozitania';
}
