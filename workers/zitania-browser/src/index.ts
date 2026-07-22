import { launch, type BrowserWorker } from '@cloudflare/playwright';
import { DEFAULT_ENTRY_URL, runSearch } from './search';

export interface Env {
    BROWSER: BrowserWorker;
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

export default {
    async fetch(request: Request, env: Env): Promise<Response> {
        if (request.method === 'GET' && new URL(request.url).pathname === '/health') {
            return json({ ok: true, service: 'zitania-browser' });
        }

        if (request.method !== 'POST') {
            return json({ error: 'Method not allowed' }, 405);
        }

        const auth = request.headers.get('authorization') ?? '';
        const token = auth.startsWith('Bearer ') ? auth.slice(7) : '';
        if (!env.SERVICE_TOKEN || token !== env.SERVICE_TOKEN) {
            return unauthorized();
        }

        let body: { reference?: unknown };
        try {
            body = (await request.json()) as { reference?: unknown };
        } catch {
            return badRequest('Invalid JSON body.');
        }

        const reference =
            typeof body.reference === 'string' ? body.reference.trim() : '';
        if (reference === '') {
            return badRequest('Field "reference" is required.');
        }

        if (!env.AUTOZITANIA_USERNAME || !env.AUTOZITANIA_PASSWORD) {
            return json(
                { error: 'Worker missing AUTOZITANIA_USERNAME / AUTOZITANIA_PASSWORD secrets.' },
                500,
            );
        }

        const browser = await launch(env.BROWSER);
        try {
            const page = await browser.newPage();
            const result = await runSearch(page, {
                reference,
                username: env.AUTOZITANIA_USERNAME,
                password: env.AUTOZITANIA_PASSWORD,
                entryUrl: env.AUTOZITANIA_ENTRY_URL || DEFAULT_ENTRY_URL,
                warehouse: env.AUTOZITANIA_WAREHOUSE || 'LEIRIA',
            });

            return json(result);
        } catch (error) {
            const message =
                error instanceof Error ? error.message : 'Unknown search failure.';
            return json({ error: message }, 502);
        } finally {
            await browser.close();
        }
    },
};
