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
