import { usePoll } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type RunAdvancedEvent = { run: App.Data.SearchRunData };
type LookupReadyEvent = { lookup: App.Data.SupplierLookupData };

function isRunAdvancedEvent(event: unknown): event is RunAdvancedEvent {
    return (
        typeof event === 'object' &&
        event !== null &&
        'run' in event &&
        event.run !== null
    );
}

function isLookupReadyEvent(event: unknown): event is LookupReadyEvent {
    return (
        typeof event === 'object' &&
        event !== null &&
        'lookup' in event &&
        event.lookup !== null
    );
}

function mergeLookups(
    current: App.Data.SupplierLookupData[],
    incoming: App.Data.SupplierLookupData[],
): App.Data.SupplierLookupData[] {
    const byId = new Map(current.map((lookup) => [lookup.id, lookup]));

    for (const lookup of incoming) {
        byId.set(lookup.id, lookup);
    }

    return Array.from(byId.values());
}

function upsertLookup(
    current: App.Data.SupplierLookupData[],
    lookup: App.Data.SupplierLookupData,
): App.Data.SupplierLookupData[] {
    const exists = current.some((l) => l.id === lookup.id);

    return exists
        ? current.map((l) => (l.id === lookup.id ? lookup : l))
        : [...current, lookup];
}

function isRunLive(run: App.Data.SearchRunData): boolean {
    if (run.status === 'pending' || run.status === 'running') {
        return true;
    }

    return run.lookups.some(
        (lookup) => lookup.status === 'pending' || lookup.status === 'running',
    );
}

/**
 * Live SearchRun state: Echo when available, Inertia poll as reliable fallback.
 *
 * Polling covers (1) missing/misconfigured Reverb client, (2) events that
 * fired before the WebSocket subscribed, (3) dropped sockets.
 */
export function useSearchRunStream(initialRun: App.Data.SearchRunData): {
    run: App.Data.SearchRunData;
    handleEvent: (event: unknown) => void;
} {
    const [run, setRun] = useState(initialRun);

    // Inertia usePoll reloads the `run` prop — fold it into local state.
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

    function handleEvent(event: unknown) {
        if (isRunAdvancedEvent(event)) {
            setRun((prev) => ({
                ...prev,
                status: event.run.status,
                understanding: event.run.understanding,
                oeParts: event.run.oeParts,
                lookups: mergeLookups(prev.lookups, event.run.lookups),
            }));

            return;
        }

        if (isLookupReadyEvent(event)) {
            setRun((prev) => ({
                ...prev,
                lookups: upsertLookup(prev.lookups, event.lookup),
            }));
        }
    }

    return { run, handleEvent };
}
