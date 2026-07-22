import babel from '@rolldown/plugin-babel';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite-plus';

export default defineConfig({
    resolve: {
        dedupe: ['react', 'react-dom', 'react-dom/client'],
    },
    lint: {
        options: {
            // typeAware rules still use TS program where available. Full
            // typeCheck is redundant with the CI TypeScript job (tsc --noEmit)
            // and currently crashes tsgolint on Linux ARM with "Invalid tsconfig"
            // against TS 6 path mapping (local Darwin is fine).
            typeAware: true,
            typeCheck: false,
        },
        plugins: ['eslint', 'typescript', 'unicorn', 'oxc', 'react'],
        ignorePatterns: ['vite.config.ts', 'public/**'],
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        useTabs: false,
        semi: true,
        singleQuote: true,
        overrides: [
            {
                files: ['**/*.yml'],
                options: {
                    tabWidth: 2,
                },
            },
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn'],
            stylesheet: 'resources/css/app.css',
        },
        sortImports: {
            groups: [
                'builtin',
                'external',
                'internal',
                'parent',
                'sibling',
                'index',
            ],
            newlinesBetween: false,
        },
        ignorePatterns: [
            '**/*.md',
            'resources/js/components/ui/*',
            'resources/views/mail/*',
            'resources/js/actions/*',
            'resources/js/routes/*',
            'resources/js/wayfinder/*',
        ],
    },
    staged: {
        // App frontend only. Cloudflare Workers live under workers/ with their
        // own package.json and are typechecked via wrangler there.
        '{resources,bin}/**/*.{js,ts,tsx}': 'vp check --fix',
        '*.php': [
            'vendor/bin/rector process --no-diffs --no-progress-bar',
            'vendor/bin/pint',
        ],
    },
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            ssr: 'resources/js/ssr.tsx',
            refresh: true,
        }),
        react(),
        babel({
            plugins: ['babel-plugin-react-compiler'],
            // Only transform app source files, not node_modules.
            include: /resources\/js\/.+\.[jt]sx?$/,
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ],
});
