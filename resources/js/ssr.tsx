import { createInertiaApp } from '@inertiajs/react';
import createServer from '@inertiajs/react/server';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { ComponentType } from 'react';
import ReactDOMServer from 'react-dom/server';
import { TooltipProvider } from '@/components/ui/tooltip';

const appName = import.meta.env.VITE_APP_NAME || 'R2CZ Auto Finder';

/**
 * SSR entry is intentionally minimal: only the public landing (`welcome`)
 * is server-rendered. Authenticated pages skip SSR (see AppServiceProvider).
 */
createServer((page) =>
    createInertiaApp({
        page,
        render: ReactDOMServer.renderToString,
        title: (title) => (title ? `${title} - ${appName}` : appName),
        resolve: (name) =>
            resolvePageComponent(
                `./pages/${name}.tsx`,
                import.meta.glob<ComponentType>('./pages/**/*.tsx'),
            ),
        layout: () => null,
        setup: ({ App, props }) => {
            return (
                <TooltipProvider delayDuration={0}>
                    <App {...props} />
                </TooltipProvider>
            );
        },
    }),
);
