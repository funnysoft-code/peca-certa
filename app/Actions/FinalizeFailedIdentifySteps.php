<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\SearchRun;
use Illuminate\Support\Str;
use Throwable;

/**
 * Marks open agent progress steps terminal when IdentifyAgentJob dies.
 *
 * Without this, the UI keeps spinning on status=running steps after failed().
 */
final readonly class FinalizeFailedIdentifySteps
{
    public function execute(SearchRun $run, ?Throwable $exception = null): SearchRun
    {
        /** @var list<array<string, mixed>> $steps */
        $steps = $run->agent_steps ?? [];
        $detail = $this->operatorDetail($exception);
        $changed = false;
        $now = now()->toIso8601String();

        foreach ($steps as $index => $step) {
            $status = $step['status'] ?? null;
            if ($status === 'done') {
                continue;
            }

            if ($status === 'failed') {
                continue;
            }

            $steps[$index]['status'] = 'failed';
            $existingDetail = $step['detail'] ?? null;
            $steps[$index]['detail'] = is_string($existingDetail) && $existingDetail !== ''
                ? $existingDetail.' · '.$detail
                : $detail;
            $steps[$index]['at'] = $now;
            $changed = true;
        }

        if ($changed) {
            $run->agent_steps = $steps;
        }

        // Append a redacted ops breadcrumb even when no step was open.
        /** @var list<array<string, mixed>> $traces */
        $traces = $run->tool_traces ?? [];
        $traces[] = [
            'id' => 'job-failed-'.Str::uuid()->toString(),
            'tool' => 'identify_agent_job',
            'detail' => null,
            'result' => json_encode([
                'ok' => false,
                'error' => 'job_failed',
                'message' => $detail,
            ], JSON_THROW_ON_ERROR),
            'at' => $now,
        ];
        $run->tool_traces = array_slice($traces, -80);

        $run->save();

        return $run;
    }

    private function operatorDetail(?Throwable $exception): string
    {
        if (! $exception instanceof Throwable) {
            return 'Identificação interrompida.';
        }

        $message = $exception->getMessage();

        if ((str_contains($message, '/pl24-appgtw/ext/api/1.0/login') || str_contains($message, 'login')) && (str_contains($message, '403') || str_contains($message, 'Forbidden'))) {
            return 'Catálogo OE indisponível (autenticação PartsLink24 recusada).';
        }

        if (str_contains($message, 'partslink24') || str_contains($message, 'PartsLink24')) {
            return 'Catálogo OE indisponível (erro PartsLink24).';
        }

        return 'Identificação interrompida por erro interno.';
    }
}
