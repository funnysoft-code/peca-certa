# Phase 1 — Auto Delta Sourcing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Operator types a part reference and gets a consolidated, sortable table of brand variants with purchase price, retail price and stock, sourced live from Auto Delta — across several independent search tabs at once.

**Architecture:** Auto Delta is a thin SPA over TecAlliance's **WebCat30 JSON API** (de-risked — see `obsidian-vault/projects/r2cz-auto/spike-autodelta-tecalliance.md`). A stateless `AutoDeltaClient` service logs in once, caches the 24h `apiKey` in Laravel cache, and replays two JSON-RPC calls: `searchByNumber` (number → brand variants) and `getTradePrices` (variants → price + stock). An Action merges them into typed DTOs. A JSON endpoint serves each search; the React workspace runs N independent tabs via Inertia v3's `useHttp`, so one slow query never blocks another.

**Tech Stack:** Laravel 13, PHP 8.5, Laravel `Http` client (Guzzle), Octane, Inertia 3 + React 19, Tailwind 4, shadcn/ui, spatie/laravel-typescript-transformer (`#[TypeScript]` DTOs), Pest 5, Wayfinder.

## Global Constraints

- `declare(strict_types=1)` at the top of every PHP file.
- All classes `final` (or `final readonly` for stateless ones).
- Constructor property promotion for all injected dependencies.
- Explicit return types on every method.
- Actions: `app/Actions`, `final readonly`, single `execute()` method, no suffix.
- `env()` only inside `config/` files; everywhere else `config('key')`.
- Never `DB::` for queries; use `Model::query()`. (No models in Phase 1.)
- **Octane safety:** no request state in static properties or singletons. Tokens live in `Cache`, never in object properties retained across requests.
- Quality gate must pass before any "done" claim: `bin/quality-gate.sh` (Rector → Pint → PHPStan max → Wayfinder drift → bun lint/types → Pest). Run `vendor/bin/pint --dirty --format agent` after editing PHP.
- After any controller/route change: `php artisan wayfinder:generate --with-form`.
- After adding/editing a `#[TypeScript]` DTO: `php artisan typescript:transform`.
- Secrets: the Auto Delta credential lives in `.env` (single shared account); never log it or the apiKey.

### Known API contract (from the spike — fully captured, treat as ground truth)

Three endpoints on `https://webservice.tecalliance.services`, all `POST`, all
authenticated with the **same headers**: `content-type: application/json`,
`x-api-key: <from login>`, `x-catalog: <static catalog id>`,
`x-catalog-user: <from login>`.

**1. Auth** — `/auth/v1/services/AuthWS.jsonEndpoint`
→ `{"apiKey":"...","expiresOn":"2026-07-01T17:10:23Z","catalogUserId":"...","catalogTecDocId":1066,"status":200}`
(apiKey is `Dynamic`, ~24h TTL; `catalogTecDocId` = the `provider` used in search).

**2. Search (number → brand variants)** — `/pegasus-3-0/services/TecdocToCatDLB.jsonEndpoint`, method `getArticles`:
```json
{"getArticles":{"applyDqmRules":true,"articleCountry":"PT","provider":1066,
 "lang":"pt","searchQuery":"OC90","searchMatchType":"exact","searchType":10,
 "page":1,"perPage":200,
 "sort":[{"field":"mfrName","direction":"asc"},{"field":"linkageSortNum","direction":"asc"}]}}
```
Response: `{"totalMatchingArticles":117,"maxAllowedPage":1,"articles":[ ... ],"genericArticleFacets":{...},"status":200}`.
Each `articles[]` element (lean request): `dataSupplierId` (int), `articleNumber` (str), `mfrId` (int), `mfrName` (str — the **brand**), `searchQueryMatches`. NOTE: `traderArticleNumber` is **empty here** — it comes from the price row (endpoint 3). The richer fields (`genericArticles[].genericArticleDescription`, `oemNumbers[]`, `misc`) only appear when include-flags are added to the request (1.35MB response) — deferred to Phase 2 (identification/cross-refs); Phase 1's sourcing table needs only brand + article.
> The webshop also sends a `filterQueries:["(dataSupplierId IN (1,2,3,…))"]` allowlist of the dealer's carried brands. Phase 1 omits it (see ponytail note) and filters results down to those that have a price.

**3. Prices/stock (variants → price + stock)** — `/webcat30/v1/services/WebCat30WS.jsonEndpoint`, method `getTradePrices`:
```json
{"getTradePrices":{"lang":"pt","countryCode":"PT","articles":[{"dataSupplierId":156,"articleNumber":"FO-398S","quantity":1}]}}
```
Response: `{"data":{"array":[ row, ... ]},"status":200}`. Each row: `{"dataSupplierId","mfrId","articleNumber","traderArticleNumber","currencyCode","price","availableQuantity","stockStatusDescription","stockMatchCode","priceTypeKey":"E"|"V","articleAdditionalInfo"}`. Each article yields **two rows**: `priceTypeKey:"E"` = purchase (Compra, net), `"V"` = retail (PVP, gross).

**Flow:** `getArticles(ref)` → variant list → `getTradePrices(those)` → merge on `dataSupplierId.articleNumber`.

