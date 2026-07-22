import type { Page } from '@cloudflare/playwright';

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

function parsePrice(text: string): number | null {
    const match = text
        .replace(/\s/g, '')
        .match(/(\d+(?:[.,]\d{3})*(?:,\d+)?)EUR/i);

    if (!match) {
        return null;
    }

    return Number.parseFloat(match[1].replaceAll('.', '').replace(',', '.'));
}

export async function login(
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

    // Single concurrent session: confirm takeover when the portal asks.
    const takeover = page.locator('input[name="ok"]');
    if (page.url().includes('Login.aspx') && (await takeover.count()) > 0) {
        await Promise.all([
            page.waitForNavigation({
                waitUntil: 'networkidle',
                timeout: 30_000,
            }),
            takeover.click(),
        ]);
    }

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
        .catch(() => null);
    // ERP price/stock fragments load async after the article list renders.
    await page.waitForTimeout(5_000);
}

export async function extract(
    page: Page,
    warehouse: string,
): Promise<ZitaniaVariant[]> {
    const rows = await page.evaluate(async (warehouseMatch: string) => {
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
    }, warehouse);

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
