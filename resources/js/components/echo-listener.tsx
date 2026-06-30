import { useEcho } from '@laravel/echo-react';
import { useEffect, useState } from 'react';

type EchoVisibility = 'private' | 'public' | 'presence';

interface EchoListenerProps {
    /** Channel name without the visibility prefix, e.g. `batches.{id}`. */
    channel: string;
    /** Event name(s) to listen for. Dot-prefix custom `broadcastAs` names. */
    events: string | string[];
    /** Invoked on every matched event. */
    onEvent: (event: unknown) => void;
    /** Channel visibility. Defaults to private. */
    visibility?: EchoVisibility;
}

function ActiveEchoListener({
    channel,
    events,
    onEvent,
    visibility = 'private',
}: EchoListenerProps) {
    useEcho(channel, events, onEvent, [], visibility);

    return null;
}

export function EchoListener(props: EchoListenerProps) {
    const [hydrated, setHydrated] = useState(false);

    useEffect(() => {
        setHydrated(true);
    }, []);

    if (!hydrated) {
        return null;
    }

    return <ActiveEchoListener {...props} />;
}
