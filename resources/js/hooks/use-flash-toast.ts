import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';

/**
 * A toast payload flashed from the backend. Share it from
 * `HandleInertiaRequests::share()` as `flash.toast` and set it with
 * `->with('toast', ['type' => 'success', 'message' => '...'])`.
 */
export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
};

/**
 * Surface backend flash messages as Sonner toasts. Call once near the root of
 * the app (e.g. in the app layout). Requires `flash.toast` to be shared from
 * `HandleInertiaRequests`.
 */
export function useFlashToast(): void {
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            const data = flash?.toast as FlashToast | undefined;

            if (!data) {
                return;
            }

            toast[data.type](data.message);
        });
    }, []);
}
