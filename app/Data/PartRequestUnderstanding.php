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
        public string $searchTerm,
        public array $keywords,
        public ?string $clarifyingQuestion,
        public float $confidence,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $clarifying = is_string($data['clarifyingQuestion'] ?? null) ? $data['clarifyingQuestion'] : null;

        return new self(
            category: is_string($data['category'] ?? null) ? $data['category'] : '',
            searchTerm: is_string($data['searchTerm'] ?? null) ? $data['searchTerm'] : '',
            keywords: self::toStringList($data['keywords'] ?? []),
            clarifyingQuestion: $clarifying === '' ? null : $clarifying,
            confidence: is_numeric($data['confidence'] ?? null) ? (float) $data['confidence'] : 0.0,
        );
    }

    public function needsClarification(): bool
    {
        return $this->clarifyingQuestion !== null && $this->clarifyingQuestion !== '';
    }

    /**
     * @return array{category: string, searchTerm: string, keywords: list<string>, clarifyingQuestion: string|null, confidence: float}
     */
    public function jsonSerialize(): array
    {
        return [
            'category' => $this->category,
            'searchTerm' => $this->searchTerm,
            'keywords' => $this->keywords,
            'clarifyingQuestion' => $this->clarifyingQuestion,
            'confidence' => $this->confidence,
        ];
    }

    /**
     * @return list<string>
     */
    private static function toStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $v): string => is_string($v) ? $v : '', $value),
            fn (string $v): bool => $v !== '',
        ));
    }
}
