<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\AgentStep;
use App\Events\SearchRunAgentStep;
use App\Models\SearchRun;
use App\Support\RedactToolTrace;
use Illuminate\Support\Facades\DB;

final readonly class RecordIdentifyAgentStep
{
    private const int MaxSteps = 40;

    private const int MaxTraces = 80;

    public function __construct(
        private RedactToolTrace $redactToolTrace,
    ) {}

    /**
     * @param  'running'|'done'  $status
     */
    public function execute(
        string $runId,
        string $stepId,
        string $tool,
        string $status,
        ?string $detail = null,
        mixed $result = null,
    ): void {
        DB::transaction(function () use ($runId, $stepId, $tool, $status, $detail, $result): void {
            $run = SearchRun::query()->whereKey($runId)->lockForUpdate()->first();

            if (! $run instanceof SearchRun) {
                return;
            }

            $steps = $this->normalizeSteps($run->agent_steps);
            $label = $this->labelFor($tool);
            $at = now()->toIso8601String();
            $payload = [
                'id' => $stepId,
                'tool' => $tool,
                'label' => $label,
                'status' => $status,
                'detail' => $detail,
                'at' => $at,
            ];

            $updated = false;

            foreach ($steps as $index => $existing) {
                if (($existing['id'] ?? null) !== $stepId) {
                    continue;
                }

                $payload['label'] = is_string($existing['label'] ?? null) ? $existing['label'] : $label;
                $payload['detail'] = $detail ?? (is_string($existing['detail'] ?? null) ? $existing['detail'] : null);
                $steps[$index] = $payload;
                $updated = true;
                break;
            }

            if (! $updated) {
                $steps[] = $payload;
            }

            $run->agent_steps = array_slice($steps, -self::MaxSteps);

            if ($status === 'done' && $result !== null) {
                $traces = $this->normalizeSteps($run->tool_traces);
                $traces[] = [
                    'id' => $stepId,
                    'tool' => $tool,
                    'detail' => $detail,
                    'result' => $this->redactToolTrace->execute($result),
                    'at' => $at,
                ];
                $run->tool_traces = array_slice($traces, -self::MaxTraces);
            }

            $run->save();

            $step = AgentStep::fromArray($payload);
            event(new SearchRunAgentStep($run, $step));
        });
    }

    public function labelFor(string $tool): string
    {
        return match ($tool) {
            'resolve_brand' => 'A resolver a marca…',
            'decode_vin' => 'A descodificar o VIN…',
            'search_parts_by_vin' => 'A pesquisar no catálogo OE…',
            'list_main_groups' => 'A listar grupos principais…',
            'list_sub_groups' => 'A listar subgrupos…',
            'list_bom_parts' => 'A listar peças do esquema…',
            'get_part_info' => 'A obter detalhe da peça…',
            default => 'A executar ferramenta…',
        };
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     */
    public function detailFromArguments(array $arguments): ?string
    {
        $query = $arguments['query'] ?? null;

        if (is_string($query) && $query !== '') {
            return mb_strlen($query) > 80 ? mb_substr($query, 0, 77).'…' : $query;
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeSteps(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $steps = [];

        foreach ($raw as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $steps[] = $row;
            }
        }

        return $steps;
    }
}
