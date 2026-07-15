<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\IdentifyResult;

final readonly class IdentifyAndSourceParts
{
    public function __construct(
        private UnderstandPartRequest $understand,
        private IdentifyOeParts $identify,
        private SearchAutoDeltaParts $autoDelta,
        private SearchAutoZitaniaParts $autoZitania,
    ) {}

    public function execute(string $request, string $vin): IdentifyResult
    {
        $understanding = $this->understand->execute($request);

        if ($understanding->needsClarification()) {
            return new IdentifyResult($understanding, [], [], []);
        }

        $oeParts = $this->identify->execute($vin, $understanding->category, $understanding->keywords);

        $autoDeltaResults = [];
        $autoZitaniaResults = [];
        foreach ($oeParts as $part) {
            $autoDeltaResults[] = $this->autoDelta->execute($part->oeNumber);
            $autoZitaniaResults[] = $this->autoZitania->execute($part->oeNumber);
        }

        return new IdentifyResult($understanding, $oeParts, $autoDeltaResults, $autoZitaniaResults);
    }
}
