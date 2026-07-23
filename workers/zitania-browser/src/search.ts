import type { Browser, BrowserContext, Page } from '@cloudflare/playwright';
import type { StorageState } from './session';

export interface ZitaniaVariant {
    brandName: string;
    articleNumber: string;
    traderArticleNumber: string;
    retailPrice: number | null;
    availabilityText: string;
    inStock: boolean;
}

export interface ZitaniaSearchResult {
    query: string;
    variants: ZitaniaVariant[];
}

export const DEFAULT_ENTRY_URL =
    'https://web2.carparts-cat.com/default.aspx?11=102&14=15&1115=1&1281=17=0&10=CB42290652B84321A1D2E66B1FA73DCE102015&12=1400';

/** ERP rejects batches above ~7 for this account. */
const ERP_CHUNK_SIZE = 6;
/** Concurrent GetErpInfosAL calls; portal tolerates small fan-out. */
const ERP_PARALLEL = 3;

// Do not block images: portal ERP status widgets and some price fragments
// depend on them. Fonts/media/trackers are safe to drop.
const BLOCKED_RESOURCE_TYPES = new Set(['media', 'font']);
const BLOCKED_URL_PATTERN =
    /google-analytics|googletagmanager|gtag\/|facebook\.net|doubleclick|hotjar|clarity\.ms|newrelic|nr-data\.net/i;

function parsePrice(text: string): number | null {
    const match = text
        .replace(/\s/g, '')
        .match(/(\d+(?:[.,]\d{3})*(?:,\d+)?)EUR/i);

    if (!match) {
        return null;
    }

    return Number.parseFloat(match[1].replaceAll('.', '').replace(',', '.'));
}

const ASSET_BLOCKING_FLAG = '__zitaniaAssetBlocking';

/**
 * Skip images/fonts/trackers. ERP status icons keep their attributes in the
 * DOM even when the image request is aborted, so stock extraction still works.
 * Idempotent: warm-session reuses must not stack route handlers.
 */
export async function installAssetBlocking(page: Page): Promise<void> {
    const flagged = page as Page & { [ASSET_BLOCKING_FLAG]?: boolean };
    if (flagged[ASSET_BLOCKING_FLAG]) {
        return;
    }
    flagged[ASSET_BLOCKING_FLAG] = true;

    await page.route('**/*', (route) => {
        const request = route.request();
        if (BLOCKED_RESOURCE_TYPES.has(request.resourceType())) {
            return route.abort();
        }
        if (BLOCKED_URL_PATTERN.test(request.url())) {
            return route.abort();
        }
        return route.continue();
    });
}

/**
 * Prefer an existing open tab (warm session). Otherwise open a context, optionally
 * seeded with cached cookies from KV.
 */
export async function getWorkingPage(
    browser: Browser,
    storageState: StorageState | null,
): Promise<Page> {
    for (const context of browser.contexts()) {
        for (const page of context.pages()) {
            if (!page.isClosed()) {
                await installAssetBlocking(page).catch(() => undefined);
                return page;
            }
        }
    }

    const context: BrowserContext = await browser.newContext(
        storageState ? { storageState } : {},
    );
    const page = await context.newPage();
    await installAssetBlocking(page);

    return page;
}

export async function login(
    page: Page,
    entryUrl: string,
    username: string,
    password: string,
): Promise<void> {
    // Already on a logged-in catalog surface (warm session).
    if (
        !page.url().includes('Login.aspx') &&
        (await page
            .locator('#home_txt_art_direkt')
            .isVisible()
            .catch(() => false))
    ) {
        return;
    }

    await page.goto(entryUrl, { waitUntil: 'domcontentloaded', timeout: 45_000 });

    await Promise.race([
        page.waitForSelector('#username', { timeout: 15_000 }),
        page.waitForSelector('#home_txt_art_direkt', { timeout: 15_000 }),
    ]).catch(() => null);

    if (
        !page.url().includes('Login.aspx') &&
        (await page
            .locator('#home_txt_art_direkt')
            .isVisible()
            .catch(() => false))
    ) {
        return;
    }

    if (!page.url().includes('Login.aspx')) {
        // Cookie restored mid-redirect; land on catalog home.
        await page
            .waitForSelector('#home_txt_art_direkt', { timeout: 15_000 })
            .catch(() => null);
        if (
            await page
                .locator('#home_txt_art_direkt')
                .isVisible()
                .catch(() => false)
        ) {
            return;
        }
    }

    await page.fill('#username', username);
    await page.fill('#password', password);

    const loginNav = page
        .waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30_000 })
        .catch(() => null);
    await page.click('#login');
    await Promise.race([
        loginNav,
        page.waitForSelector('#home_txt_art_direkt', { timeout: 30_000 }).catch(() => null),
        page.waitForSelector('input[name="ok"]', { timeout: 30_000 }).catch(() => null),
    ]);

    // Single concurrent session: confirm takeover when the portal asks.
    const takeover = page.locator('input[name="ok"]');
    if (page.url().includes('Login.aspx') && (await takeover.count()) > 0) {
        const takeoverNav = page
            .waitForNavigation({
                waitUntil: 'domcontentloaded',
                timeout: 30_000,
            })
            .catch(() => null);
        await takeover.click();
        await Promise.race([
            takeoverNav,
            page
                .waitForSelector('#home_txt_art_direkt', { timeout: 30_000 })
                .catch(() => null),
        ]);
    }

    await page
        .waitForSelector('#home_txt_art_direkt', { timeout: 20_000 })
        .catch(() => null);

    if (page.url().includes('Login.aspx')) {
        throw new Error('Auto Zitania login failed: still on Login.aspx after submit.');
    }
}

