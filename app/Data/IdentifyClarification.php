<?php

declare(strict_types=1);

namespace App\Data;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class IdentifyClarification implements JsonSerializable
{
    /**
     * @param  list<string>  $options
     */
    public function __construct(
        public string $question,
        public array $options,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $options = $data['options'] ?? [];

        return new self(
            question: is_string($data['question'] ?? null) ? $data['question'] : '',
            options: is_array($options)
                ? array_values(array_filter(
                    array_map(fn (mixed $v): string => is_string($v) ? $v : '', $options),
                    fn (string $v): bool => $v !== '',
                ))
                : [],
        );
    }

    /**
     * @return array{question: string, options: list<string>}
     */
    public function jsonSerialize(): array
    {
        return [
            'question' => $this->question,
            'options' => $this->options,
        ];
    }
}
