<?php

declare(strict_types=1);

namespace App\Data;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class IdentifyResult implements JsonSerializable
{
    /**
     * @param  list<OePart>  $oeParts
     * @param  list<PartSearchResult>  $autoDeltaResults
     * @param  list<PartSearchResult>  $autoZitaniaResults
     */
    public function __construct(
        public PartRequestUnderstanding $understanding,
        public array $oeParts,
        public array $autoDeltaResults,
        public array $autoZitaniaResults,
    ) {}

    /**
     * @return array{understanding: PartRequestUnderstanding, oeParts: list<OePart>, autoDeltaResults: list<PartSearchResult>, autoZitaniaResults: list<PartSearchResult>}
     */
    public function jsonSerialize(): array
    {
        return [
            'understanding' => $this->understanding,
            'oeParts' => $this->oeParts,
            'autoDeltaResults' => $this->autoDeltaResults,
            'autoZitaniaResults' => $this->autoZitaniaResults,
        ];
    }
}
