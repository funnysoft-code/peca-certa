<?php

declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum SearchRunKind: string
{
    case Identify = 'identify';
    case Parts = 'parts';
}
