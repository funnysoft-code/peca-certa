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
        public ?string $searchUrl = null,
    ) {}

    /**
     * @return array{query: string, variants: list<PartVariant>, searchUrl: string|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'query' => $this->query,
            'variants' => $this->variants,
            'searchUrl' => $this->searchUrl,
        ];
    }
}