---

### Task 1: Record live Auto Delta API fixtures + supplier config

The full API contract is already captured (see "Known API contract" above). This task just (a) wires config and (b) saves trimmed real responses as test fixtures so downstream tests run against real data. Fixtures can be produced by re-running the same XHR-hook capture used in the spike (hook `XMLHttpRequest`, search a reference, dump `getArticles` + `getTradePrices` responses), trimmed to ~2-3 articles each.

**Files:**
- Create: `config/suppliers.php`
- Create: `tests/Fixtures/AutoDelta/search-by-number.json` — `{"response": <trimmed getArticles response, ~3 articles>}`
- Create: `tests/Fixtures/AutoDelta/trade-prices.json` — `{"response": <getTradePrices response with E+V rows for those articles>}`
- User edits (instruct, do not edit — `.env` is hook-blocked): add `.env` keys below.

> Fixture file shape: each wraps the real endpoint response under a `"response"` key (tests read `$fixture['response']`). `getArticles` response has top-level `articles[]`; `getTradePrices` response has top-level `data.array[]`.

- [ ] **Step 1: Add supplier config**

```php
<?php

declare(strict_types=1);

return [
    'autodelta' => [
        'auth_url' => env('AUTODELTA_AUTH_URL', 'https://webservice.tecalliance.services/auth/v1/services/AuthWS.jsonEndpoint'),
        'catalog_url' => env('AUTODELTA_CATALOG_URL', 'https://webservice.tecalliance.services/webcat30/v1/services/WebCat30WS.jsonEndpoint'),
        'search_url' => env('AUTODELTA_SEARCH_URL', 'https://webservice.tecalliance.services/pegasus-3-0/services/TecdocToCatDLB.jsonEndpoint'),
        'catalog_id' => env('AUTODELTA_CATALOG_ID'),
        'provider' => (int) env('AUTODELTA_PROVIDER', 1066),
        'username' => env('AUTODELTA_USERNAME'),
        'password' => env('AUTODELTA_PASSWORD'),
        'lang' => env('AUTODELTA_LANG', 'pt'),
        'country' => env('AUTODELTA_COUNTRY', 'PT'),
    ],
];
```

- [ ] **Step 2: Tell the user to add these `.env` keys** (hook blocks editing `.env`; the user pastes them). The catalog id from the spike is `BKPJZtiED4SrFa71ZynAq`:

```
AUTODELTA_CATALOG_ID=BKPJZtiED4SrFa71ZynAq
AUTODELTA_USERNAME=r2czauto
AUTODELTA_PASSWORD=<rotate-then-set>
```

- [ ] **Step 3: Capture the real interactions.** Use mitmproxy (or Charles/Proxyman) with the operator's browser, log into `https://web.tecalliance.net/autodelta/pt`, run a part-number search (e.g. `OC90`), and save the raw request+response JSON for: the AuthWS login, the first `WebCat30WS` call that turns the number into the article list (this is `searchByNumber`), and the `getTradePrices` call. Save each under `tests/Fixtures/AutoDelta/`. In `README.md`, write the exact JSON-RPC method name and params of the number-search call and the field names of each returned article (must include the mfr/brand **name**, `dataSupplierId`, `mfrId`, `articleNumber`, `traderArticleNumber`).

- [ ] **Step 4: Sanity-check the fixtures parse**

Run: `php -r 'foreach (glob("tests/Fixtures/AutoDelta/*.json") as $f) { json_decode(file_get_contents($f), true, 512, JSON_THROW_ON_ERROR); echo "$f ok\n"; }'`
Expected: each file prints `ok`.

- [ ] **Step 5: Commit**

```bash
git add config/suppliers.php tests/Fixtures/AutoDelta
git commit -m "chore: add Auto Delta supplier config and recorded API fixtures"
```

---

### Task 2: AutoDeltaClient — login + cached 24h token

A stateless service that authenticates and caches the apiKey/catalogUserId until expiry.

**Files:**
- Create: `app/Services/AutoDelta/AutoDeltaToken.php`
- Create: `app/Services/AutoDelta/AutoDeltaClient.php`
- Test: `tests/Feature/AutoDelta/AutoDeltaClientAuthTest.php`

**Interfaces:**
- Produces: `AutoDeltaClient::token(): AutoDeltaToken` — logs in if no valid cached token, else returns cached. `AutoDeltaToken` is `final readonly` with `public string $apiKey`, `public string $catalogUserId`, `public CarbonInterface $expiresOn`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Services\AutoDelta\AutoDeltaClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('logs in once and caches the token until expiry', function (): void {
    config()->set('suppliers.autodelta.auth_url', 'https://auth.test/AuthWS');
    config()->set('suppliers.autodelta.catalog_id', 'CAT123');
    config()->set('suppliers.autodelta.username', 'u');
    config()->set('suppliers.autodelta.password', 'p');

    Http::fake([
        'auth.test/*' => Http::response([
            'apiKey' => 'KEY-1',
            'catalogUserId' => 'USER-1',
            'expiresOn' => now()->addDay()->toIso8601String(),
            'status' => 200,
        ]),
    ]);

    $client = app(AutoDeltaClient::class);

    $first = $client->token();
    $second = $client->token();

    expect($first->apiKey)->toBe('KEY-1')
        ->and($first->catalogUserId)->toBe('USER-1')
        ->and($second->apiKey)->toBe('KEY-1');

    Http::assertSentCount(1); // cached: only one login
});

