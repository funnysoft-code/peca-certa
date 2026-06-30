import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';

/**
 * Surface server-side validation errors (422) as a toast. Inertia delivers
 * field errors inline via `useForm().errors`, but errors keyed to nested paths
 * (e.g. `photos.0` for a per-file rule) are easily missed when a form only
 * renders the parent field's error — so this is the global safety net. Call
 * once near the root of the app (e.g. in the app layout).
 */
export function useValidationToast(): void {
    useEffect(() => {
        return router.on('error', (event) => {
            const errors = (event as CustomEvent).detail?.errors as
                | Record<string, string>
                | undefined;

            const messages = [
                ...new Set(Object.values(errors ?? {}).filter(Boolean)),
            ];

            if (messages.length === 0) {
                return;
            }

            const [first, ...rest] = messages;

            toast.error(first, {
                description: rest.length > 0 ? rest.join('\n') : undefined,
            });
        });
    }, []);
}
