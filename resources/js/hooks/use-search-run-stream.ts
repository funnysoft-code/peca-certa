import { router, usePoll } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

function isRunLive(run: App.Data.SearchRunData): boolean {
    if (run.status === 'pending' || run.status === 'running') {
        return true;
    }

    return run.lookups.some(
        (lookup) => lookup.status === 'pending' || lookup.status === 'running',
    );
}

function isAgentStepPayload(
    event: unknown,
): event is { step: App.Data.AgentStep } {
    if (typeof event !== 'object' || event === null || !('step' in event)) {
        return false;
    }

    const step = (event as { step: unknown }).step;

    return (
        typeof step === 'object' &&
        step !== null &&
        'id' in step &&
        'tool' in step &&
        'label' in step &&
        'status' in step
    );
}

function mergeAgentStep(
    steps: App.Data.AgentStep[],
    step: App.Data.AgentStep,
): App.Data.AgentStep[] {
    const index = steps.findIndex((existing) => existing.id === step.id);

    if (index === -1) {
        return [...steps, step];
    }

    const next = [...steps];
    next[index] = step;

    return next;
}

/**
 * Live SearchRun state: Echo wakes a server reload; poll is the safety net.
 *
 * Broadcasts only carry ids/status (Reverb rejects large Auto Delta payloads).
 * Agent tool steps are merged optimistically from `.agent.step` so the
 * operator sees progress without waiting for a full run reload.
 * Full run meta comes from the Inertia `run` prop. Findings are loaded via the
 * JSON list endpoint and refetch when lookup fingerprints on `run` change.
 */
export function useSearchRunStream(initialRun: App.Data.SearchRunData): {
    run: App.Data.SearchRunData;
    handleEvent: (event: unknown) => void;
} {
    const [run, setRun] = useState(initialRun);

    useEffect(() => {
        setRun(initialRun);
    }, [initialRun]);

    const live = isRunLive(run);
    const { start, stop } = usePoll(
        2500,
        { only: ['run'] },
        { autoStart: false, keepAlive: true },
    );

    useEffect(() => {
        if (live) {
            start();
        } else {
            stop();
        }
    }, [live, start, stop]);

    const handleEvent = useCallback((event: unknown) => {
        if (isAgentStepPayload(event)) {
            setRun((current) => ({
                ...current,
                agentSteps: mergeAgentStep(
                    current.agentSteps ?? [],
                    event.step,
                ),
            }));

            return;
        }

        router.reload({ only: ['run'] });
    }, []);

    return { run, handleEvent };
}
