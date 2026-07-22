<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\SearchRunKind;
use App\Enums\SearchRunStatus;
use App\Models\SearchRun;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class SearchRunData implements JsonSerializable
{
    /**
     * @param  list<OePart>  $oeParts
     * @param  list<SupplierLookupData>  $lookups
     */
    public function __construct(
        public string $id,
        public SearchRunKind $kind,
        public SearchRunStatus $status,
        public ?string $requestText,
        public ?string $vin,
        public ?string $reference,
        public ?PartRequestUnderstanding $understanding,
        public ?IdentifyClarification $pendingQuestion,
        public array $oeParts,
        public array $lookups,
        public string $createdAt,
    ) {}

    public static function fromModel(SearchRun $run): self
    {
        return new self(
            id: $run->id,
            kind: $run->kind,
            status: $run->status,
            requestText: $run->request_text,
            vin: $run->vin,
            reference: $run->reference,
            understanding: $run->understanding === null ? null : PartRequestUnderstanding::fromArray($run->understanding),
            pendingQuestion: $run->pending_question === null ? null : IdentifyClarification::fromArray($run->pending_question),
            oeParts: array_map(OePart::fromArray(...), $run->oe_parts ?? []),
            lookups: array_values($run->lookups->map(SupplierLookupData::fromModel(...))->all()),
            createdAt: $run->created_at?->toISOString() ?? '',
        );
    }

    /**
     * @return array{id: string, kind: SearchRunKind, status: SearchRunStatus, requestText: string|null, vin: string|null, reference: string|null, understanding: PartRequestUnderstanding|null, pendingQuestion: IdentifyClarification|null, oeParts: list<OePart>, lookups: list<SupplierLookupData>, createdAt: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'status' => $this->status,
            'requestText' => $this->requestText,
            'vin' => $this->vin,
            'reference' => $this->reference,
            'understanding' => $this->understanding,
            'pendingQuestion' => $this->pendingQuestion,
            'oeParts' => $this->oeParts,
            'lookups' => $this->lookups,
            'createdAt' => $this->createdAt,
        ];
    }
}
