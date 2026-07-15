# PartsLink24 Real Client (Plan 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the `FakePartsLink24Catalog` with a real headless HTTP client that resolves a VIN + English part term into genuine (OE) part numbers via PartsLink24, so `/identify` returns real vehicle-specific parts instead of the hardcoded `OC 90`.

**Architecture:** Pure-HTTP JSON flow (no browser), mirroring `AutoDeltaClient`'s token-cache pattern. Login (`/pl24-appgtw/ext/api/1.0/login` with `squeezeOut:true` to take over any stale single session) → authorize (`/auth/ext/api/1.1/authorize` → short-lived Bearer JWT, cached) → VIN-scoped part search (`/p5<group>/extern/search/vin?q=<term>`). A deterministic VIN-WMI → brand-catalog map (scoped to the brands PartsLink24 offers) routes each VIN to the correct catalog. Grok gains an English `searchTerm` field because the catalog search index is English-only.

**Tech Stack:** Laravel 13, Guzzle (via `Illuminate\Support\Facades\Http`), `laravel/ai` (Grok structured output), Pest 5, PHPStan max.

## Global Constraints

- `declare(strict_types=1)` at the top of every PHP file; all classes `final` (`final readonly` for stateless).
- `env()` only inside `config/`; everywhere else `config()` (use typed `config()->string(...)`, `config()->integer(...)`).
- Actions: `final readonly`, single `execute()`, never `handle()`. DTOs: `final readonly implements JsonSerializable`, `#[TypeScript]` where consumed by the frontend.
- Never call `DB::` for queries; not relevant here (no DB), but no raw SQL.
- Secrets via `config('suppliers.partslink24.*')`, never `env()` directly, never logged. Mark the password argument `#[\SensitiveParameter]` wherever it is passed.
- No em dashes in any output (code, comments, commits): use commas, periods, or parentheses.
- Octane-safe: no mutable state on singletons/statics; the token lives in `Cache`, cookie jars are created per-call as locals.
- Quality gate must pass: `bin/quality-gate.sh` (rector, pint, phpstan max, wayfinder drift, `bun test:types`, 100% code + type coverage, Pest browser). Run it with `PAO_DISABLE=1`.
- After touching `app/Data/` or the agent schema, run `php artisan typescript:transform` and keep `resources/js/types/generated.d.ts` in sync.
- The single PartsLink24 session is shared with the operator; the client always logs in with `squeezeOut:true` so it takes over rather than erroring. This is a deliberate product decision (the app is allowed to evict; a dedicated account is the operational fix).

---

### Task 1: Add an English `searchTerm` to the request understanding

Grok returns a Portuguese `category` for display, but the PartsLink24 search index is English-only (verified: `q="oil filter"` returns matches, `q="filtro de óleo"` returns none). Add an English `searchTerm` the OE lookup will use.

**Files:**
- Modify: `app/Data/PartRequestUnderstanding.php`
- Modify: `app/Ai/Agents/PartRequestUnderstander.php`
- Modify: `app/Actions/UnderstandPartRequest.php`
- Test: `tests/Feature/Actions/UnderstandPartRequestTest.php` (existing — extend)
- Test: `tests/Unit/Data/PartRequestUnderstandingTest.php` (existing — extend if present; otherwise cover via the action test)

**Interfaces:**
- Produces: `PartRequestUnderstanding` gains `public string $searchTerm` (English part term, e.g. `"oil filter"`) as the 2nd constructor argument, after `category`. Consumed by Task 7 (`IdentifyAndSourceParts`).

- [ ] **Step 1: Read the current DTO and agent** to preserve constructor order and the `needsClarification()`/`jsonSerialize()` shape.

Run: read `app/Data/PartRequestUnderstanding.php`, `app/Ai/Agents/PartRequestUnderstander.php`, `app/Actions/UnderstandPartRequest.php`.

- [ ] **Step 2: Extend the DTO** — add `searchTerm` as the second property and to `jsonSerialize()`.

```php
public function __construct(
    public string $category,
    public string $searchTerm,
    /** @var list<string> */
    public array $keywords,
    public ?string $clarifyingQuestion,
    public float $confidence,
) {}
```

Add `'searchTerm' => $this->searchTerm,` to the `jsonSerialize()` array (right after `category`). Keep `needsClarification()` unchanged.

- [ ] **Step 3: Extend the agent schema** — add a required English `searchTerm` and instruct Grok to fill it in English.

In `schema()` add (after `category`):

```php
'searchTerm' => $schema->string()->required(),
```

