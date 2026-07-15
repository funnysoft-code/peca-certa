/**
 * Auto Zitania (TOPMOTIVE/DVSE carparts-cat.com) part search sidecar.
 *
 * Usage: bun bin/zitania-search.ts "<part reference>"
 * Reads AUTOZITANIA_USERNAME / AUTOZITANIA_PASSWORD / AUTOZITANIA_ENTRY_URL from env.
 * Prints a JSON result to stdout; diagnostics go to stderr.
 */
import process from 'node:process';
import { chromium, type Page } from 'playwright';

interface ZitaniaVariant {
    brandName: string;
    articleNumber: string;
    traderArticleNumber: string;
    retailPriceText: string;
    availabilityText: string;
    inStock: boolean;
}

const DEFAULT_ENTRY_URL =
    'https://web2.carparts-cat.com/default.aspx?11=102&14=15&1115=1&1281=17=0&10=CB42290652B84321A1D2E66B1FA73DCE102015&12=1400';

// R2CZ sources from the Leiria branch, so availability is keyed off that
// warehouse specifically rather than "in stock anywhere in the country".
const WAREHOUSE = process.env.AUTOZITANIA_WAREHOUSE ?? 'LEIRIA';

function fail(message: string): never {
    console.error(message);
    process.exit(1);
}

function parsePrice(text: string): number | null {
    const match = text
        .replace(/\s/g, '')
        .match(/(\d+(?:[.,]\d{3})*(?:,\d+)?)EUR/i);
    if (!match) {
        return null;
    }

    return Number.parseFloat(match[1].replaceAll('.', '').replace(',', '.'));
}

async function login(
    page: Page,
    entryUrl: string,
    username: string,
    password: string,
): Promise<void> {
    await page.goto(entryUrl, { waitUntil: 'networkidle' });

    if (!page.url().includes('Login.aspx')) {
        return;
    }

    await page.fill('#username', username);
    await page.fill('#password', password);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle', timeout: 30_000 }),
        page.click('#login'),
    ]);

    // The account allows a single concurrent session; the portal asks for
    // confirmation before evicting the existing one. Confirm with "ok".
    const takeover = page.locator('input[name="ok"]');
    if (page.url().includes('Login.aspx') && (await takeover.count()) > 0) {
        console.error('Existing session detected; taking over.');
        await Promise.all([
            page.waitForNavigation({
                waitUntil: 'networkidle',
                timeout: 30_000,
            }),
            takeover.click(),
        ]);
    }

    if (page.url().includes('Login.aspx')) {
        fail('Auto Zitania login failed: still on Login.aspx after submit.');
    }
}

async function search(page: Page, reference: string): Promise<void> {
    const input = page.locator('#home_txt_art_direkt');

    if (!(await input.isVisible().catch(() => false))) {
        // Several "Pesquisa" spans exist; the collapsed sidebar copies sit at
        // negative x. Click the one actually inside the viewport.
        const tabs = page.locator('span', { hasText: /^Pesquisa$/ });
        const count = await tabs.count();
        for (let i = 0; i < count; i++) {
            const box = await tabs.nth(i).boundingBox();
            if (box && box.x >= 0 && box.width > 0) {
                await tabs.nth(i).click({ timeout: 10_000 });
                break;
            }
        }
    }

    await input.fill(reference, { timeout: 15_000 });
    await Promise.all([
        page
            .waitForNavigation({ waitUntil: 'networkidle', timeout: 45_000 })
            .catch(() => null),
        input.press('Enter'),
    ]);
    await page
        .waitForSelector('tr.main_artikel_panel_tr_artikel', {
            timeout: 20_000,
        })
        .catch(() =>
            console.error('No article rows appeared (possibly zero results).'),
        );
    // ERP price/stock fragments load async after the article list renders.
    await page.waitForTimeout(5_000);
}

async function extract(
    page: Page,
    warehouse: string,
): Promise<ZitaniaVariant[]> {
    return page.evaluate(async (warehouseMatch: string) => {
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

        const rows: ArticleRow[] = [];
        let currentBrand = '';

        for (const row of document.querySelectorAll('tr')) {
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

            rows.push({
                brandName: currentBrand,
                traderArticleNumber: numberLines[0] ?? '',
                articleNumber: numberLines[1] ?? numberLines[0] ?? '',
                retailPriceText: priceText.replace(/\s+/g, ' ').trim(),
                // Overall "in stock anywhere" flag; used only if the per-branch
                // ERP lookup below is unavailable.
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

        // Query the ERP web service for per-warehouse availability. Its schema
        // exposes an AvailState per branch (1 = available); the numeric
        // Quantity is zeroed for this account, so branch availability is the
        // finest stock signal we can get.
        const svc = (window as unknown as { ErpAppWSVC?: ErpProxy }).ErpAppWSVC;
        const token = (location.search.match(/[?&]10=([^&]+)/) ?? [])[1];
        const lang =
            (window as unknown as { sprachNr?: unknown }).sprachNr ?? 15;
        const requests = rows
            .map((r) => r.request)
            .filter((r): r is Record<string, string> => r !== null);

        // The service rejects large batches (HTTP 500 above ~7 items for this
        // account), so request availability in small chunks. A failed chunk
        // simply leaves its articles on the overall-availability fallback.
        const CHUNK_SIZE = 5;
        const branchAvailByKArt = new Map<string, boolean>();
        if (svc && token) {
            for (let i = 0; i < requests.length; i += CHUNK_SIZE) {
                const chunk = requests.slice(i, i + CHUNK_SIZE);
                await new Promise<void>((resolve) => {
                    const done = setTimeout(resolve, 15_000);
                    svc.GetErpInfosAL(
                        token,
                        chunk,
                        lang,
                        (resp: ErpItem[]) => {
                            clearTimeout(done);
                            for (const item of resp) {
                                const branch = (item.Locations ?? []).find(
                                    (loc) =>
                                        (loc.Text ?? '')
                                            .toUpperCase()
                                            .includes(
                                                warehouseMatch.toUpperCase(),
                                            ),
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
            }
        }

        return rows.map((r) => {
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
        }) as never[];
    }, warehouse);
}

const reference = process.argv[2];
const username = process.env.AUTOZITANIA_USERNAME;
const password = process.env.AUTOZITANIA_PASSWORD;
const entryUrl = process.env.AUTOZITANIA_ENTRY_URL ?? DEFAULT_ENTRY_URL;

if (!reference) {
    fail('Usage: bun bin/zitania-search.ts "<part reference>"');
}
if (!username || !password) {
    fail('Missing AUTOZITANIA_USERNAME / AUTOZITANIA_PASSWORD env vars.');
}

const browser = await chromium.launch({ headless: true });

try {
    const page = await browser.newPage();
    await login(page, entryUrl, username, password);
    await search(page, reference);
    const rows = await extract(page, WAREHOUSE);
    const variants = rows.map(({ retailPriceText, ...row }) => ({
        ...row,
        retailPrice: parsePrice(retailPriceText),
    }));

    console.log(JSON.stringify({ query: reference, variants }, null, 2));
} finally {
    await browser.close();
}