export async function search(page: Page, reference: string): Promise<void> {
    const input = page.locator('#home_txt_art_direkt');

    if (!(await input.isVisible().catch(() => false))) {
        const tabs = page.locator('span', { hasText: /^Pesquisa$/ });
        const count = await tabs.count();
        for (let i = 0; i < count; i++) {
            const box = await tabs.nth(i).boundingBox();
            if (box && box.x >= 0 && box.width > 0) {
                await tabs.nth(i).click({ timeout: 10_000 });
                break;
            }
        }
        await input.waitFor({ state: 'visible', timeout: 15_000 });
    }

    // Warm sessions keep previous result rows in the DOM. Mark them stale so
    // we can detect a fresh render even when re-searching the same reference.
    await page
        .evaluate(() => {
            for (const row of Array.from(
                document.querySelectorAll('tr.main_artikel_panel_tr_artikel'),
            )) {
                row.setAttribute('data-zitania-stale', '1');
            }
        })
        .catch(() => undefined);

    await input.fill(reference, { timeout: 15_000 });

    const navigation = page
        .waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15_000 })
        .catch(() => null);
    const portalTraffic = page
        .waitForResponse(
            (response) => {
                const url = response.url();
                if (!url.includes('carparts-cat.com')) {
                    return false;
                }
                if (response.status() < 200 || response.status() >= 400) {
                    return false;
                }
                // Ignore static assets; accept any document/XHR/fetch postback.
                return !/\.(js|css|png|jpe?g|gif|svg|woff2?|ttf|ico)(\?|$)/i.test(
                    url,
                );
            },
            { timeout: 15_000 },
        )
        .catch(() => null);

    await input.press('Enter');
    await Promise.race([navigation, portalTraffic]);

    // Prefer brand-new rows (no stale mark). Fall back to any rows after a short wait.
    const freshRows = page.locator(
        'tr.main_artikel_panel_tr_artikel:not([data-zitania-stale="1"])',
    );
    let hasRows = (await freshRows.count()) > 0;
    if (!hasRows) {
        await freshRows
            .first()
            .waitFor({ state: 'attached', timeout: 12_000 })
            .catch(() => null);
        hasRows = (await freshRows.count()) > 0;
    }
    if (!hasRows) {
        // Full document replace may drop our stale marks; accept any rows.
        await page
            .waitForSelector('tr.main_artikel_panel_tr_artikel', {
                timeout: 5_000,
            })
            .catch(() => null);
        hasRows =
            (await page.locator('tr.main_artikel_panel_tr_artikel').count()) > 0;
    }
    if (!hasRows) {
        return;
    }

    // Prices and ERP widgets populate after the article list. Wait for a EUR
    // price or erpstatus mark instead of a fixed 5s sleep.
    await page
        .waitForFunction(
            () => {
                const prices = Array.from(
                    document.querySelectorAll('td.tc_price'),
                );
                if (
                    prices.some((cell) =>
                        /EUR|\d+[.,]\d{2}/i.test(
                            (cell as HTMLElement).innerText ?? '',
                        ),
                    )
                ) {
                    return true;
                }
                return document.querySelector('img[erpstatus]') !== null;
            },
            { timeout: 10_000 },
        )
        .catch(() => null);
}

