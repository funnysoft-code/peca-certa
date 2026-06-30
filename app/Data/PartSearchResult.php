<?php

declare(strict_types=1);

namespace App\Data;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class PartSearchResult implements JsonSerializable
{
    /**
     * @param  list<PartVariant>  $variants
     */
    public function __construct(
        public string $query,
        public array $variants,
    ) {}

    /**
     * @return array{query: string, variants: list<PartVariant>}
     */
    public function jsonSerialize(): array
    {
        return [
            'query' => $this->query,
            'variants' => $this->variants,
        ];
    }
}
