<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class PartSearchResult
{
    /**
     * @param  list<PartVariant>  $variants
     */
    public function __construct(
        public string $query,
        public array $variants,
    ) {}
}
