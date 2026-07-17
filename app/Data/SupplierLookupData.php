<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\Supplier;
use App\Enums\SupplierLookupStatus;
use App\Models\SupplierLookup;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class SupplierLookupData implements JsonSerializable
{
    public function __construct(
        public string $id,
        public Supplier $supplier,
        public string $query,
        public ?string $oeDescription,
        public SupplierLookupStatus $status,
        public ?PartSearchResult $result,
    ) {}

    public static function fromModel(SupplierLookup $lookup): self
    {
        return new self(
            id: $lookup->id,
            supplier: $lookup->supplier,
            query: $lookup->query,
            oeDescription: $lookup->oe_description,
            status: $lookup->status,
            result: $lookup->result === null ? null : PartSearchResult::fromArray($lookup->result),
        );
    }

    /**
     * @return array{id: string, supplier: Supplier, query: string, oeDescription: string|null, status: SupplierLookupStatus, result: PartSearchResult|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'supplier' => $this->supplier,
            'query' => $this->query,
            'oeDescription' => $this->oeDescription,
            'status' => $this->status,
            'result' => $this->result,
        ];
    }
}
