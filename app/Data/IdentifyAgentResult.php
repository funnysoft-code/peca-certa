<?php

declare(strict_types=1);

namespace App\Data;

use JsonSerializable;

final readonly class IdentifyAgentResult implements JsonSerializable
{
    /**
     * @param  list<OePart>  $oeParts
     * @param  list<string>  $options
     */
    public function __construct(
        public string $status,
        public array $oeParts,
        public ?string $question,
        public array $options,
        public float $confidence,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $status = is_string($data['status'] ?? null) ? $data['status'] : 'failed';
        $question = is_string($data['question'] ?? null) ? $data['question'] : null;
        $optionsRaw = $data['options'] ?? [];
        $partsRaw = $data['oeParts'] ?? [];

        $oeParts = [];

        if (is_array($partsRaw)) {
            foreach ($partsRaw as $part) {
                if (! is_array($part)) {
                    continue;
                }

                $oe = OePart::fromArray($part);

                if ($oe->oeNumber !== '') {
                    $oeParts[] = $oe;
                }
            }
        }

        $options = [];

        if (is_array($optionsRaw)) {
            $options = array_values(array_filter(
                array_map(fn (mixed $v): string => is_string($v) ? $v : '', $optionsRaw),
                fn (string $v): bool => $v !== '',
            ));
        }

        return new self(
            status: $status,
            oeParts: $oeParts,
            question: $question === '' ? null : $question,
            options: $options,
            confidence: is_numeric($data['confidence'] ?? null) ? (float) $data['confidence'] : 0.0,
        );
    }

    public function needsInput(): bool
    {
        return $this->status === 'needs_input'
            && $this->question !== null
            && $this->question !== '';
    }

    public function hasSelectedParts(): bool
    {
        return $this->status === 'selected' && $this->oeParts !== [];
    }

    /**
     * @return array{status: string, oeParts: list<OePart>, question: string|null, options: list<string>, confidence: float}
     */
    public function jsonSerialize(): array
    {
        return [
            'status' => $this->status,
            'oeParts' => $this->oeParts,
            'question' => $this->question,
            'options' => $this->options,
            'confidence' => $this->confidence,
        ];
    }
}
