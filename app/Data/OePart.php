<?php

declare(strict_types=1);

namespace App\Data;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class OePart implements JsonSerializable
{
    public function __construct(
        public string $oeNumber,
        public string $description,
        public string $brand,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            oeNumber: is_string($data['oeNumber'] ?? null) ? $data['oeNumber'] : '',
            description: is_string($data['description'] ?? null) ? $data['description'] : '',
            brand: is_string($data['brand'] ?? null) ? $data['brand'] : '',
        );
    }

    /**
     * @return array{oeNumber: string, description: string, brand: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'oeNumber' => $this->oeNumber,
            'description' => $this->description,
            'brand' => $this->brand,
        ];
    }
}
