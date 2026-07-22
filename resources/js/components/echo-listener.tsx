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
    const reverbKey = import.meta.env.VITE_REVERB_APP_KEY;

    useEffect(() => {
        setHydrated(true);
    }, []);

    // No client Echo when the app was built without a Reverb key (CI, local).
    if (!hydrated || typeof reverbKey !== 'string' || reverbKey.length === 0) {
        return null;
    }

    return <ActiveEchoListener {...props} />;
}
