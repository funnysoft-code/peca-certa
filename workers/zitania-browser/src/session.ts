import {
    acquire,
    connect,
    sessions,
    type Browser,
    type BrowserWorker,
} from '@cloudflare/playwright';

/** Keep idle browser sessions warm for 10 minutes (Browser Rendering max). */
export const SESSION_KEEP_ALIVE_MS = 600_000;

export const STORAGE_STATE_KEY = 'autozitania:storage-state';
export const SESSION_ID_KEY = 'autozitania:browser-session-id';

export type StorageState = {
    cookies: Array<{
        name: string;
        value: string;
        domain: string;
        path: string;
        expires: number;
        httpOnly: boolean;
        secure: boolean;
        sameSite: 'Strict' | 'Lax' | 'None';
    }>;
    origins: Array<{
        origin: string;
        localStorage: Array<{ name: string; value: string }>;
    }>;
};

export interface ObtainedBrowser {
    browser: Browser;
    sessionId: string;
    reused: boolean;
}

/**
 * Prefer one sticky Browser Rendering session so portal cookies / tabs survive
 * across Worker invocations. Fall back to acquire + connect (not launch) so
 * browser.close() disconnects without killing the remote session.
 */
export async function obtainBrowser(
    binding: BrowserWorker,
    kv?: KVNamespace,
): Promise<ObtainedBrowser> {
    const remembered = kv ? await kv.get(SESSION_ID_KEY) : null;
    if (remembered) {
        try {
            const browser = await connect(binding, remembered);
            await rememberSessionId(kv, remembered);
            return { browser, sessionId: remembered, reused: true };
        } catch {
            // Remembered session expired or is busy; try idle list next.
        }
    }

    const active = await sessions(binding);
    const idle = active.find((session) => !session.connectionId);

    if (idle) {
        try {
            const browser = await connect(binding, idle.sessionId);
            await rememberSessionId(kv, idle.sessionId);
            return {
                browser,
                sessionId: idle.sessionId,
                reused: true,
            };
        } catch {
            // Session may have closed between list and connect; fall through.
        }
    }

    const { sessionId } = await acquire(binding, {
        keep_alive: SESSION_KEEP_ALIVE_MS,
    });
    const browser = await connect(binding, sessionId);
    await rememberSessionId(kv, sessionId);

    return { browser, sessionId, reused: false };
}

async function rememberSessionId(
    kv: KVNamespace | undefined,
    sessionId: string,
): Promise<void> {
    if (!kv) {
        return;
    }

    // Match Browser Rendering keep_alive (10m) plus a little slack.
    await kv.put(SESSION_ID_KEY, sessionId, {
        expirationTtl: Math.ceil(SESSION_KEEP_ALIVE_MS / 1000) + 60,
    });
}

export async function loadStorageState(
    kv: KVNamespace | undefined,
): Promise<StorageState | null> {
    if (!kv) {
        return null;
    }

    const raw = await kv.get(STORAGE_STATE_KEY, 'json');
    if (!raw || typeof raw !== 'object') {
        return null;
    }

    return raw as StorageState;
}

export async function saveStorageState(
    kv: KVNamespace | undefined,
    state: StorageState,
): Promise<void> {
    if (!kv) {
        return;
    }

    // Portal sessions are shorter than this; 6h is a safe cookie cache ceiling.
    await kv.put(STORAGE_STATE_KEY, JSON.stringify(state), {
        expirationTtl: 60 * 60 * 6,
    });
}

/**
 * Touch the warm session so keep_alive does not expire between sparse searches.
 */
export async function keepSessionWarm(
    binding: BrowserWorker,
    kv?: KVNamespace,
): Promise<{
    ok: boolean;
    sessionId?: string;
    error?: string;
}> {
    try {
        const { browser, sessionId } = await obtainBrowser(binding, kv);
        try {
            const context = browser.contexts()[0] ?? (await browser.newContext());
            const page = context.pages()[0] ?? (await context.newPage());
            await page.evaluate(() => true);
            return { ok: true, sessionId };
        } finally {
            await browser.close();
        }
    } catch (error) {
        return {
            ok: false,
            error: error instanceof Error ? error.message : 'Warm keep-alive failed.',
        };
    }
}
