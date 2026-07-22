import { router, usePoll } from '@inertiajs/react';
import { useEffect, useState } from 'react';

function isRunLive(run: App.Data.SearchRunData): boolean {
    if (run.status === 'pending' || run.status === 'running') {
        return true;
    }

    return run.lookups.some(
        (lookup) => lookup.status === 'pending' || lookup.status === 'running',
    );
}

/**
 * Live SearchRun state: Echo wakes a server reload; poll is the safety net.
 *
 * Broadcasts only carry ids/status (Reverb rejects large Auto Delta payloads).
 * Full variant tables always come from the Inertia `run` prop.
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

    function handleEvent() {
        router.reload({ only: ['run'] });
    }

    return { run, handleEvent };
}