In `instructions()`, add a sentence (Portuguese, matching the existing prose): the model must also return `searchTerm`, the same part in **English** and in the singular catalog wording an OEM parts catalog would use (e.g. `"oil filter"`, `"brake disc"`, `"timing belt"`), because the OE catalog search is English-only. Do not add an em dash.

- [ ] **Step 4: Coerce `searchTerm` in the action** — mirror the existing string coercion for `category`.

In `UnderstandPartRequest::execute()`, read `$response['searchTerm']`, coerce to string (same helper/pattern used for `category`), and pass it as the 2nd argument to `new PartRequestUnderstanding(...)`. If empty, fall back to `$category`.

- [ ] **Step 5: Update the fake in existing tests** — any test that does `PartRequestUnderstander::fake([...])` with a JSON payload must include `"searchTerm"`, and any direct `new PartRequestUnderstanding(...)` must pass the new 2nd arg. Update `tests/Feature/Actions/UnderstandPartRequestTest.php` accordingly and add one assertion that `searchTerm` is populated (e.g. `->and($result->searchTerm)->toBe('oil filter')`).

- [ ] **Step 6: Run tests + TS transform**

Run: `php artisan test --compact --filter=UnderstandPartRequest`
Expected: PASS.
Run: `php artisan typescript:transform` then confirm `resources/js/types/generated.d.ts` now has `searchTerm` on `PartRequestUnderstanding`.

- [ ] **Step 7: Commit**

```bash
git add app/Data/PartRequestUnderstanding.php app/Ai/Agents/PartRequestUnderstander.php app/Actions/UnderstandPartRequest.php tests/ resources/js/types/generated.d.ts
git commit -m "feat: Grok emits English searchTerm for OE catalog lookup"
```

---

### Task 2: PartsLink24 config (catalog params + WMI brand map)

**Files:**
- Modify: `config/suppliers.php`
- Test: `tests/Feature/Config/PartsLink24ConfigTest.php` (create)

**Interfaces:**
- Produces: `config('suppliers.partslink24.*')` gains `country`, `lang`, `token_ttl_buffer`, `max_candidates`, and `brands` (with `wmi` + `catalogs`). Consumed by Tasks 3, 5, 6.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

