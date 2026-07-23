<?php

declare(strict_types=1);

namespace App\Data;

use App\Enums\SearchRunKind;
use App\Enums\SearchRunStatus;
use App\Models\SearchRun;
use App\Support\OePartDiagramUrl;
use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class SearchRunData implements JsonSerializable
{
    /**
     * @param  list<OePart>  $oeParts
     * @param  list<SupplierLookupData>  $lookups
     * @param  list<AgentStep>  $agentSteps
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
        public array $agentSteps,
        public string $createdAt,
        public string $authorName,
        public bool $unavailableIncluded = false,
    ) {}

    public static function fromModel(SearchRun $run): self
    {
        $author = $run->user;
        $authorName = $author === null ? '' : $author->name;

        $agentSteps = array_map(
            AgentStep::fromArray(...),
            $run->agent_steps ?? [],
        );

        return new self(
            id: $run->id,
            kind: $run->kind,
            status: $run->status,
            requestText: $run->request_text,
            vin: $run->vin,
            reference: $run->reference,
            understanding: $run->understanding === null ? null : PartRequestUnderstanding::fromArray($run->understanding),
            pendingQuestion: $run->pending_question === null ? null : IdentifyClarification::fromArray($run->pending_question),
            oeParts: array_map(
                fn (array $row): OePart => self::oePartWithFreshDiagramUrl($run, OePart::fromArray($row)),
                $run->oe_parts ?? [],
            ),
            lookups: array_values($run->lookups->map(SupplierLookupData::fromModel(...))->all()),
            agentSteps: $agentSteps,
            createdAt: $run->created_at?->toISOString() ?? '',
            authorName: $authorName,
            unavailableIncluded: (bool) $run->unavailable_included,
        );
    }

    /**
     * @return array{id: string, kind: SearchRunKind, status: SearchRunStatus, requestText: string|null, vin: string|null, reference: string|null, understanding: PartRequestUnderstanding|null, pendingQuestion: IdentifyClarification|null, oeParts: list<OePart>, lookups: list<SupplierLookupData>, agentSteps: list<AgentStep>, createdAt: string, authorName: string, unavailableIncluded: bool}
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
            'agentSteps' => $this->agentSteps,
            'createdAt' => $this->createdAt,
            'authorName' => $this->authorName,
            'unavailableIncluded' => $this->unavailableIncluded,
        ];
    }

    private static function oePartWithFreshDiagramUrl(SearchRun $run, OePart $part): OePart
    {
        if (! is_string($part->diagramPath) || $part->diagramPath === '') {
            return $part;
        }

        return $part->withDiagram(
            $part->diagramPath,
            OePartDiagramUrl::for($run, $part->diagramPath),
        );
    }
}