export async function extract(
    page: Page,
    warehouse: string,
): Promise<ZitaniaVariant[]> {
    const rows = await page.evaluate(
        async ({
            warehouseMatch,
            chunkSize,
            parallel,
        }: {
            warehouseMatch: string;
            chunkSize: number;
            parallel: number;
        }) => {
            type ErpLocation = { Text?: string; AvailState?: number | string };
            type ErpItem = { KArtNr?: string; Locations?: ErpLocation[] };
            type ErpProxy = {
                GetErpInfosAL: (
                    token: string,
                    req: unknown[],
                    lang: unknown,
                    onOk: (resp: ErpItem[]) => void,
                    onErr: (err: unknown) => void,
                    data: unknown,
                ) => void;
            };

            type ArticleRow = {
                brandName: string;
                traderArticleNumber: string;
                articleNumber: string;
                retailPriceText: string;
                fallbackInStock: boolean;
                request: Record<string, string> | null;
            };

            const articleRows: ArticleRow[] = [];
            let currentBrand = '';

            for (const row of Array.from(document.querySelectorAll('tr'))) {
                const isArticle = row.className.includes(
                    'main_artikel_panel_tr_artikel',
                );

                if (!isArticle) {
                    const text = row.textContent?.replace(/\s+/g, ' ').trim() ?? '';
                    const looksLikeBrandHeader =
                        text.length > 1 &&
                        text.length < 60 &&
                        row.querySelector('input[type=checkbox]') !== null &&
                        !row.querySelector('td.tc_number');
                    if (looksLikeBrandHeader) {
                        currentBrand = text;
                    }
                    continue;
                }

                const numberCell = row.querySelector('td.tc_number');
                if (!numberCell) {
                    continue;
                }

                const numberLines = (numberCell as HTMLElement).innerText
                    .split('\n')
                    .map((line) => line.trim())
                    .filter(Boolean);
                const priceText =
                    (row.querySelector('td.tc_price') as HTMLElement | null)
                        ?.innerText ?? '';
                const availImg = row.querySelector('img[erpstatus]');
                const request = availImg
                    ? {
                          EinspNr: availImg.getAttribute('einspnr') ?? '',
                          EArtNr: availImg.getAttribute('eartnr') ?? '',
                          KArtNr: availImg.getAttribute('kartnr') ?? '',
                          GenArtNr: availImg.getAttribute('genartnr') ?? '',
                          Menge: availImg.getAttribute('menge') ?? '1',
                          ExtSysID: availImg.getAttribute('extsysid') ?? '',
                          KArtPrio: availImg.getAttribute('kartprio') ?? '0',
                      }
                    : null;

                articleRows.push({
                    brandName: currentBrand,
                    traderArticleNumber: numberLines[0] ?? '',
                    articleNumber: numberLines[1] ?? numberLines[0] ?? '',
                    retailPriceText: priceText.replace(/\s+/g, ' ').trim(),
                    fallbackInStock: (
                        row.querySelector('.erpTooltipBaseAvailstate')
                            ?.textContent ?? ''
                    )
                        .trim()
                        .toLowerCase()
                        .startsWith('dispon'),
                    request,
                });
            }

            const svc = (window as unknown as { ErpAppWSVC?: ErpProxy }).ErpAppWSVC;
            const token = (location.search.match(/[?&]10=([^&]+)/) ?? [])[1];
            const lang =
                (window as unknown as { sprachNr?: unknown }).sprachNr ?? 15;
            const requests = articleRows
                .map((r) => r.request)
                .filter((r): r is Record<string, string> => r !== null);

            const branchAvailByKArt = new Map<string, boolean>();

            const fetchChunk = (chunk: Record<string, string>[]): Promise<void> =>
                new Promise<void>((resolve) => {
                    if (!svc || !token) {
                        resolve();
                        return;
                    }
                    const done = setTimeout(resolve, 12_000);
                    svc.GetErpInfosAL(
                        token,
                        chunk,
                        lang,
                        (resp: ErpItem[]) => {
                            clearTimeout(done);
                            for (const item of resp) {
                                const branch = (item.Locations ?? []).find((loc) =>
                                    (loc.Text ?? '')
                                        .toUpperCase()
                                        .includes(warehouseMatch.toUpperCase()),
                                );
                                branchAvailByKArt.set(
                                    item.KArtNr ?? '',
                                    Number(branch?.AvailState) === 1,
                                );
                            }
                            resolve();
                        },
                        () => {
                            clearTimeout(done);
                            resolve();
                        },
                        null,
                    );
                });

            if (svc && token && requests.length > 0) {
                const chunks: Record<string, string>[][] = [];
                for (let i = 0; i < requests.length; i += chunkSize) {
                    chunks.push(requests.slice(i, i + chunkSize));
                }

                for (let i = 0; i < chunks.length; i += parallel) {
                    await Promise.all(
                        chunks.slice(i, i + parallel).map((chunk) => fetchChunk(chunk)),
                    );
                }
            }

            return articleRows.map((r) => {
                const key = r.request?.KArtNr ?? '';
                const inStock = branchAvailByKArt.has(key)
                    ? (branchAvailByKArt.get(key) ?? false)
                    : r.fallbackInStock;

                return {
                    brandName: r.brandName,
                    traderArticleNumber: r.traderArticleNumber,
                    articleNumber: r.articleNumber,
                    retailPriceText: r.retailPriceText,
                    availabilityText: inStock ? 'disponível' : 'não disponível',
                    inStock,
                };
            });
        },
        {
            warehouseMatch: warehouse,
            chunkSize: ERP_CHUNK_SIZE,
            parallel: ERP_PARALLEL,
        },
    );

    return rows.map(({ retailPriceText, ...row }) => ({
        ...row,
        retailPrice: parsePrice(retailPriceText),
    }));
}

export async function runSearch(
    page: Page,
    options: {
        reference: string;
        username: string;
        password: string;
        entryUrl: string;
        warehouse: string;
    },
): Promise<ZitaniaSearchResult> {
    await login(page, options.entryUrl, options.username, options.password);
    await search(page, options.reference);
    const variants = await extract(page, options.warehouse);

    return {
        query: options.reference,
        variants,
    };
}
