<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\IdentifyClarification;
use App\Enums\SearchRunStatus;
use App\Jobs\IdentifyAgentJob;
use App\Models\SearchRun;
use App\Services\PartsLink24\PartsLink24Brand;
use App\Services\PartsLink24\VinBrandResolver;
use InvalidArgumentException;

final readonly class ResumeIdentifyRun
{
    public function __construct(
        private VinBrandResolver $vinBrandResolver,
    ) {}

    public function execute(SearchRun $run, string $answer, ?string $selectedOption = null): SearchRun
    {
        throw_if($run->status !== SearchRunStatus::NeedsInput, InvalidArgumentException::class, 'Search run is not waiting for operator input.');

        $pending = $run->pending_question === null
            ? null
            : IdentifyClarification::fromArray($run->pending_question);

        if ($pending instanceof IdentifyClarification && $pending->isUnsupportedBrand()) {
            return $this->resumeWithBrandOverride($run, $selectedOption, $answer);
        }

        $parts = array_values(array_filter([
            $selectedOption !== null && $selectedOption !== '' ? 'Opção escolhida: '.$selectedOption : null,
            $answer !== '' ? $answer : null,
        ]));

        $content = $parts === [] ? 'Continuar sem texto adicional.' : implode("\n", $parts);

        $messages = $run->messages ?? [];
        $messages[] = [
            'role' => 'user',
            'content' => $content,
        ];

        $run->messages = $messages;
        $run->pending_question = null;
        $run->status = SearchRunStatus::Pending;
        $run->save();

        dispatch(new IdentifyAgentJob($run));

        return $run;
    }

    private function resumeWithBrandOverride(SearchRun $run, ?string $selectedOption, string $answer): SearchRun
    {
        $brandKey = $selectedOption !== null && $selectedOption !== ''
            ? $selectedOption
            : mb_strtolower(mb_trim($answer));

        throw_if($brandKey === '', InvalidArgumentException::class, 'Brand catalog override is required.');

        $brand = $this->vinBrandResolver->fromCatalogKey($brandKey);

        throw_unless(
            $brand instanceof PartsLink24Brand,
            InvalidArgumentException::class,
            'Unknown PartsLink24 brand catalog: '.$brandKey,
        );

        $messages = $run->messages ?? [];
        $messages[] = [
            'role' => 'user',
            'content' => 'Catálogo PartsLink24 escolhido pelo operador: '.$brand->key,
        ];

        $run->messages = $messages;
        $run->brand_override = $brand->key;
        $run->pending_question = null;
        $run->status = SearchRunStatus::Pending;
        $run->save();

        dispatch(new IdentifyAgentJob($run));

        return $run;
    }
}
