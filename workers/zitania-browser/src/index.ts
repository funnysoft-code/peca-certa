import type { BrowserWorker } from '@cloudflare/playwright';
import {
    DEFAULT_ENTRY_URL,
    getWorkingPage,
    runSearch,
    type ZitaniaSearchResult,
} from './search';
import {
    keepSessionWarm,
    loadStorageState,
    obtainBrowser,
    saveStorageState,
    type StorageState,
} from './session';

export interface Env {
    BROWSER: BrowserWorker;
    SESSION_KV?: KVNamespace;
    SERVICE_TOKEN: string;
    AUTOZITANIA_USERNAME: string;
    AUTOZITANIA_PASSWORD: string;
    AUTOZITANIA_ENTRY_URL?: string;
    AUTOZITANIA_WAREHOUSE?: string;
}

function json(data: unknown, status = 200): Response {
    return new Response(JSON.stringify(data), {
        status,
        headers: {
            'content-type': 'application/json; charset=utf-8',
        },
    });
}

function unauthorized(): Response {
    return json({ error: 'Unauthorized' }, 401);
}

function badRequest(message: string): Response {
    return json({ error: message }, 400);
}

function bearerToken(request: Request): string {
    const auth = request.headers.get('authorization') ?? '';
    return auth.startsWith('Bearer ') ? auth.slice(7) : '';
}

function authorize(request: Request, env: Env): boolean {
    return Boolean(env.SERVICE_TOKEN) && bearerToken(request) === env.SERVICE_TOKEN;
}

type SearchResponse = ZitaniaSearchResult & {
    meta: {
        ms: number;
        sessionId: string;
        reused: boolean;
    };
};

async function handleSearch(request: Request, env: Env): Promise<Response> {
    let body: { reference?: unknown; includeUnavailable?: unknown };
    try {
        body = (await request.json()) as {
            reference?: unknown;
            includeUnavailable?: unknown;
        };
    } catch {
        return badRequest('Invalid JSON body.');
    }

    const reference =
        typeof body.reference === 'string' ? body.reference.trim() : '';
    if (reference === '') {
        return badRequest('Field "reference" is required.');
    }

    const includeUnavailable = body.includeUnavailable === true;

    if (!env.AUTOZITANIA_USERNAME || !env.AUTOZITANIA_PASSWORD) {
        return json(
            {
                error: 'Worker missing AUTOZITANIA_USERNAME / AUTOZITANIA_PASSWORD secrets.',
            },
            500,
        );
    }

    const started = Date.now();
    const { browser, sessionId, reused } = await obtainBrowser(
        env.BROWSER,
        env.SESSION_KV,
    );

    try {
        const storageState = await loadStorageState(env.SESSION_KV);
        const page = await getWorkingPage(browser, storageState);
        const result = await runSearch(page, {
            reference,
            username: env.AUTOZITANIA_USERNAME,
            password: env.AUTOZITANIA_PASSWORD,
            entryUrl: env.AUTOZITANIA_ENTRY_URL || DEFAULT_ENTRY_URL,
            warehouse: env.AUTOZITANIA_WAREHOUSE || 'LEIRIA',
            includeUnavailable,
        });

        try {
            const state = (await page.context().storageState()) as StorageState;
            await saveStorageState(env.SESSION_KV, state);
        } catch {
            // Cookie cache is best-effort; search already succeeded.
        }

        const payload: SearchResponse = {
            ...result,
            meta: {
                ms: Date.now() - started,
                sessionId,
                reused,
            },
        };

        return json(payload);
    } catch (error) {
        const message =
            error instanceof Error ? error.message : 'Unknown search failure.';
        return json(
            {
                error: message,
                meta: {
                    ms: Date.now() - started,
                    sessionId,
                    reused,
                },
            },
            502,
        );
    } finally {
        // connect()-based close disconnects the Worker without killing the session.
        await browser.close();
    }
}

export default {
    async fetch(request: Request, env: Env): Promise<Response> {
        const { pathname } = new URL(request.url);

        if (request.method === 'GET' && pathname === '/health') {
            return json({ ok: true, service: 'zitania-browser' });
        }

        if (request.method === 'POST' && (pathname === '/' || pathname === '/search')) {
            if (!authorize(request, env)) {
                return unauthorized();
            }
            return handleSearch(request, env);
        }

        if (request.method !== 'POST') {
            return json({ error: 'Method not allowed' }, 405);
        }

        if (!authorize(request, env)) {
            return unauthorized();
        }

        // Backward-compatible: POST any path with { reference }.
        return handleSearch(request, env);
    },

    /**
     * Keep the warm Browser Rendering session (and portal cookies) alive between
     * sparse user searches. Runs every 8 minutes.
     */
    async scheduled(
        _controller: ScheduledController,
        env: Env,
        ctx: ExecutionContext,
    ): Promise<void> {
        ctx.waitUntil(
            keepSessionWarm(env.BROWSER, env.SESSION_KV).then((result) => {
                console.log('zitania-browser keep-alive', result);
            }),
        );
    },
};
