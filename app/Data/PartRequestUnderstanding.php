<?php

declare(strict_types=1);

namespace App\Data;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class PartRequestUnderstanding implements JsonSerializable
{
    /**
     * @param  list<string>  $keywords
     */
    public function __construct(
        public string $category,
        public array $keywords,
        public ?string $clarifyingQuestion,
        public float $confidence,
    ) {}

    public function needsClarification(): bool
    {
        return $this->clarifyingQuestion !== null && $this->clarifyingQuestion !== '';
    }

    /**
     * @return array{category: string, keywords: list<string>, clarifyingQuestion: string|null, confidence: float}
     */
    public function jsonSerialize(): array
    {
        return [
            'category' => $this->category,
            'keywords' => $this->keywords,
            'clarifyingQuestion' => $this->clarifyingQuestion,
            'confidence' => $this->confidence,
        ];
    }
}
