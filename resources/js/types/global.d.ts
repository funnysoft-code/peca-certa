/// <reference path="./generated.d.ts" />

import type { FlashToast } from '@/hooks/use-flash-toast';
import type { Auth } from '@/types/auth';

declare module 'react' {
    // Allow the `passwordrules` attribute on password inputs (Safari/WebAuthn).
    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

// Type the props shared from `HandleInertiaRequests::share()`, so every page
// gets autocomplete on `usePage().props`. Extend this with your own shared
// props as you add them.
declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            flash?: {
                toast?: FlashToast;
            };
            [key: string]: unknown;
        };
    }
}
