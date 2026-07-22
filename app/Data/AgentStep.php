<?php

declare(strict_types=1);

namespace App\Data;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class AgentStep implements JsonSerializable
{
    public function __construct(
        public string $id,
        public string $tool,
        public string $label,
        public string $status,
        public ?string $detail,
        public string $at,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: is_string($data['id'] ?? null) ? $data['id'] : '',
            tool: is_string($data['tool'] ?? null) ? $data['tool'] : '',
            label: is_string($data['label'] ?? null) ? $data['label'] : '',
            status: is_string($data['status'] ?? null) ? $data['status'] : 'running',
            detail: is_string($data['detail'] ?? null) ? $data['detail'] : null,
            at: is_string($data['at'] ?? null) ? $data['at'] : '',
        );
    }

    /**
     * @return array{id: string, tool: string, label: string, status: string, detail: string|null, at: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'tool' => $this->tool,
            'label' => $this->label,
            'status' => $this->status,
            'detail' => $this->detail,
            'at' => $this->at,
        ];
    }
}
