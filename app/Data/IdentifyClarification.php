<?php

declare(strict_types=1);

namespace App\Data;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class IdentifyClarification implements JsonSerializable
{
    public const string KIND_UNSUPPORTED_BRAND = 'unsupported_brand';

    /**
     * @param  list<string>  $options
     */
    public function __construct(
        public string $question,
        public array $options,
        public ?string $kind = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $options = $data['options'] ?? [];
        $kind = $data['kind'] ?? null;

        return new self(
            question: is_string($data['question'] ?? null) ? $data['question'] : '',
            options: is_array($options)
                ? array_values(array_filter(
                    array_map(fn (mixed $v): string => is_string($v) ? $v : '', $options),
                    fn (string $v): bool => $v !== '',
                ))
                : [],
            kind: is_string($kind) && $kind !== '' ? $kind : null,
        );
    }

    public function isUnsupportedBrand(): bool
    {
        return $this->kind === self::KIND_UNSUPPORTED_BRAND;
    }

    /**
     * @return array{question: string, options: list<string>, kind: string|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'question' => $this->question,
            'options' => $this->options,
            'kind' => $this->kind,
        ];
    }
}