it('exposes partslink24 catalog config and a wmi brand map', function (): void {
    expect(config('suppliers.partslink24.lang'))->toBe('en')
        ->and(config('suppliers.partslink24.country'))->toBe('PT')
        ->and(config('suppliers.partslink24.max_candidates'))->toBeInt()
        ->and(config('suppliers.partslink24.brands.wmi.WMW'))->toBe('mini')
        ->and(config('suppliers.partslink24.brands.catalogs.mini'))
            ->toBe(['service' => 'mini_parts', 'group' => 'p5bmw']);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact tests/Feature/Config/PartsLink24ConfigTest.php`
Expected: FAIL (keys missing).

- [ ] **Step 3: Extend the `partslink24` block** in `config/suppliers.php` (keep existing `base_url`/`account`/`username`/`password`/`timeout`):

```php
'partslink24' => [
    'base_url' => env('PARTSLINK24_BASE_URL', 'https://www.partslink24.com'),
    'account' => env('PARTSLINK24_ACCOUNT'),
    'username' => env('PARTSLINK24_USERNAME'),
    'password' => env('PARTSLINK24_PASSWORD'),
    'timeout' => (int) env('PARTSLINK24_TIMEOUT', 30),
    'country' => env('PARTSLINK24_COUNTRY', 'PT'),
    'lang' => env('PARTSLINK24_LANG', 'en'),
    // Seconds shaved off the authorize token TTL before treating it as expired.
    'token_ttl_buffer' => (int) env('PARTSLINK24_TOKEN_TTL_BUFFER', 30),
    // Max distinct OE candidates fed into the Phase 1 pricing fan-out per identify.
    'max_candidates' => (int) env('PARTSLINK24_MAX_CANDIDATES', 5),
    'brands' => [
        // VIN World Manufacturer Identifier (chars 1-3) => brand key.
        'wmi' => [
            'WMW' => 'mini',
            'WBA' => 'bmw', 'WBS' => 'bmw', 'WBY' => 'bmw', 'WBX' => 'bmw', '4US' => 'bmw', '5UX' => 'bmw',
            'WVW' => 'vw', 'WV1' => 'vw', 'WV2' => 'vw', '1VW' => 'vw', '3VW' => 'vw', '9BW' => 'vw', 'WVG' => 'vw',
            'WAU' => 'audi', 'WA1' => 'audi', 'TRU' => 'audi',
            'VSS' => 'seat',
            'TMB' => 'skoda',
            'VF1' => 'renault',
            'UU1' => 'dacia',
            'VF3' => 'peugeot',
            'VF7' => 'citroen',
            'W0L' => 'opel', 'W0V' => 'opel',
            'ZFA' => 'fiat',
            'WDB' => 'mercedes', 'WDD' => 'mercedes', 'WDC' => 'mercedes', 'W1K' => 'mercedes', 'W1N' => 'mercedes',
            'YV1' => 'volvo', 'YV4' => 'volvo',
            'WP0' => 'porsche', 'WP1' => 'porsche',
            'SAJ' => 'jaguar',
            'SAL' => 'landrover',
        ],
        // brand key => PartsLink24 catalog service + p5 group prefix (from the manufacturers endpoint).
        'catalogs' => [
            'mini' => ['service' => 'mini_parts', 'group' => 'p5bmw'],
            'bmw' => ['service' => 'bmw_parts', 'group' => 'p5bmw'],
            'vw' => ['service' => 'vw_parts', 'group' => 'p5vwag'],
            'audi' => ['service' => 'audi_parts', 'group' => 'p5vwag'],
            'seat' => ['service' => 'seat_parts', 'group' => 'p5vwag'],
            'skoda' => ['service' => 'skoda_parts', 'group' => 'p5vwag'],
            'renault' => ['service' => 'renault_parts', 'group' => 'p5renault'],
            'dacia' => ['service' => 'dacia_parts', 'group' => 'p5renault'],
            'peugeot' => ['service' => 'peugeot_parts', 'group' => 'p5psa'],
            'citroen' => ['service' => 'citroen_parts', 'group' => 'p5psa'],
            'opel' => ['service' => 'psa_opel_parts', 'group' => 'p5psa'],
            'fiat' => ['service' => 'fiatp_parts', 'group' => 'p5fiat'],
            'mercedes' => ['service' => 'mercedes_parts', 'group' => 'p5daimler'],
            'volvo' => ['service' => 'volvo_parts', 'group' => 'p5volvo'],
            'porsche' => ['service' => 'porsche_parts', 'group' => 'p5vwag'],
            'jaguar' => ['service' => 'jaguar_parts', 'group' => 'p5jlr'],
            'landrover' => ['service' => 'landrover_parts', 'group' => 'p5jlr'],
        ],
    ],
],
```

Note: only Mini is live-verified (spike). The other brand/group rows follow the same manufacturers-endpoint pattern and are verified per brand as real VINs appear; an unknown WMI resolves to no catalog and yields an empty result (Task 3/6), never a crash.

- [ ] **Step 4: Run the test** — Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add config/suppliers.php tests/Feature/Config/PartsLink24ConfigTest.php
git commit -m "feat: partslink24 catalog config + VIN WMI brand map"
```

---

### Task 3: `PartsLink24Brand` value object + `VinBrandResolver`

**Files:**
- Create: `app/Services/PartsLink24/PartsLink24Brand.php`
- Create: `app/Services/PartsLink24/VinBrandResolver.php`
- Test: `tests/Feature/Services/PartsLink24/VinBrandResolverTest.php`

**Interfaces:**
- Produces:
  - `final readonly class PartsLink24Brand { public function __construct(public string $key, public string $service, public string $group) {} }`
  - `VinBrandResolver::resolve(string $vin): ?PartsLink24Brand` — WMI (uppercased first 3 chars) → brand, or `null` for unknown/short VINs.
- Consumed by Task 5 (`PartsLink24Client` builds URLs/serviceNames from `service` + `group`) and Task 6.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Services\PartsLink24\VinBrandResolver;

it('resolves a Mini VIN to the mini catalog', function (): void {
    $brand = resolve(VinBrandResolver::class)->resolve('WMWSU91010T717700');

    expect($brand)->not->toBeNull()
        ->and($brand->key)->toBe('mini')
        ->and($brand->service)->toBe('mini_parts')
        ->and($brand->group)->toBe('p5bmw');
});

it('resolves a BMW VIN via a shared WMI', function (): void {
    expect(resolve(VinBrandResolver::class)->resolve('WBA12345678901234')?->service)->toBe('bmw_parts');
});

it('returns null for an unknown WMI', function (): void {
    expect(resolve(VinBrandResolver::class)->resolve('ZZZ99999999999999'))->toBeNull();
});

it('returns null for a too-short VIN', function (): void {
    expect(resolve(VinBrandResolver::class)->resolve('WM'))->toBeNull();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact tests/Feature/Services/PartsLink24/VinBrandResolverTest.php`
Expected: FAIL (classes not found).

- [ ] **Step 3: Write `PartsLink24Brand`**

```php
<?php

declare(strict_types=1);

namespace App\Services\PartsLink24;

final readonly class PartsLink24Brand
{
    public function __construct(
        public string $key,
        public string $service,
        public string $group,
    ) {}
}
```

- [ ] **Step 4: Write `VinBrandResolver`**

```php
<?php

declare(strict_types=1);

namespace App\Services\PartsLink24;

use Illuminate\Support\Str;

final readonly class VinBrandResolver
{
    public function resolve(string $vin): ?PartsLink24Brand
    {
        if (Str::length($vin) < 3) {
            return null;
        }

        $wmi = Str::upper(Str::substr($vin, 0, 3));

        /** @var array<string, string> $wmiMap */
        $wmiMap = config('suppliers.partslink24.brands.wmi');
        $key = $wmiMap[$wmi] ?? null;

        if ($key === null) {
            return null;
        }

        /** @var array<string, array{service: string, group: string}> $catalogs */
        $catalogs = config('suppliers.partslink24.brands.catalogs');
        $catalog = $catalogs[$key] ?? null;

        if ($catalog === null) {
            return null;
        }

        return new PartsLink24Brand($key, $catalog['service'], $catalog['group']);
    }
}
```

- [ ] **Step 5: Run the test** — Expected: PASS.

- [ ] **Step 6: Add the service CLAUDE.md note** — append to `app/Services/PartsLink24/CLAUDE.md` a line describing `PartsLink24Brand` + `VinBrandResolver` (VIN WMI → catalog). No em dashes.

- [ ] **Step 7: Commit**

```bash
git add app/Services/PartsLink24/PartsLink24Brand.php app/Services/PartsLink24/VinBrandResolver.php app/Services/PartsLink24/CLAUDE.md tests/Feature/Services/PartsLink24/VinBrandResolverTest.php
git commit -m "feat: PartsLink24 VIN WMI brand resolver"
```

---

### Task 4: `PartsLink24Token` value object

**Files:**
- Create: `app/Services/PartsLink24/PartsLink24Token.php`
- Test: `tests/Unit/Services/PartsLink24/PartsLink24TokenTest.php`

**Interfaces:**
- Produces: `final readonly class PartsLink24Token { public function __construct(public string $accessToken, public CarbonInterface $expiresAt) {} public function isValid(): bool; }` — mirrors `AutoDeltaToken`. Consumed by Task 5.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Services\PartsLink24\PartsLink24Token;
use Illuminate\Support\Facades\Date;

it('is valid while unexpired and invalid once past expiry', function (): void {
    $valid = new PartsLink24Token('jwt', Date::now()->addMinutes(5));
    $expired = new PartsLink24Token('jwt', Date::now()->subSecond());

    expect($valid->isValid())->toBeTrue()
        ->and($expired->isValid())->toBeFalse();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact tests/Unit/Services/PartsLink24/PartsLink24TokenTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Write `PartsLink24Token`**

```php
<?php

declare(strict_types=1);

namespace App\Services\PartsLink24;

use Carbon\CarbonInterface;

final readonly class PartsLink24Token
{
    public function __construct(
        public string $accessToken,
        public CarbonInterface $expiresAt,
    ) {}

    public function isValid(): bool
    {
        return $this->expiresAt->isFuture();
    }
}
```

- [ ] **Step 4: Run the test** — Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/PartsLink24/PartsLink24Token.php tests/Unit/Services/PartsLink24/PartsLink24TokenTest.php
git commit -m "feat: PartsLink24Token value object"
```

---

### Task 5: `PartsLink24Client` (login + authorize + VIN search)

**Files:**
- Create: `app/Services/PartsLink24/PartsLink24Client.php`
- Test: `tests/Feature/Services/PartsLink24/PartsLink24ClientTest.php`
- Fixtures: `tests/Fixtures/PartsLink24/authorize.json`, `tests/Fixtures/PartsLink24/search-oil-filter.json`

**Interfaces:**
- Consumes: `PartsLink24Brand` (Task 3), `PartsLink24Token` (Task 4), config (Task 2).
- Produces:
  - `token(PartsLink24Brand $brand): PartsLink24Token` — cached per brand service (`Cache` keyed `partslink24.token.<service>`); on miss does appgtw login (`squeezeOut:true`) + authorize, using a shared cookie jar across the two calls.
  - `searchByVin(PartsLink24Brand $brand, string $vin, string $query): array<int, array{oe: string, name: string}>` — GET `/{group}/extern/search/vin`, returns raw `{oe, name}` rows (unfiltered, undeduped). Empty array when the catalog returns no records.
- Consumed by Task 6.

- [ ] **Step 1: Create the fixtures** (trimmed from the live spike; enough for `Http::fake`).

`tests/Fixtures/PartsLink24/authorize.json`:
```json
{"access_token":"eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJhZG1pbiJ9.signature","token_type":"Bearer","expires_in":600,"scope":"catalog","session_status":"OK"}
```

`tests/Fixtures/PartsLink24/search-oil-filter.json`:
```json
{"data":{"records":[
  {"recordContext":{"bidata_part_no":"11427557011"},"values":{"partno":"11 42 7 557 011","name":"[Oil] [filter] cover"}},
  {"recordContext":{"bidata_part_no":"11427622446"},"values":{"partno":"11 42 7 622 446","name":"Set [oil]\\-[filter] element"}},
  {"recordContext":{"bidata_part_no":"11427622446"},"values":{"partno":"11 42 7 622 446","name":"Set [oil]\\-[filter] element"}},
  {"recordContext":{"bidata_part_no":"11428643745"},"values":{"partno":"11 42 8 643 745","name":"[Oil] [filter] with plastic cover"}}
]}}
```

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Services\PartsLink24\PartsLink24Brand;
use App\Services\PartsLink24\PartsLink24Client;
use Illuminate\Support\Facades\Http;

function fakePl24(): void
{
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
    ]);

    Http::fake([
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'refreshToken' => 'r', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/search/vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/search-oil-filter.json')), true)),
    ]);
}

it('logs in, authorizes, and returns raw search rows for a VIN', function (): void {
    fakePl24();
    $brand = new PartsLink24Brand('mini', 'mini_parts', 'p5bmw');

    $rows = resolve(PartsLink24Client::class)->searchByVin($brand, 'WMWSU91010T717700', 'oil filter');

    expect($rows)->toHaveCount(4)
        ->and($rows[0])->toBe(['oe' => '11427557011', 'name' => '[Oil] [filter] cover']);
});

it('sends squeezeOut true on login and Bearer auth on search', function (): void {
    fakePl24();
    $brand = new PartsLink24Brand('mini', 'mini_parts', 'p5bmw');

    resolve(PartsLink24Client::class)->searchByVin($brand, 'WMWSU91010T717700', 'oil filter');

    Http::assertSent(fn ($req) => str_contains($req->url(), '/pl24-appgtw/ext/api/1.0/login')
        && $req->data()['squeezeOut'] === true);
    Http::assertSent(fn ($req) => str_contains($req->url(), '/p5bmw/extern/search/vin')
        && str_starts_with((string) $req->header('Authorization')[0], 'Bearer '));
});

it('caches the authorize token across calls', function (): void {
    fakePl24();
    $brand = new PartsLink24Brand('mini', 'mini_parts', 'p5bmw');
    $client = resolve(PartsLink24Client::class);

    $client->searchByVin($brand, 'WMWSU91010T717700', 'oil filter');
    $client->searchByVin($brand, 'WMWSU91010T717700', 'brake disc');

    Http::assertSentCount(4); // login+authorize once, then 2 searches (token reused on 2nd)
});
```

- [ ] **Step 3: Run it to verify it fails**

Run: `php artisan test --compact tests/Feature/Services/PartsLink24/PartsLink24ClientTest.php`
Expected: FAIL (class not found).

- [ ] **Step 4: Write `PartsLink24Client`**

```php
<?php

declare(strict_types=1);

namespace App\Services\PartsLink24;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class PartsLink24Client
{
    /** @var list<string> */
    private const array BASE_SERVICES = ['cart', 'pl24-full-vin-data', 'pl24-orderbridge', 'pl24-qparts'];

    public function token(PartsLink24Brand $brand): PartsLink24Token
    {
        $cacheKey = 'partslink24.token.'.$brand->service;

        $cached = Cache::get($cacheKey);

        if ($cached instanceof PartsLink24Token && $cached->isValid()) {
            return $cached;
        }

        $token = $this->authorize($brand);

        Cache::put($cacheKey, $token, $token->expiresAt);

        return $token;
    }

    /**
     * @return list<array{oe: string, name: string}>
     */
    public function searchByVin(PartsLink24Brand $brand, string $vin, string $query): array
    {
        $token = $this->token($brand);
        $base = config()->string('suppliers.partslink24.base_url');

        $response = Http::asJson()
            ->timeout(config()->integer('suppliers.partslink24.timeout'))
            ->withToken($token->accessToken)
            ->get("{$base}/{$brand->group}/extern/search/vin", [
                'lang' => config()->string('suppliers.partslink24.lang'),
                'serviceName' => $brand->service,
                'vin' => $vin,
                'q' => $query,
            ])
            ->throw();

        /** @var list<array<string, mixed>> $records */
        $records = data_get($response->json(), 'data.records', []);

        $rows = [];

        foreach ($records as $record) {
            $oe = data_get($record, 'recordContext.bidata_part_no');
            $name = data_get($record, 'values.name');

            if (is_string($oe) && $oe !== '' && is_string($name)) {
                $rows[] = ['oe' => $oe, 'name' => $name];
            }
        }

        return $rows;
    }

    private function authorize(PartsLink24Brand $brand): PartsLink24Token
    {
        $base = config()->string('suppliers.partslink24.base_url');
        $jar = new CookieJar();

        $this->login($base, $jar);

        $response = Http::asJson()
            ->timeout(config()->integer('suppliers.partslink24.timeout'))
            ->withOptions(['cookies' => $jar])
            ->post("{$base}/auth/ext/api/1.1/authorize", [
                'serviceNames' => $this->serviceNames($brand),
                'serviceCategoryNames' => ['pl24-shop-universal', 'pl24-shop-tools'],
                'withLogin' => true,
            ])
            ->throw();

        $accessToken = $response->json('access_token');
        $expiresIn = $response->json('expires_in');

        throw_unless(
            is_string($accessToken) && $accessToken !== '' && is_int($expiresIn),
            RuntimeException::class,
            'Incomplete PartsLink24 authorize response (access_token/expires_in missing).',
        );

        $buffer = config()->integer('suppliers.partslink24.token_ttl_buffer');

        return new PartsLink24Token(
            accessToken: $accessToken,
            expiresAt: Date::now()->addSeconds(max(1, $expiresIn - $buffer)),
        );
    }

    private function login(string $base, CookieJar $jar): void
    {
        Http::asJson()
            ->timeout(config()->integer('suppliers.partslink24.timeout'))
            ->withOptions(['cookies' => $jar])
            ->post("{$base}/pl24-appgtw/ext/api/1.0/login", [
                'authentication' => [
                    'account' => config()->string('suppliers.partslink24.account'),
                    'user' => config()->string('suppliers.partslink24.username'),
                    'pwd' => config()->string('suppliers.partslink24.password'),
                ],
                'device' => ['id' => '0', 'os' => 'server', 'offset' => '0', 'lang' => 'en-US', 'os-version' => '0'],
                'app-version' => '',
                'squeezeOut' => true,
            ])
            ->throw();
    }

    /**
     * @return list<string>
     */
    private function serviceNames(PartsLink24Brand $brand): array
    {
        $short = Str::replaceLast('_parts', '', $brand->service);

        return [
            ...self::BASE_SERVICES,
            $brand->service,
            "dealer-listing-pl24-{$short}",
            "pl24-parts-list-scan-{$short}",
        ];
    }
}
```

Note (`squeezeOut:true`): this is the single-session takeover the product requires. The password reaches `login()` only through `config()`; never log the request body.

- [ ] **Step 5: Run the test** — Expected: PASS. If the cache-reuse test miscounts, confirm the array-cache store is active in tests (it is by default) and that `expiresAt` is future.

- [ ] **Step 6: Commit**

```bash
git add app/Services/PartsLink24/PartsLink24Client.php tests/Feature/Services/PartsLink24/PartsLink24ClientTest.php tests/Fixtures/PartsLink24/
git commit -m "feat: PartsLink24Client (appgtw login + authorize + VIN search)"
```

---

### Task 6: `PartsLink24HttpClient` (the real `PartsLink24Catalog`)

**Files:**
- Create: `app/Services/PartsLink24/PartsLink24HttpClient.php`
- Test: `tests/Feature/Services/PartsLink24/PartsLink24HttpClientTest.php`

**Interfaces:**
- Consumes: `VinBrandResolver` (Task 3), `PartsLink24Client` (Task 5), config (Task 2).
- Produces: `final readonly class PartsLink24HttpClient implements PartsLink24Catalog` — `resolveOeParts(string $vin, string $category, array $keywords): array` maps the client's raw rows into deduped, capped `list<OePart>` (brand `'OE'`, cleaned description). Empty VIN, unknown brand, or no matches → `[]`.
- The `$category` argument carries Grok's English `searchTerm` (wired in Task 7); if empty, fall back to the first keyword.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Services\PartsLink24\Contracts\PartsLink24Catalog;
use App\Services\PartsLink24\PartsLink24HttpClient;
use Illuminate\Support\Facades\Http;

function fakePl24Full(): void
{
    config()->set([
        'suppliers.partslink24.account' => 'pt-test',
        'suppliers.partslink24.username' => 'tester',
        'suppliers.partslink24.password' => 'secret',
    ]);

    Http::fake([
        '*/pl24-appgtw/ext/api/1.0/login' => Http::response(['token' => 'sess', 'status' => 'OK']),
        '*/auth/ext/api/1.1/authorize' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/authorize.json')), true)),
        '*/p5bmw/extern/search/vin*' => Http::response(json_decode((string) file_get_contents(base_path('tests/Fixtures/PartsLink24/search-oil-filter.json')), true)),
    ]);
}

it('resolves a VIN + term to deduped, cleaned OE parts', function (): void {
    fakePl24Full();

    $parts = resolve(PartsLink24HttpClient::class)->resolveOeParts('WMWSU91010T717700', 'oil filter', ['oil']);

    // 4 raw rows, one duplicate collapsed => 3 distinct OE numbers.
    expect($parts)->toHaveCount(3)
        ->and($parts[0]->oeNumber)->toBe('11427557011')
        ->and($parts[0]->description)->toBe('Oil filter cover')
        ->and($parts[1]->oeNumber)->toBe('11427622446')
        ->and($parts[1]->description)->toBe('Set oil-filter element')
        ->and($parts[1]->brand)->toBe('OE');
});

it('returns no parts for an empty VIN without any HTTP', function (): void {
    Http::fake();
    expect(resolve(PartsLink24HttpClient::class)->resolveOeParts('', 'oil filter', []))->toBe([]);
    Http::assertNothingSent();
});

it('returns no parts for an unknown brand VIN without any HTTP', function (): void {
    Http::fake();
    expect(resolve(PartsLink24HttpClient::class)->resolveOeParts('ZZZ99999999999999', 'oil filter', []))->toBe([]);
    Http::assertNothingSent();
});

it('is the bound implementation after Task 7', function (): void {
    // Sanity: the contract resolves to the real client (kept green once binding is swapped).
    expect(resolve(PartsLink24Catalog::class))->toBeInstanceOf(PartsLink24HttpClient::class);
})->skip('enable after Task 7 swaps the binding');

it('caps candidates at the configured maximum', function (): void {
    config()->set('suppliers.partslink24.max_candidates', 1);
    fakePl24Full();

    expect(resolve(PartsLink24HttpClient::class)->resolveOeParts('WMWSU91010T717700', 'oil filter', []))->toHaveCount(1);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact tests/Feature/Services/PartsLink24/PartsLink24HttpClientTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Write `PartsLink24HttpClient`**

```php
<?php

declare(strict_types=1);

namespace App\Services\PartsLink24;

use App\Data\OePart;
use App\Services\PartsLink24\Contracts\PartsLink24Catalog;
use Illuminate\Support\Str;

final readonly class PartsLink24HttpClient implements PartsLink24Catalog
{
    public function __construct(
        private VinBrandResolver $resolver,
        private PartsLink24Client $client,
    ) {}

    public function resolveOeParts(string $vin, string $category, array $keywords): array
    {
        if ($vin === '') {
            return [];
        }

        $brand = $this->resolver->resolve($vin);

        if (! $brand instanceof PartsLink24Brand) {
            return [];
        }

        $query = $category !== '' ? $category : ($keywords[0] ?? '');

        if ($query === '') {
            return [];
        }

        $rows = $this->client->searchByVin($brand, $vin, $query);

        $parts = [];

        foreach ($rows as $row) {
            $oe = $row['oe'];

            if (isset($parts[$oe])) {
                continue;
            }

            $parts[$oe] = new OePart($oe, $this->cleanName($row['name']), 'OE');
        }

        $max = config()->integer('suppliers.partslink24.max_candidates');

        return array_slice(array_values($parts), 0, $max);
    }

    /**
     * Catalog names carry match markup: square brackets around matched terms
     * and `\-` escapes (e.g. "Set [oil]\-[filter] element" => "Set oil-filter element").
     */
    private function cleanName(string $name): string
    {
        $name = str_replace(['[', ']'], '', $name);
        $name = str_replace('\\-', '-', $name);

        return Str::squish($name);
    }
}
```

- [ ] **Step 4: Run the test** — Expected: PASS (the binding-sanity test stays skipped until Task 7).

- [ ] **Step 5: Commit**

```bash
git add app/Services/PartsLink24/PartsLink24HttpClient.php tests/Feature/Services/PartsLink24/PartsLink24HttpClientTest.php
git commit -m "feat: PartsLink24HttpClient real catalog (VIN + term -> OE parts)"
```

---

### Task 7: Swap the binding + wire the English `searchTerm`

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `app/Actions/IdentifyAndSourceParts.php`
- Modify: `app/Services/PartsLink24/CLAUDE.md`, `app/Services/PartsLink24/Contracts/CLAUDE.md` (update "fake bound today" wording)
- Test: `tests/Feature/Services/PartsLink24/PartsLink24HttpClientTest.php` (un-skip the binding test)
- Test: existing identify tests — `tests/Feature/Actions/IdentifyAndSourcePartsTest.php`, `tests/Feature/Http/IdentifyControllerTest.php` (or equivalent) and the browser test — update to fake PartsLink24 HTTP.

**Interfaces:**
- Consumes: `PartsLink24HttpClient` (Task 6), `PartRequestUnderstanding::$searchTerm` (Task 1).

- [ ] **Step 1: Read** `app/Actions/IdentifyAndSourceParts.php` to see how it currently calls `IdentifyOeParts` (it passes `$understanding->category`).

- [ ] **Step 2: Swap the binding** in `AppServiceProvider::register()`:

```php
$this->app->bind(
    PartsLink24Catalog::class,
    PartsLink24HttpClient::class,
);
```

Update the import from `FakePartsLink24Catalog` to `PartsLink24HttpClient`. Keep `FakePartsLink24Catalog` in the codebase (used by tests that bind it explicitly).

- [ ] **Step 3: Feed the English term** — in `IdentifyAndSourceParts`, change the OE lookup to pass `$understanding->searchTerm` instead of `$understanding->category`:

```php
$oeParts = $this->identifyOeParts->execute($vin, $understanding->searchTerm, $understanding->keywords);
```

- [ ] **Step 4: Un-skip the binding test** in `PartsLink24HttpClientTest.php` (remove the `->skip(...)`).

- [ ] **Step 5: Fix the existing identify tests** — they currently rely on the fake returning `OC 90`. For each identify feature/browser test:
  - Either bind the fake explicitly at the top: `$this->app->bind(PartsLink24Catalog::class, FakePartsLink24Catalog::class);` (fastest, keeps their assertions), or
  - `Http::fake([...])` the PartsLink24 endpoints with the Task 5 fixtures and assert on real OE numbers.
  Prefer binding the fake where the test's intent is "the identify flow wires together" (not "PartsLink24 parsing"), so those tests stay decoupled from HTTP. Update any assertion that hardcodes `OC 90` accordingly. Ensure the `PartRequestUnderstander::fake([...])` payloads include `searchTerm` (Task 1).

- [ ] **Step 6: Run the full identify + PartsLink24 suites**

Run: `php artisan test --compact --filter="Identify|PartsLink24"`
Expected: PASS. Fix any test still asserting `OC 90` or missing `searchTerm`.

- [ ] **Step 7: Full quality gate**

Run: `PAO_DISABLE=1 bin/quality-gate.sh`
Expected: exit 0 (rector, pint, phpstan max, wayfinder, ts types, 100% coverage, Pest browser).

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat: bind real PartsLink24 client + wire English searchTerm"
```

---

## Post-Plan Verification (controller-run, not a task)

After Task 7 is green, do a single live smoke test (needs the PartsLink24 session free; the client will `squeezeOut` a stale one): drive `/identify` with request `"preciso de um filtro de óleo"` + VIN `WMWSU91010T717700` and confirm the results table prices a real Mini OE number (`11427622446`, oil-filter element) rather than `OC 90`. This is manual verification, not an automated test (the suite fakes HTTP).

## Notes / Deferred (not in this plan)

- Per-supplier failure isolation in `IdentifyAndSourceParts` (final-review I2) and the `/identify` UI-parity items (422 field errors, clarify re-run with carried context, available/unavailable split + "Abrir em" links, `SUPPLIER_LABELS` dedupe) remain deferred; they are independent of this client swap.
- Only Mini is live-verified. As real VINs for other makes appear, verify each brand's `service`/`group` row and extend the WMI map. Unknown WMIs degrade to an empty result, never a crash.
- Operational: rotate the PartsLink24 password (shared in chat, appeared in captured traffic during the spike) and provision a dedicated app account so app + operator stop evicting each other via `squeezeOut`.