it('re-logs in when the cached token has expired', function (): void {
    config()->set('suppliers.autodelta.auth_url', 'https://auth.test/AuthWS');
    config()->set('suppliers.autodelta.username', 'u');
    config()->set('suppliers.autodelta.password', 'p');
    config()->set('suppliers.autodelta.catalog_id', 'CAT123');

    Cache::put('autodelta.token', new App\Services\AutoDelta\AutoDeltaToken('OLD', 'USER-OLD', now()->subMinute()), now()->addDay());

    Http::fake(['auth.test/*' => Http::response([
        'apiKey' => 'KEY-2', 'catalogUserId' => 'USER-2',
        'expiresOn' => now()->addDay()->toIso8601String(), 'status' => 200,
    ])]);

    expect(app(AutoDeltaClient::class)->token()->apiKey)->toBe('KEY-2');
    Http::assertSentCount(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/AutoDelta/AutoDeltaClientAuthTest.php`
Expected: FAIL — class `App\Services\AutoDelta\AutoDeltaToken` not found.

- [ ] **Step 3: Implement the token value object**

```php
<?php

declare(strict_types=1);

namespace App\Services\AutoDelta;

use Carbon\CarbonInterface;

final readonly class AutoDeltaToken
{
    public function __construct(
        public string $apiKey,
        public string $catalogUserId,
        public CarbonInterface $expiresOn,
    ) {}

    public function isValid(): bool
    {
        return $this->expiresOn->isFuture();
    }
}
```

- [ ] **Step 4: Implement the client login + cache**

```php
<?php

declare(strict_types=1);

namespace App\Services\AutoDelta;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class AutoDeltaClient
{
    private const CACHE_KEY = 'autodelta.token';

    public function token(): AutoDeltaToken
    {
        $cached = Cache::get(self::CACHE_KEY);

        if ($cached instanceof AutoDeltaToken && $cached->isValid()) {
            return $cached;
        }

        $token = $this->login();

        Cache::put(self::CACHE_KEY, $token, $token->expiresOn);

        return $token;
    }

    private function login(): AutoDeltaToken
    {
        $response = Http::asJson()
            ->post((string) config('suppliers.autodelta.auth_url'), [
                'username' => (string) config('suppliers.autodelta.username'),
                'password' => (string) config('suppliers.autodelta.password'),
            ])
            ->throw()
            ->json();

        return new AutoDeltaToken(
            apiKey: (string) $response['apiKey'],
            catalogUserId: (string) $response['catalogUserId'],
            expiresOn: Carbon::parse((string) $response['expiresOn']),
        );
    }
}
```

> NOTE: confirm the exact login request body against `tests/Fixtures/AutoDelta/login.json` from Task 1. If login is two calls (authenticate → apiKey, then context → catalogUserId), add the second `Http::post` here using the apiKey header `x-api-key`; the test's `assertSentCount` becomes 2.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/AutoDelta/AutoDeltaClientAuthTest.php`
Expected: PASS (2 passed).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/AutoDelta tests/Feature/AutoDelta/AutoDeltaClientAuthTest.php
git commit -m "feat: Auto Delta client login with 24h token cache"
```

---

### Task 3: AutoDeltaClient — searchByNumber + getTradePrices

Add the two catalog calls, authenticated with the cached token headers.

**Files:**
- Modify: `app/Services/AutoDelta/AutoDeltaClient.php`
- Test: `tests/Feature/AutoDelta/AutoDeltaCatalogTest.php`

**Interfaces:**
- Produces:
  - `AutoDeltaClient::searchByNumber(string $reference): array` — calls `getArticles` on the **search_url** (pegasus); returns `list<array{dataSupplierId:int, mfrId:int, brandName:string, articleNumber:string}>`. (The lean request omits include-flags → no `genericArticles`/`oemNumbers`; add those in Phase 2 for identification/cross-refs.)
  - `AutoDeltaClient::getTradePrices(array $articles): array` — calls `getTradePrices` on the **catalog_url** (webcat30); `$articles` is the list above; returns `list<array{dataSupplierId:int, articleNumber:string, traderArticleNumber:string, priceTypeKey:string, price:float, currencyCode:string, availableQuantity:int, stockStatusDescription:string, stockMatchCode:string}>` (the raw E/V rows).
  - Both share a private `call(string $url, array $body): array` that attaches the auth headers.

- [ ] **Step 1: Write the failing test** (uses the recorded fixtures from Task 1)

```php
<?php

declare(strict_types=1);

use App\Services\AutoDelta\AutoDeltaClient;
use App\Services\AutoDelta\AutoDeltaToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('suppliers.autodelta.catalog_url', 'https://cat.test/WebCat30WS');
    config()->set('suppliers.autodelta.search_url', 'https://cat.test/Tecdoc');
    config()->set('suppliers.autodelta.catalog_id', 'CAT123');
    config()->set('suppliers.autodelta.provider', 1066);
    Cache::put('autodelta.token', new AutoDeltaToken('KEY', 'USER', now()->addDay()), now()->addDay());
});

it('fetches trade prices and sends the auth headers', function (): void {
    $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/AutoDelta/trade-prices.json')), true);

    Http::fake(['cat.test/*' => Http::response($fixture['response'])]);

    $rows = app(AutoDeltaClient::class)->getTradePrices([
        ['dataSupplierId' => 156, 'articleNumber' => 'FO-398S'],
    ]);

    expect($rows)->not->toBeEmpty()
        ->and($rows[0])->toHaveKeys(['dataSupplierId', 'articleNumber', 'priceTypeKey', 'price']);

    Http::assertSent(fn ($req) => $req->hasHeader('x-api-key', 'KEY')
        && $req->hasHeader('x-catalog', 'CAT123')
        && $req->hasHeader('x-catalog-user', 'USER'));
});

it('searches by number and returns brand variants', function (): void {
    $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/AutoDelta/search-by-number.json')), true);

    Http::fake(['cat.test/*' => Http::response($fixture['response'])]);

    $articles = app(AutoDeltaClient::class)->searchByNumber('OC90');

    expect($articles)->not->toBeEmpty()
        ->and($articles[0])->toHaveKeys(['dataSupplierId', 'mfrId', 'brandName', 'articleNumber']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/AutoDelta/AutoDeltaCatalogTest.php`
Expected: FAIL — `searchByNumber`/`getTradePrices` not defined.

- [ ] **Step 3: Implement both methods** (add to `AutoDeltaClient`)

```php
    /**
     * @return list<array<string, mixed>>
     */
    public function searchByNumber(string $reference): array
    {
        $response = $this->call((string) config('suppliers.autodelta.search_url'), [
            'getArticles' => [
                'applyDqmRules' => true,
                'articleCountry' => (string) config('suppliers.autodelta.country'),
                'provider' => (int) config('suppliers.autodelta.provider'),
                'lang' => (string) config('suppliers.autodelta.lang'),
                'searchQuery' => $reference,
                'searchMatchType' => 'exact',
                'searchType' => 10,
                'page' => 1,
                'perPage' => 200,
                'sort' => [
                    ['field' => 'mfrName', 'direction' => 'asc'],
                    ['field' => 'linkageSortNum', 'direction' => 'asc'],
                ],
            ],
        ]);

        return collect($response['articles'] ?? [])
            ->map(fn (array $a): array => [
                'dataSupplierId' => (int) $a['dataSupplierId'],
                'mfrId' => (int) $a['mfrId'],
                'brandName' => (string) ($a['mfrName'] ?? ''),
                'articleNumber' => (string) $a['articleNumber'],
            ])
            ->all();
    }

    /**
     * @param  list<array{dataSupplierId:int, articleNumber:string}>  $articles
     * @return list<array<string, mixed>>
     */
    public function getTradePrices(array $articles): array
    {
        $payload = array_map(fn (array $a): array => [
            'dataSupplierId' => $a['dataSupplierId'],
            'articleNumber' => $a['articleNumber'],
            'quantity' => 1,
        ], $articles);

        $response = $this->call((string) config('suppliers.autodelta.catalog_url'), [
            'getTradePrices' => [
                'lang' => (string) config('suppliers.autodelta.lang'),
                'countryCode' => (string) config('suppliers.autodelta.country'),
                'articles' => $payload,
            ],
        ]);

        return $response['data']['array'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function call(string $url, array $body): array
    {
        $token = $this->token();

        return Http::asJson()
            ->withHeaders([
                'x-api-key' => $token->apiKey,
                'x-catalog' => (string) config('suppliers.autodelta.catalog_id'),
                'x-catalog-user' => $token->catalogUserId,
            ])
            ->post($url, $body)
            ->throw()
            ->json();
    }
```

> NOTE: the pegasus search endpoint is assumed to accept the same `x-api-key`/`x-catalog`/`x-catalog-user` headers as webcat30 (same auth domain). Confirm against the captured request in Task 1; if it needs an extra header, add it in `call()`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/AutoDelta/AutoDeltaCatalogTest.php`
Expected: PASS (2 passed).

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/AutoDelta/AutoDeltaClient.php tests/Feature/AutoDelta/AutoDeltaCatalogTest.php
git commit -m "feat: Auto Delta searchByNumber and getTradePrices calls"
```

---

### Task 4: PartVariant DTO + merge logic

Typed result objects (TypeScript-synced) and the merge that folds the E/V price rows + brand names into one variant per article.

**Files:**
- Create: `app/Data/PartVariant.php`
- Create: `app/Data/PartSearchResult.php`
- Test: `tests/Unit/Data/PartVariantTest.php`

**Interfaces:**
- Produces:
  - `PartVariant` `final readonly`, `#[TypeScript]`: `brandName:string`, `articleNumber:string`, `traderArticleNumber:string`, `purchasePrice:?float`, `retailPrice:?float`, `currency:string`, `availableQuantity:int`, `inStock:bool`, `warehouse:string`.
  - `PartSearchResult` `final readonly`, `#[TypeScript]`: `query:string`, `variants: list<PartVariant>`.
  - `PartVariant::merge(array $articles, array $priceRows): PartSearchResult` static — joins on `dataSupplierId.articleNumber`, maps `priceTypeKey:"E"`→`purchasePrice`, `"V"`→`retailPrice`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Data\PartVariant;

it('merges articles with E/V price rows into one variant each', function (): void {
    $articles = [[
        'dataSupplierId' => 156, 'mfrId' => 2194, 'brandName' => 'JAPANPARTS',
        'articleNumber' => 'FO-398S', 'description' => 'Filtro de óleo',
    ]];
    $priceRows = [
        ['dataSupplierId' => 156, 'articleNumber' => 'FO-398S', 'traderArticleNumber' => 'JFO-398', 'priceTypeKey' => 'E', 'price' => 1.70, 'currencyCode' => 'EUR', 'availableQuantity' => 23, 'stockStatusDescription' => 'em stock', 'stockMatchCode' => '1 - Leiria,'],
        ['dataSupplierId' => 156, 'articleNumber' => 'FO-398S', 'traderArticleNumber' => 'JFO-398', 'priceTypeKey' => 'V', 'price' => 2.26, 'currencyCode' => 'EUR', 'availableQuantity' => 23, 'stockStatusDescription' => 'em stock', 'stockMatchCode' => '1 - Leiria,'],
    ];

    $result = PartVariant::merge($articles, $priceRows);

    expect($result->query)->toBe('')
        ->and($result->variants)->toHaveCount(1);

    $v = $result->variants[0];
    expect($v->brandName)->toBe('JAPANPARTS')
        ->and($v->traderArticleNumber)->toBe('JFO-398')
        ->and($v->purchasePrice)->toBe(1.70)
        ->and($v->retailPrice)->toBe(2.26)
        ->and($v->availableQuantity)->toBe(23)
        ->and($v->inStock)->toBeTrue()
        ->and($v->warehouse)->toBe('1 - Leiria');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Unit/Data/PartVariantTest.php`
Expected: FAIL — `App\Data\PartVariant` not found.

- [ ] **Step 3: Implement the DTOs**

```php
<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class PartVariant
{
    public function __construct(
        public string $brandName,
        public string $articleNumber,
        public string $traderArticleNumber,
        public ?float $purchasePrice,
        public ?float $retailPrice,
        public string $currency,
        public int $availableQuantity,
        public bool $inStock,
        public string $warehouse,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $articles
     * @param  list<array<string, mixed>>  $priceRows
     */
    public static function merge(array $articles, array $priceRows, string $query = ''): PartSearchResult
    {
        $pricesByKey = [];
        foreach ($priceRows as $row) {
            $key = $row['dataSupplierId'].'.'.$row['articleNumber'];
            $pricesByKey[$key][(string) $row['priceTypeKey']] = $row;
        }

        $variants = [];
        foreach ($articles as $a) {
            $key = $a['dataSupplierId'].'.'.$a['articleNumber'];
            $purchase = $pricesByKey[$key]['E'] ?? null;
            $retail = $pricesByKey[$key]['V'] ?? null;
            $any = $purchase ?? $retail;

            $variants[] = new self(
                brandName: (string) $a['brandName'],
                articleNumber: (string) $a['articleNumber'],
                traderArticleNumber: (string) ($any['traderArticleNumber'] ?? ''),
                purchasePrice: $purchase !== null ? (float) $purchase['price'] : null,
                retailPrice: $retail !== null ? (float) $retail['price'] : null,
                currency: (string) ($any['currencyCode'] ?? 'EUR'),
                availableQuantity: (int) ($any['availableQuantity'] ?? 0),
                inStock: (int) ($any['availableQuantity'] ?? 0) > 0,
                warehouse: trim((string) ($any['stockMatchCode'] ?? ''), " ,\t\n"),
            );
        }

        return new PartSearchResult($query, $variants);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class PartSearchResult
{
    /**
     * @param  list<PartVariant>  $variants
     */
    public function __construct(
        public string $query,
        public array $variants,
    ) {}
}
```

- [ ] **Step 4: Run test + generate TS types**

Run: `php artisan test --compact tests/Unit/Data/PartVariantTest.php`
Expected: PASS.
Run: `php artisan typescript:transform`
Expected: `resources/js/types/generated.d.ts` now contains `PartVariant` and `PartSearchResult`.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Data tests/Unit/Data/PartVariantTest.php resources/js/types/generated.d.ts
git commit -m "feat: PartVariant/PartSearchResult DTOs with E/V price merge"
```

---

### Task 5: SearchAutoDeltaParts action

Orchestrates: search → prices → merge.

**Files:**
- Create: `app/Actions/SearchAutoDeltaParts.php`
- Test: `tests/Feature/Parts/SearchAutoDeltaPartsTest.php`

**Interfaces:**
- Consumes: `AutoDeltaClient::searchByNumber`, `AutoDeltaClient::getTradePrices`, `PartVariant::merge`.
- Produces: `SearchAutoDeltaParts::execute(string $reference): PartSearchResult`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Actions\SearchAutoDeltaParts;
use App\Services\AutoDelta\AutoDeltaClient;
use App\Services\AutoDelta\AutoDeltaToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('returns merged variants for a reference', function (): void {
    config()->set('suppliers.autodelta.catalog_url', 'https://cat.test/WebCat30WS');
    config()->set('suppliers.autodelta.search_url', 'https://cat.test/Tecdoc');
    config()->set('suppliers.autodelta.catalog_id', 'CAT');
    config()->set('suppliers.autodelta.provider', 1066);
    Cache::put('autodelta.token', new AutoDeltaToken('KEY', 'USER', now()->addDay()), now()->addDay());

    $search = json_decode((string) file_get_contents(base_path('tests/Fixtures/AutoDelta/search-by-number.json')), true);
    $prices = json_decode((string) file_get_contents(base_path('tests/Fixtures/AutoDelta/trade-prices.json')), true);

    // First call → search (getArticles) response, second → prices response.
    Http::fakeSequence('cat.test/*')
        ->push($search['response'])
        ->push($prices['response']);

    $result = app(SearchAutoDeltaParts::class)->execute('OC90');

    expect($result->query)->toBe('OC90')
        ->and($result->variants)->not->toBeEmpty()
        ->and($result->variants[0]->brandName)->not->toBe('');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/Parts/SearchAutoDeltaPartsTest.php`
Expected: FAIL — `App\Actions\SearchAutoDeltaParts` not found.

- [ ] **Step 3: Implement the action** (`php artisan make:action SearchAutoDeltaParts --no-interaction`, then)

```php
<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\PartSearchResult;
use App\Data\PartVariant;
use App\Services\AutoDelta\AutoDeltaClient;

final readonly class SearchAutoDeltaParts
{
    public function __construct(
        private AutoDeltaClient $client,
    ) {}

    public function execute(string $reference): PartSearchResult
    {
        $articles = $this->client->searchByNumber($reference);

        if ($articles === []) {
            return new PartSearchResult($reference, []);
        }

        $prices = $this->client->getTradePrices($articles);

        return PartVariant::merge($articles, $prices, $reference);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/Parts/SearchAutoDeltaPartsTest.php`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Actions/SearchAutoDeltaParts.php tests/Feature/Parts/SearchAutoDeltaPartsTest.php
git commit -m "feat: SearchAutoDeltaParts action"
```

---

### Task 6: Search endpoint (page + JSON route)

A page route for the workspace and a JSON route each tab calls via `useHttp`.

**Files:**
- Create: `app/Http/Controllers/PartSearchController.php`
- Create: `app/Http/Requests/SearchPartsRequest.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Parts/PartSearchEndpointTest.php`

**Interfaces:**
- Consumes: `SearchAutoDeltaParts::execute`.
- Produces: `GET /parts` (name `parts.index`) → Inertia page `parts/index`; `POST /parts/search` (name `parts.search`) → JSON `PartSearchResult`. Both behind `auth`,`verified`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Services\AutoDelta\AutoDeltaToken;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('requires auth for the search endpoint', function (): void {
    $this->postJson('/parts/search', ['reference' => 'OC90'])->assertUnauthorized();
});

it('returns merged variants as json', function (): void {
    config()->set('suppliers.autodelta.catalog_url', 'https://cat.test/WebCat30WS');
    config()->set('suppliers.autodelta.search_url', 'https://cat.test/Tecdoc');
    config()->set('suppliers.autodelta.catalog_id', 'CAT');
    config()->set('suppliers.autodelta.provider', 1066);
    Cache::put('autodelta.token', new AutoDeltaToken('KEY', 'USER', now()->addDay()), now()->addDay());

    $search = json_decode((string) file_get_contents(base_path('tests/Fixtures/AutoDelta/search-by-number.json')), true);
    $prices = json_decode((string) file_get_contents(base_path('tests/Fixtures/AutoDelta/trade-prices.json')), true);
    Http::fakeSequence('cat.test/*')->push($search['response'])->push($prices['response']);

    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->postJson('/parts/search', ['reference' => 'OC90'])
        ->assertOk()
        ->assertJsonStructure(['query', 'variants' => [['brandName', 'articleNumber', 'purchasePrice', 'retailPrice', 'availableQuantity', 'inStock']]]);
});

it('validates the reference is present', function (): void {
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->postJson('/parts/search', ['reference' => ''])
        ->assertJsonValidationErrorFor('reference');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/Parts/PartSearchEndpointTest.php`
Expected: FAIL — route `/parts/search` not defined (404/405).

- [ ] **Step 3: Create the form request** (`php artisan make:request SearchPartsRequest --no-interaction`)

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SearchPartsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:100'],
        ];
    }
}
```

- [ ] **Step 4: Create the controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SearchAutoDeltaParts;
use App\Data\PartSearchResult;
use App\Http\Requests\SearchPartsRequest;
use Inertia\Inertia;
use Inertia\Response;

final readonly class PartSearchController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('parts/index');
    }

    public function search(SearchPartsRequest $request, SearchAutoDeltaParts $action): PartSearchResult
    {
        return $action->execute((string) $request->validated('reference'));
    }
}
```

> NOTE: returning a `#[TypeScript]` DTO from a controller serialises its public properties to JSON automatically (PHP `JsonSerializable` is not needed for readonly classes with public props — Laravel casts via `json_encode`). If the JSON comes back empty, add `implements \JsonSerializable` to the DTOs with a `jsonSerialize(): array` returning `get_object_vars($this)`.

- [ ] **Step 5: Register routes** (in `routes/web.php`, inside the existing `auth`,`verified` group)

```php
    Route::get('parts', [App\Http\Controllers\PartSearchController::class, 'index'])->name('parts.index');
    Route::post('parts/search', [App\Http\Controllers\PartSearchController::class, 'search'])->name('parts.search');
```

- [ ] **Step 6: Run tests + regenerate Wayfinder**

Run: `php artisan test --compact tests/Feature/Parts/PartSearchEndpointTest.php`
Expected: PASS (3 passed).
Run: `php artisan wayfinder:generate --with-form`
Expected: typed route fns for `parts.index`/`parts.search` appear under `resources/js/`.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/PartSearchController.php app/Http/Requests/SearchPartsRequest.php routes/web.php resources/js
git commit -m "feat: parts search page + JSON endpoint"
```

---

### Task 7: Parts workspace UI — multi-tab async search

The operator-facing screen: a tab bar of independent searches, each with its own input, loading/empty/error state, and a sortable results table. Tabs are client-side state; each fires `useHttp` to `parts.search` so a slow tab never blocks another.

**Files:**
- Create: `resources/js/pages/parts/index.tsx`
- Create: `resources/js/components/parts/search-tab.tsx`
- Create: `resources/js/components/parts/results-table.tsx`
- Test: `tests/Browser/Parts/SearchWorkspaceTest.php`

**Interfaces:**
- Consumes: generated `PartSearchResult`/`PartVariant` types from `@/types/generated`; Wayfinder `parts.search` route fn; Inertia v3 `useHttp`.

- [ ] **Step 1: Build the results table** (shadcn `table` — add via `bunx shadcn@latest add table` if absent)

```tsx
import type { PartVariant } from '@/types/generated';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';

export function ResultsTable({ variants }: { variants: PartVariant[] }) {
    if (variants.length === 0) {
        return <p className="text-muted-foreground py-8 text-center text-sm">Sem resultados.</p>;
    }

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Marca</TableHead>
                    <TableHead>Artigo</TableHead>
                    <TableHead className="text-right">Compra</TableHead>
                    <TableHead className="text-right">PVP</TableHead>
                    <TableHead className="text-right">Stock</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {variants.map((v) => (
                    <TableRow key={`${v.brandName}-${v.articleNumber}`}>
                        <TableCell className="font-medium">{v.brandName}</TableCell>
                        <TableCell>{v.articleNumber}</TableCell>
                        <TableCell className="text-right tabular-nums">{v.purchasePrice?.toFixed(2) ?? '—'}</TableCell>
                        <TableCell className="text-right tabular-nums">{v.retailPrice?.toFixed(2) ?? '—'}</TableCell>
                        <TableCell className="text-right tabular-nums">
                            <span className={v.inStock ? 'text-emerald-600' : 'text-muted-foreground'}>{v.availableQuantity}</span>
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}
```

- [ ] **Step 2: Build a single search tab** (own input + state + `useHttp` call)

```tsx
import { useState } from 'react';
import { useHttp } from '@inertiajs/react';
import type { PartSearchResult } from '@/types/generated';
import { search as searchRoute } from '@/routes/parts';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { ResultsTable } from './results-table';

export function SearchTab() {
    const [reference, setReference] = useState('');
    const [result, setResult] = useState<PartSearchResult | null>(null);
    const http = useHttp();
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function run() {
        if (!reference.trim()) return;
        setLoading(true);
        setError(null);
        try {
            const res = await http.post<PartSearchResult>(searchRoute.url(), { reference });
            setResult(res.data);
        } catch {
            setError('Falha na pesquisa. Tente novamente.');
        } finally {
            setLoading(false);
        }
    }

    return (
        <div className="space-y-4">
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    void run();
                }}
                className="flex gap-2"
            >
                <Input value={reference} onChange={(e) => setReference(e.target.value)} placeholder="Referência da peça" autoFocus />
                <Button type="submit" disabled={loading}>
                    {loading ? 'A pesquisar…' : 'Pesquisar'}
                </Button>
            </form>

            {error && <p className="text-destructive text-sm">{error}</p>}
            {loading && <div className="bg-muted h-32 animate-pulse rounded-md" />}
            {!loading && result && <ResultsTable variants={result.variants} />}
        </div>
    );
}
```

> NOTE: confirm Inertia v3's `useHttp` return shape against the installed version (`search-docs` query `useHttp`). If the hook returns the body directly rather than `{ data }`, adjust `res.data` → `res`.

- [ ] **Step 3: Build the multi-tab page**

```tsx
import { useState } from 'react';
import { Head } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Plus, X } from 'lucide-react';
import { SearchTab } from '@/components/parts/search-tab';

let nextId = 1;

export default function PartsIndex() {
    const [tabs, setTabs] = useState([{ id: 0 }]);
    const [active, setActive] = useState(0);

    function addTab() {
        const id = nextId++;
        setTabs((t) => [...t, { id }]);
        setActive(id);
    }

    function closeTab(id: number) {
        setTabs((t) => {
            const next = t.filter((x) => x.id !== id);
            if (active === id && next.length) setActive(next[next.length - 1].id);
            return next.length ? next : [{ id: 0 }];
        });
    }

    return (
        <>
            <Head title="Pesquisa de peças" />
            <div className="p-6">
                <div className="mb-4 flex items-center gap-1 border-b">
                    {tabs.map((tab, i) => (
                        <button
                            key={tab.id}
                            onClick={() => setActive(tab.id)}
                            className={`flex items-center gap-2 rounded-t-md px-4 py-2 text-sm ${active === tab.id ? 'bg-muted font-medium' : 'text-muted-foreground'}`}
                        >
                            Pesquisa {i + 1}
                            {tabs.length > 1 && <X className="size-3.5" onClick={(e) => { e.stopPropagation(); closeTab(tab.id); }} />}
                        </button>
                    ))}
                    <Button variant="ghost" size="icon" onClick={addTab} className="ml-1">
                        <Plus className="size-4" />
                    </Button>
                </div>

                {/* Each tab stays mounted so its results persist while another searches. */}
                {tabs.map((tab) => (
                    <div key={tab.id} hidden={active !== tab.id}>
                        <SearchTab />
                    </div>
                ))}
            </div>
        </>
    );
}
```

- [ ] **Step 4: Lint + type-check**

Run: `bun run lint && bun run test:types`
Expected: no errors. (Add shadcn `input`/`button`/`table` via `bunx shadcn@latest add <name>` if a type error reports a missing component.)

- [ ] **Step 5: Write the browser test**

```php
<?php

declare(strict_types=1);

use App\Services\AutoDelta\AutoDeltaToken;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('searches a part and shows variants in a tab', function (): void {
    config()->set('suppliers.autodelta.catalog_url', 'https://cat.test/WebCat30WS');
    config()->set('suppliers.autodelta.search_url', 'https://cat.test/Tecdoc');
    config()->set('suppliers.autodelta.catalog_id', 'CAT');
    config()->set('suppliers.autodelta.provider', 1066);
    Cache::put('autodelta.token', new AutoDeltaToken('KEY', 'USER', now()->addDay()), now()->addDay());

    $search = json_decode((string) file_get_contents(base_path('tests/Fixtures/AutoDelta/search-by-number.json')), true);
    $prices = json_decode((string) file_get_contents(base_path('tests/Fixtures/AutoDelta/trade-prices.json')), true);
    Http::fakeSequence('cat.test/*')->push($search['response'])->push($prices['response']);

    $user = User::factory()->create(['email_verified_at' => now()]);

    $page = visit('/parts')->actingAs($user);
    $page->fill('input[placeholder="Referência da peça"]', 'OC90')
        ->press('Pesquisar')
        ->waitForText('Compra')
        ->assertSee('PVP');
});
```

- [ ] **Step 6: Run the browser test**

Run: `php artisan test --compact tests/Browser/Parts/SearchWorkspaceTest.php`
Expected: PASS.

- [ ] **Step 7: Add nav link + commit**

Add a "Pesquisa de peças" link to the authenticated nav (follow the pattern in `resources/js/components/` used by the `dashboard` link), then:

```bash
vendor/bin/pint --dirty --format agent
git add resources/js tests/Browser/Parts
git commit -m "feat: multi-tab parts search workspace"
```

---

### Final verification

- [ ] Run the full quality gate: `bin/quality-gate.sh`
  Expected: exit 0. (Exit-code map in `.claude/CLAUDE.md`: `2`→pint, `5`→vp lint, `6`→tsc, `7`→wayfinder, `9`→rector.)
- [ ] Manually drive `/parts` with `dev-browser` against the real API (token from a live login), confirm a real reference (e.g. `OC90`) returns variants, and open a second tab to confirm independent searches.

---

## Ponytail simplifications (deliberate, with upgrade paths)

- **Single credential in `.env`, not a DB vault.** One shared Auto Delta account → `config('suppliers.autodelta.*')`. Upgrade to the design's encrypted multi-account vault at **Phase 1.5** when Auto Zitania + Europeças add more credentials.
- **Synchronous JSON endpoint, no queue/stream.** Real calls are ~250 ms; per-tab `useHttp` already gives async, non-blocking parallelism. Add queued/streamed search only if a portal proves slow.
- **No persistence / search history.** Phase 1 is stateless lookup. History is Phase 3.
- **`searchByNumber` against one WebCat30 method.** If Task 1 shows the number-search needs a vehicle context or a different method for some number types, handle only the plain-number case now; branch later.

## Out of scope (later phases)

Plate→VIN, LLM request understanding, PartsLink24, make→portal routing, photo/vision, Auto Zitania + Europeças adapters (Phase 1.5).
