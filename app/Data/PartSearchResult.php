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
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $variants = is_array($data['variants'] ?? null) ? $data['variants'] : [];

        return new self(
            query: is_string($data['query'] ?? null) ? $data['query'] : '',
            variants: array_values(array_map(
                fn (mixed $variant): PartVariant => PartVariant::fromArray(is_array($variant) ? $variant : []),
                $variants,
            )),
            searchUrl: is_string($data['searchUrl'] ?? null) ? $data['searchUrl'] : null,
        );
    }

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
