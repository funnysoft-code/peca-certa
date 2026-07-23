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
        public ?bool $factoryFit = null,
        public ?string $pos = null,
        public ?string $mainGroupId = null,
        public ?string $btnr = null,
        public ?string $diagramPath = null,
        public ?string $diagramUrl = null,
        public ?string $applicability = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $factoryFit = $data['factoryFit'] ?? null;

        return new self(
            oeNumber: is_string($data['oeNumber'] ?? null) ? $data['oeNumber'] : '',
            description: is_string($data['description'] ?? null) ? $data['description'] : '',
            brand: is_string($data['brand'] ?? null) ? $data['brand'] : '',
            factoryFit: is_bool($factoryFit) ? $factoryFit : null,
            pos: is_string($data['pos'] ?? null) ? $data['pos'] : null,
            mainGroupId: is_string($data['mainGroupId'] ?? null) ? $data['mainGroupId'] : null,
            btnr: is_string($data['btnr'] ?? null) ? $data['btnr'] : null,
            diagramPath: is_string($data['diagramPath'] ?? null) ? $data['diagramPath'] : null,
            diagramUrl: is_string($data['diagramUrl'] ?? null) ? $data['diagramUrl'] : null,
            applicability: is_string($data['applicability'] ?? null) ? $data['applicability'] : null,
        );
    }

    public function withDiagram(?string $path, ?string $url): self
    {
        return new self(
            oeNumber: $this->oeNumber,
            description: $this->description,
            brand: $this->brand,
            factoryFit: $this->factoryFit,
            pos: $this->pos,
            mainGroupId: $this->mainGroupId,
            btnr: $this->btnr,
            diagramPath: $path,
            diagramUrl: $url,
            applicability: $this->applicability,
        );
    }

    public function withCatalogMeta(
        ?bool $factoryFit = null,
        ?string $pos = null,
        ?string $mainGroupId = null,
        ?string $btnr = null,
        ?string $applicability = null,
    ): self {
        return new self(
            oeNumber: $this->oeNumber,
            description: $this->description,
            brand: $this->brand,
            factoryFit: $factoryFit ?? $this->factoryFit,
            pos: $pos ?? $this->pos,
            mainGroupId: $mainGroupId ?? $this->mainGroupId,
            btnr: $btnr ?? $this->btnr,
            diagramPath: $this->diagramPath,
            diagramUrl: $this->diagramUrl,
            applicability: $applicability ?? $this->applicability,
        );
    }

    /**
     * @return array{
     *     oeNumber: string,
     *     description: string,
     *     brand: string,
     *     factoryFit: bool|null,
     *     pos: string|null,
     *     mainGroupId: string|null,
     *     btnr: string|null,
     *     diagramPath: string|null,
     *     diagramUrl: string|null,
     *     applicability: string|null
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'oeNumber' => $this->oeNumber,
            'description' => $this->description,
            'brand' => $this->brand,
            'factoryFit' => $this->factoryFit,
            'pos' => $this->pos,
            'mainGroupId' => $this->mainGroupId,
            'btnr' => $this->btnr,
            'diagramPath' => $this->diagramPath,
            'diagramUrl' => $this->diagramUrl,
            'applicability' => $this->applicability,
        ];
    }
}
