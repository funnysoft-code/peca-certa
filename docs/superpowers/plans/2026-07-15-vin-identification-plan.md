# VIN Part Identification — Implementation Plan (Plan 1 of 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the `/identify` flow end-to-end — operator enters a free-text request + VIN, Grok 4.3 structures the request, and (via a PartsLink24 catalog contract, faked in this plan) the identified OE reference is priced through the existing Phase 1 sourcing.

**Architecture:** Grok 4.3 (via `laravel/ai`, structured output) turns the request into a category + keywords. A `PartsLink24Catalog` contract resolves VIN + category → OE parts; this plan ships the contract plus a fake, so the whole flow is testable now. **Plan 2** (spike-gated) swaps in the real PartsLink24 HTTP client. Identified references reuse `SearchAutoDeltaParts` / `SearchAutoZitaniaParts` for pricing.

**Tech Stack:** PHP 8.5, Laravel 13, `laravel/ai` (v0) with xAI/Grok, Inertia 3 + React 19, Pest 5, PHPStan max.

## Global Constraints

- `declare(strict_types=1)` at the top of every PHP file.
- Actions: `final readonly class`, single `execute()` method, constructor property promotion.
- DTOs: `final readonly class implements JsonSerializable`, `#[TypeScript]`.
- `env()` only in `config/`; everywhere else `config()`.
- PHPStan level max, zero errors; 100% code + type coverage; full quality gate (`bin/quality-gate.sh`) green before any completion claim.
- AI provider: xAI, model `grok-4.3` (image `grok-imagine-image`); `Lab::xAI`.
- Routes English (`/identify`), UI copy European Portuguese. No em dashes anywhere.
- VIN is required to run identification; without it the operator uses `/parts`.
- `laravel/ai` is new — **search Boost docs (`search-docs`) for exact structured-output / JsonSchema syntax before writing the agent**; do not guess the JsonSchema builder methods.
- Run affected tests with `PAO_DISABLE=1 php artisan test --compact <path>` (pao flips the exit code otherwise).

---

## Task 1: xAI provider config + global default

**Files:**
- Modify: `config/ai.php` (add `xai` provider; switch defaults)
- Modify: `.env.example`
- Test: `tests/Feature/Ai/XaiProviderConfigTest.php`

**Interfaces:**
- Produces: `config('ai.providers.xai.models.text.default')` === `'grok-4.3'`; `config('ai.default')` === `'xai'`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

it('defaults to the xai provider with grok-4.3', function (): void {
    expect(config('ai.default'))->toBe('xai')
        ->and(config('ai.default_for_images'))->toBe('xai')
        ->and(config('ai.providers.xai.driver'))->toBe('xai')
        ->and(config('ai.providers.xai.models.text.default'))->toBe('grok-4.3')
        ->and(config('ai.providers.xai.models.image.default'))->toBe('grok-imagine-image');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `PAO_DISABLE=1 php artisan test --compact tests/Feature/Ai/XaiProviderConfigTest.php`
Expected: FAIL (default is `anthropic`, no `xai` block).

- [ ] **Step 3: Edit `config/ai.php`**

Change the two default lines:

```php
'default' => env('AI_DEFAULT_PROVIDER', 'xai'),

'default_for_images' => env('AI_DEFAULT_IMAGE_PROVIDER', 'xai'),
```

Add inside the `'providers' => [` array (after the `openai` block):

```php
'xai' => [
    'driver' => 'xai',
    'name' => 'xai',
    'key' => env('XAI_API_KEY'),
    'models' => [
        'text' => [
            'default' => env('XAI_DEFAULT_MODEL', 'grok-4.3'),
            'cheapest' => env('XAI_CHEAPEST_MODEL', 'grok-4.3-fast'),
        ],
        'image' => [
            'default' => env('XAI_DEFAULT_IMAGE_MODEL', 'grok-imagine-image'),
        ],
    ],
],
```

- [ ] **Step 4: Append to `.env.example`** (under the `# --- Laravel AI ---` block)

```
XAI_API_KEY=
AI_DEFAULT_PROVIDER=xai
```

- [ ] **Step 5: Run test to verify it passes**

Run: `PAO_DISABLE=1 php artisan test --compact tests/Feature/Ai/XaiProviderConfigTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add config/ai.php .env.example tests/Feature/Ai/XaiProviderConfigTest.php
git commit -m "feat: xai as default AI provider (grok-4.3)"
```

> **Manual (user):** add the real key to `.env`: `! echo 'XAI_API_KEY=<key>' >> .env`

---

## Task 2: DTOs — PartRequestUnderstanding + OePart

**Files:**
- Create: `app/Data/PartRequestUnderstanding.php`
- Create: `app/Data/OePart.php`
- Test: `tests/Unit/Data/PartRequestUnderstandingTest.php`, `tests/Unit/Data/OePartTest.php`

**Interfaces:**
- Produces:
  - `new PartRequestUnderstanding(string $category, list<string> $keywords, ?string $clarifyingQuestion, float $confidence)`
  - `new OePart(string $oeNumber, string $description, string $brand)`
  - both `jsonSerialize(): array`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Data/PartRequestUnderstandingTest.php`:

```php
<?php

declare(strict_types=1);

use App\Data\PartRequestUnderstanding;

it('serialises an understanding', function (): void {
    $u = new PartRequestUnderstanding('filtro de óleo', ['OC 90', 'óleo'], null, 0.9);

    expect($u->jsonSerialize())->toBe([
        'category' => 'filtro de óleo',
        'keywords' => ['OC 90', 'óleo'],
        'clarifyingQuestion' => null,
        'confidence' => 0.9,
    ]);
});

it('flags when a clarifying question is needed', function (): void {
    $u = new PartRequestUnderstanding('', [], 'Qual é o motor?', 0.2);

    expect($u->needsClarification())->toBeTrue();
});
```

`tests/Unit/Data/OePartTest.php`:

```php
<?php

declare(strict_types=1);

use App\Data\OePart;

it('serialises an OE part', function (): void {
    expect((new OePart('06A115561B', 'Filtro de óleo', 'VAG'))->jsonSerialize())->toBe([
        'oeNumber' => '06A115561B',
        'description' => 'Filtro de óleo',
        'brand' => 'VAG',
    ]);
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `PAO_DISABLE=1 php artisan test --compact tests/Unit/Data/PartRequestUnderstandingTest.php tests/Unit/Data/OePartTest.php`
Expected: FAIL (classes missing).

- [ ] **Step 3: Create `app/Data/PartRequestUnderstanding.php`**

```php
<?php

declare(strict_types=1);

namespace App\Data;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class PartRequestUnderstanding implements JsonSerializable
{
    /**
     * @param  list<string>  $keywords
     */
    public function __construct(
        public string $category,
        public array $keywords,
        public ?string $clarifyingQuestion,
        public float $confidence,
    ) {}

    public function needsClarification(): bool
    {
        return $this->clarifyingQuestion !== null && $this->clarifyingQuestion !== '';
    }

    /**
     * @return array{category: string, keywords: list<string>, clarifyingQuestion: string|null, confidence: float}
     */
    public function jsonSerialize(): array
    {
        return [
            'category' => $this->category,
            'keywords' => $this->keywords,
            'clarifyingQuestion' => $this->clarifyingQuestion,
            'confidence' => $this->confidence,
        ];
    }
}
```

- [ ] **Step 4: Create `app/Data/OePart.php`**

```php
<?php

declare(strict_types=1);

namespace App\Data;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class OePart implements JsonSerializable
{
    public function __construct(
        public string $oeNumber,
        public string $description,
        public string $brand,
    ) {}

    /**
     * @return array{oeNumber: string, description: string, brand: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'oeNumber' => $this->oeNumber,
            'description' => $this->description,
            'brand' => $this->brand,
        ];
    }
}
```

- [ ] **Step 5: Run to verify pass, then regenerate TS types**

Run: `PAO_DISABLE=1 php artisan test --compact tests/Unit/Data/PartRequestUnderstandingTest.php tests/Unit/Data/OePartTest.php`
Expected: PASS.
Run: `php artisan typescript:transform`

- [ ] **Step 6: Commit**

```bash
git add app/Data/PartRequestUnderstanding.php app/Data/OePart.php tests/Unit/Data resources/js/types/generated.d.ts
git commit -m "feat: PartRequestUnderstanding + OePart DTOs"
```

---

## Task 3: Grok request-understanding agent + action

**Files:**
- Create: `app/Ai/Agents/PartRequestUnderstander.php` (scaffold with `php artisan make:agent PartRequestUnderstander`)
- Create: `app/Actions/UnderstandPartRequest.php`
- Test: `tests/Feature/Identify/UnderstandPartRequestTest.php`

**Interfaces:**
- Consumes: `PartRequestUnderstanding` (Task 2).
- Produces: `UnderstandPartRequest::execute(string $request): PartRequestUnderstanding`.

> **Before coding:** run `search-docs` with queries `["structured output", "agent configuration", "testing agents fake"]` (packages `laravel/ai`) to confirm the `HasStructuredOutput::schema()` JsonSchema builder methods (array items, nullable) and the `Agent::fake()` assertion API. Adjust the schema/fake calls below to match the docs exactly.

- [ ] **Step 1: Write the failing test** (`tests/Feature/Identify/UnderstandPartRequestTest.php`)

```php
<?php

declare(strict_types=1);

use App\Actions\UnderstandPartRequest;
use App\Ai\Agents\PartRequestUnderstander;

it('structures a clear request into category and keywords', function (): void {
    PartRequestUnderstander::fake([
        ['category' => 'filtro de óleo', 'keywords' => ['filtro', 'óleo'], 'clarifyingQuestion' => null, 'confidence' => 0.92],
    ]);

    $result = resolve(UnderstandPartRequest::class)->execute('filtro de óleo para Golf 1.9 TDI');

    expect($result->category)->toBe('filtro de óleo')
        ->and($result->keywords)->toBe(['filtro', 'óleo'])
        ->and($result->needsClarification())->toBeFalse();
});

it('returns a clarifying question when the request is ambiguous', function (): void {
    PartRequestUnderstander::fake([
        ['category' => '', 'keywords' => [], 'clarifyingQuestion' => 'Qual é o motor da viatura?', 'confidence' => 0.2],
    ]);

    $result = resolve(UnderstandPartRequest::class)->execute('preciso de uma peça');

    expect($result->needsClarification())->toBeTrue()
        ->and($result->clarifyingQuestion)->toBe('Qual é o motor da viatura?');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `PAO_DISABLE=1 php artisan test --compact tests/Feature/Identify/UnderstandPartRequestTest.php`
Expected: FAIL (agent + action missing).

- [ ] **Step 3: Scaffold + write the agent** (`app/Ai/Agents/PartRequestUnderstander.php`)

```php
<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

final class PartRequestUnderstander implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'PROMPT'
            És um assistente de identificação de peças auto para uma oficina em Portugal.
            Recebes o pedido do cliente em português. Devolve:
            - category: a categoria da peça em português (ex.: "filtro de óleo"). Vazio se não der para determinar.
            - keywords: palavras-chave para pesquisa no catálogo.
            - clarifyingQuestion: UMA pergunta em português quando o pedido é demasiado ambíguo para escolher a categoria; caso contrário null.
            - confidence: 0 a 1.
            Nunca inventes uma categoria com baixa confiança: faz antes uma pergunta de clarificação.
            PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'category' => $schema->string()->required(),
            'keywords' => $schema->array()->items($schema->string())->required(),
            'clarifyingQuestion' => $schema->string()->nullable(),
            'confidence' => $schema->number()->min(0)->max(1)->required(),
        ];
    }
}
```

- [ ] **Step 4: Write the action** (`app/Actions/UnderstandPartRequest.php`)

```php
<?php

declare(strict_types=1);

namespace App\Actions;

use App\Ai\Agents\PartRequestUnderstander;
use App\Data\PartRequestUnderstanding;

final readonly class UnderstandPartRequest
{
    public function execute(string $request): PartRequestUnderstanding
    {
        $response = (new PartRequestUnderstander)->prompt($request);

        $clarifying = is_string($response['clarifyingQuestion'] ?? null) ? $response['clarifyingQuestion'] : null;

        return new PartRequestUnderstanding(
            category: is_string($response['category'] ?? null) ? $response['category'] : '',
            keywords: $this->toStringList($response['keywords'] ?? []),
            clarifyingQuestion: $clarifying === '' ? null : $clarifying,
            confidence: is_numeric($response['confidence'] ?? null) ? (float) $response['confidence'] : 0.0,
        );
    }

    /**
     * @return list<string>
     */
    private function toStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $v): string => is_scalar($v) ? (string) $v : '',
            $value,
        ), fn (string $v): bool => $v !== ''));
    }
}
```

- [ ] **Step 5: Run to verify pass**

Run: `PAO_DISABLE=1 php artisan test --compact tests/Feature/Identify/UnderstandPartRequestTest.php`
Expected: PASS. (If the `fake()` payload shape differs from docs, adjust per the search-docs findings.)

- [ ] **Step 6: Commit**

```bash
git add app/Ai/Agents/PartRequestUnderstander.php app/Actions/UnderstandPartRequest.php tests/Feature/Identify/UnderstandPartRequestTest.php
git commit -m "feat: Grok request-understanding agent + action"
```

---

## Task 4: PartsLink24 catalog contract + fake + config

**Files:**
- Create: `app/Services/PartsLink24/Contracts/PartsLink24Catalog.php`
- Create: `app/Services/PartsLink24/FakePartsLink24Catalog.php`
- Create: `app/Services/PartsLink24/CLAUDE.md`
- Modify: `config/suppliers.php` (add `partslink24` block)
- Modify: `app/Providers/AppServiceProvider.php` (bind the contract to the fake for now)
- Modify: `.env.example`
- Test: `tests/Feature/PartsLink24/FakePartsLink24CatalogTest.php`

**Interfaces:**
- Consumes: `OePart` (Task 2).
- Produces: `PartsLink24Catalog::resolveOeParts(string $vin, string $category, list<string> $keywords): list<OePart>`.

- [ ] **Step 1: Write the failing test** (`tests/Feature/PartsLink24/FakePartsLink24CatalogTest.php`)

```php
<?php

declare(strict_types=1);

use App\Services\PartsLink24\Contracts\PartsLink24Catalog;

it('resolves fake OE parts for a vin and category', function (): void {
    $parts = resolve(PartsLink24Catalog::class)->resolveOeParts('WVWZZZ1JZXW000001', 'filtro de óleo', ['óleo']);

    expect($parts)->not->toBeEmpty()
        ->and($parts[0]->oeNumber)->not->toBe('');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `PAO_DISABLE=1 php artisan test --compact tests/Feature/PartsLink24/FakePartsLink24CatalogTest.php`
Expected: FAIL (contract unbound).

- [ ] **Step 3: Create the contract** (`app/Services/PartsLink24/Contracts/PartsLink24Catalog.php`)

```php
<?php

declare(strict_types=1);

namespace App\Services\PartsLink24\Contracts;

use App\Data\OePart;

interface PartsLink24Catalog
{
    /**
     * Resolve a VIN + part category to genuine (OE) part references.
     *
     * @param  list<string>  $keywords
     * @return list<OePart>
     */
    public function resolveOeParts(string $vin, string $category, array $keywords): array;
}
```

- [ ] **Step 4: Create the fake** (`app/Services/PartsLink24/FakePartsLink24Catalog.php`)

```php
<?php

declare(strict_types=1);

namespace App\Services\PartsLink24;

use App\Data\OePart;
use App\Services\PartsLink24\Contracts\PartsLink24Catalog;

/**
 * Placeholder until the real HTTP client lands in Plan 2. Returns a single
 * deterministic OE part so the identify flow is exercisable end-to-end.
 */
final readonly class FakePartsLink24Catalog implements PartsLink24Catalog
{
    public function resolveOeParts(string $vin, string $category, array $keywords): array
    {
        if ($vin === '') {
            return [];
        }

        return [new OePart('OC 90', $category !== '' ? $category : 'peça', 'OE')];
    }
}
```

- [ ] **Step 5: Bind in `AppServiceProvider::register()`**

```php
$this->app->bind(
    \App\Services\PartsLink24\Contracts\PartsLink24Catalog::class,
    \App\Services\PartsLink24\FakePartsLink24Catalog::class,
);
```

- [ ] **Step 6: Add config + env**

`config/suppliers.php` (new block inside the returned array):

```php
'partslink24' => [
    'base_url' => env('PARTSLINK24_BASE_URL', 'https://www.partslink24.com'),
    'account' => env('PARTSLINK24_ACCOUNT'),
    'username' => env('PARTSLINK24_USERNAME'),
    'password' => env('PARTSLINK24_PASSWORD'),
    'timeout' => (int) env('PARTSLINK24_TIMEOUT', 30),
],
```

`.env.example` (under `# --- Suppliers ---`):

```
PARTSLINK24_ACCOUNT=
PARTSLINK24_USERNAME=
PARTSLINK24_PASSWORD=
```

Create `app/Services/PartsLink24/CLAUDE.md`:

```markdown
# PartsLink24 Service

- `PartsLink24Catalog` (contract) resolves VIN + category -> OE parts.
- `FakePartsLink24Catalog` is a placeholder until the real HTTP client (Plan 2).
- Real API: JSON REST, `POST /auth/ext/api/1.1/login` with `{account, user, password}` -> token; catalogs under `/pl24-*/ext/api/1.0/`. Single concurrent session limit (like Auto Zitania).
- Credentials via `config('suppliers.partslink24.*')`, never `env()` directly.
```

- [ ] **Step 7: Run to verify pass**

Run: `PAO_DISABLE=1 php artisan test --compact tests/Feature/PartsLink24/FakePartsLink24CatalogTest.php`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Services/PartsLink24 config/suppliers.php app/Providers/AppServiceProvider.php .env.example tests/Feature/PartsLink24
git commit -m "feat: PartsLink24 catalog contract + fake"
```

---

## Task 5: IdentifyOeParts action

**Files:**
- Create: `app/Actions/IdentifyOeParts.php`
- Test: `tests/Feature/Identify/IdentifyOePartsTest.php`

**Interfaces:**
- Consumes: `PartsLink24Catalog` (Task 4), `OePart` (Task 2).
- Produces: `IdentifyOeParts::execute(string $vin, string $category, list<string> $keywords): list<OePart>`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Actions\IdentifyOeParts;
use App\Data\OePart;
use App\Services\PartsLink24\Contracts\PartsLink24Catalog;

it('delegates identification to the catalog', function (): void {
    $this->mock(PartsLink24Catalog::class)
        ->shouldReceive('resolveOeParts')->once()
        ->with('VIN1', 'filtro de óleo', ['óleo'])
        ->andReturn([new OePart('06A115561B', 'Filtro', 'VAG')]);

    $parts = resolve(IdentifyOeParts::class)->execute('VIN1', 'filtro de óleo', ['óleo']);

    expect($parts)->toHaveCount(1)->and($parts[0]->oeNumber)->toBe('06A115561B');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `PAO_DISABLE=1 php artisan test --compact tests/Feature/Identify/IdentifyOePartsTest.php`
Expected: FAIL.

- [ ] **Step 3: Write the action** (`app/Actions/IdentifyOeParts.php`)

```php
<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\OePart;
use App\Services\PartsLink24\Contracts\PartsLink24Catalog;

final readonly class IdentifyOeParts
{
    public function __construct(
        private PartsLink24Catalog $catalog,
    ) {}

    /**
     * @param  list<string>  $keywords
     * @return list<OePart>
     */
    public function execute(string $vin, string $category, array $keywords): array
    {
        return $this->catalog->resolveOeParts($vin, $category, $keywords);
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `PAO_DISABLE=1 php artisan test --compact tests/Feature/Identify/IdentifyOePartsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Actions/IdentifyOeParts.php tests/Feature/Identify/IdentifyOePartsTest.php
git commit -m "feat: IdentifyOeParts action"
```

---

## Task 6: IdentifyAndSourceParts glue action

**Files:**
- Create: `app/Data/IdentifyResult.php`
- Create: `app/Actions/IdentifyAndSourceParts.php`
- Test: `tests/Feature/Identify/IdentifyAndSourcePartsTest.php`

**Interfaces:**
- Consumes: `UnderstandPartRequest`, `IdentifyOeParts`, `SearchAutoDeltaParts`, `SearchAutoZitaniaParts`, `PartRequestUnderstanding`, `PartSearchResult`.
- Produces: `IdentifyAndSourceParts::execute(string $request, string $vin): IdentifyResult` where `IdentifyResult` carries `understanding: PartRequestUnderstanding`, `oeParts: list<OePart>`, `results: list<PartSearchResult>`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Actions\IdentifyAndSourceParts;
use App\Ai\Agents\PartRequestUnderstander;
use App\Data\OePart;
use App\Services\AutoDelta\AutoDeltaToken;
use App\Services\PartsLink24\Contracts\PartsLink24Catalog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

it('understands, identifies, and prices when a vin is given', function (): void {
    PartRequestUnderstander::fake([
        ['category' => 'filtro de óleo', 'keywords' => ['óleo'], 'clarifyingQuestion' => null, 'confidence' => 0.9],
    ]);
    $this->mock(PartsLink24Catalog::class)
        ->shouldReceive('resolveOeParts')->once()
        ->andReturn([new OePart('OC 90', 'Filtro de óleo', 'OE')]);

    config()->set('suppliers.autodelta.catalog_url', 'https://cat.test/WebCat30WS');
    config()->set('suppliers.autodelta.search_url', 'https://cat.test/Tecdoc');
    config()->set('suppliers.autodelta.catalog_id', 'CAT');
    config()->set('suppliers.autozitania.username', 'user');
    config()->set('suppliers.autozitania.password', 'secret');
    Cache::put('autodelta.token', new AutoDeltaToken('KEY', 'USER', now()->addDay()), now()->addDay());
    $search = json_decode((string) file_get_contents(base_path('tests/Fixtures/AutoDelta/search-by-number.json')), true);
    $prices = json_decode((string) file_get_contents(base_path('tests/Fixtures/AutoDelta/trade-prices.json')), true);
    Http::fakeSequence('cat.test/*')->push($search['response'])->push($prices['response']);
    Process::fake(['*' => Process::result(output: (string) file_get_contents(base_path('tests/Fixtures/AutoZitania/search-output.json')))]);

    $result = resolve(IdentifyAndSourceParts::class)->execute('filtro de óleo para Golf', 'WVWZZZ1JZXW000001');

    expect($result->understanding->category)->toBe('filtro de óleo')
        ->and($result->oeParts)->toHaveCount(1)
        ->and($result->results)->not->toBeEmpty();
});

it('stops at the clarifying question and does not identify', function (): void {
    PartRequestUnderstander::fake([
        ['category' => '', 'keywords' => [], 'clarifyingQuestion' => 'Qual é o motor?', 'confidence' => 0.2],
    ]);
    $this->mock(PartsLink24Catalog::class)->shouldNotReceive('resolveOeParts');

    $result = resolve(IdentifyAndSourceParts::class)->execute('preciso de uma peça', 'WVWZZZ1JZXW000001');

    expect($result->understanding->needsClarification())->toBeTrue()
        ->and($result->oeParts)->toBe([])
        ->and($result->results)->toBe([]);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `PAO_DISABLE=1 php artisan test --compact tests/Feature/Identify/IdentifyAndSourcePartsTest.php`
Expected: FAIL.

- [ ] **Step 3: Create `app/Data/IdentifyResult.php`**

```php
<?php

declare(strict_types=1);

namespace App\Data;

use JsonSerializable;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final readonly class IdentifyResult implements JsonSerializable
{
    /**
     * @param  list<OePart>  $oeParts
     * @param  list<PartSearchResult>  $results
     */
    public function __construct(
        public PartRequestUnderstanding $understanding,
        public array $oeParts,
        public array $results,
    ) {}

    /**
     * @return array{understanding: PartRequestUnderstanding, oeParts: list<OePart>, results: list<PartSearchResult>}
     */
    public function jsonSerialize(): array
    {
        return [
            'understanding' => $this->understanding,
            'oeParts' => $this->oeParts,
            'results' => $this->results,
        ];
    }
}
```

- [ ] **Step 4: Write the glue action** (`app/Actions/IdentifyAndSourceParts.php`)

```php
<?php

declare(strict_types=1);

namespace App\Actions;

use App\Data\IdentifyResult;
use App\Data\PartSearchResult;

final readonly class IdentifyAndSourceParts
{
    public function __construct(
        private UnderstandPartRequest $understand,
        private IdentifyOeParts $identify,
        private SearchAutoDeltaParts $autoDelta,
        private SearchAutoZitaniaParts $autoZitania,
    ) {}

    public function execute(string $request, string $vin): IdentifyResult
    {
        $understanding = $this->understand->execute($request);

        if ($understanding->needsClarification()) {
            return new IdentifyResult($understanding, [], []);
        }

        $oeParts = $this->identify->execute($vin, $understanding->category, $understanding->keywords);

        $results = [];
        foreach ($oeParts as $part) {
            $results[] = $this->autoDelta->execute($part->oeNumber);
            $results[] = $this->autoZitania->execute($part->oeNumber);
        }

        return new IdentifyResult($understanding, $oeParts, array_values($results));
    }
}
```

- [ ] **Step 5: Run to verify pass, regenerate TS types**

Run: `PAO_DISABLE=1 php artisan test --compact tests/Feature/Identify/IdentifyAndSourcePartsTest.php`
Expected: PASS.
Run: `php artisan typescript:transform`

- [ ] **Step 6: Commit**

```bash
git add app/Data/IdentifyResult.php app/Actions/IdentifyAndSourceParts.php tests/Feature/Identify/IdentifyAndSourcePartsTest.php resources/js/types/generated.d.ts
git commit -m "feat: IdentifyAndSourceParts glue action"
```

---

## Task 7: Controller, request, routes

**Files:**
- Create: `app/Http/Controllers/IdentifyController.php`
- Create: `app/Http/Requests/IdentifyRequest.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Identify/IdentifyEndpointTest.php`

**Interfaces:**
- Consumes: `IdentifyAndSourceParts`, `IdentifyRequest`.
- Produces: routes `identify.index` (GET `/identify`), `identify.store` (POST `/identify`).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Ai\Agents\PartRequestUnderstander;
use App\Models\User;
use App\Services\PartsLink24\Contracts\PartsLink24Catalog;

it('renders the identify page for authed users', function (): void {
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->get('/identify')->assertOk();
});

it('requires a vin', function (): void {
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->postJson('/identify', ['request' => 'filtro', 'vin' => ''])
        ->assertJsonValidationErrorFor('vin');
});

it('returns understanding + results as json', function (): void {
    PartRequestUnderstander::fake([
        ['category' => 'filtro de óleo', 'keywords' => ['óleo'], 'clarifyingQuestion' => null, 'confidence' => 0.9],
    ]);
    $this->mock(PartsLink24Catalog::class)->shouldReceive('resolveOeParts')->andReturn([]);

    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))
        ->postJson('/identify', ['request' => 'filtro de óleo', 'vin' => 'WVWZZZ1JZXW000001'])
        ->assertOk()
        ->assertJsonStructure(['understanding' => ['category', 'keywords', 'clarifyingQuestion', 'confidence'], 'oeParts', 'results']);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `PAO_DISABLE=1 php artisan test --compact tests/Feature/Identify/IdentifyEndpointTest.php`
Expected: FAIL.

- [ ] **Step 3: Create `app/Http/Requests/IdentifyRequest.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class IdentifyRequest extends FormRequest
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
            'request' => ['required', 'string', 'max:500'],
            'vin' => ['required', 'string', 'min:11', 'max:17'],
        ];
    }

    public function requestText(): string
    {
        $value = $this->validated('request');

        return is_string($value) ? $value : '';
    }

    public function vin(): string
    {
        $value = $this->validated('vin');

        return is_string($value) ? $value : '';
    }
}
```

- [ ] **Step 4: Create `app/Http/Controllers/IdentifyController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\IdentifyAndSourceParts;
use App\Data\IdentifyResult;
use App\Http\Requests\IdentifyRequest;
use Inertia\Inertia;
use Inertia\Response;

final class IdentifyController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('identify/index');
    }

    public function store(IdentifyRequest $request, IdentifyAndSourceParts $action): IdentifyResult
    {
        return $action->execute($request->requestText(), $request->vin());
    }
}
```

- [ ] **Step 5: Add routes to `routes/web.php`** (inside the same authenticated group as `/parts`)

```php
Route::get('identify', [\App\Http\Controllers\IdentifyController::class, 'index'])->name('identify.index');
Route::post('identify', [\App\Http\Controllers\IdentifyController::class, 'store'])->name('identify.store');
```

- [ ] **Step 6: Run to verify pass, regenerate Wayfinder**

Run: `PAO_DISABLE=1 php artisan test --compact tests/Feature/Identify/IdentifyEndpointTest.php`
Expected: PASS (the render test needs the page from Task 8; if it fails only on missing page, proceed to Task 8 then re-run).
Run: `php artisan wayfinder:generate --with-form`

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/IdentifyController.php app/Http/Requests/IdentifyRequest.php routes/web.php tests/Feature/Identify/IdentifyEndpointTest.php resources/js/actions resources/js/routes
git commit -m "feat: /identify controller, request, routes"
```

---

## Task 8: /identify React page

**Files:**
- Create: `resources/js/pages/identify/index.tsx`
- Create: `resources/js/components/identify/identify-form.tsx`
- Test: `tests/Browser/Identify/IdentifyPageTest.php`

**Interfaces:**
- Consumes: `identify.store` route (Task 7), `App.Data.IdentifyResult`, existing `ResultsTable` + `ResultRow` (`@/components/parts/results-table`).

- [ ] **Step 1: Write the browser test** (`tests/Browser/Identify/IdentifyPageTest.php`)

```php
<?php

declare(strict_types=1);

use App\Ai\Agents\PartRequestUnderstander;
use App\Models\User;
use App\Services\AutoDelta\AutoDeltaToken;
use App\Services\PartsLink24\Contracts\PartsLink24Catalog;
use App\Data\OePart;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

it('identifies a part from a request and vin', function (): void {
    PartRequestUnderstander::fake([
        ['category' => 'filtro de óleo', 'keywords' => ['óleo'], 'clarifyingQuestion' => null, 'confidence' => 0.9],
    ]);
    $this->mock(PartsLink24Catalog::class)->shouldReceive('resolveOeParts')->andReturn([new OePart('OC 90', 'Filtro', 'OE')]);
    config()->set('suppliers.autodelta.catalog_url', 'https://cat.test/WebCat30WS');
    config()->set('suppliers.autodelta.search_url', 'https://cat.test/Tecdoc');
    config()->set('suppliers.autodelta.catalog_id', 'CAT');
    config()->set('suppliers.autozitania.username', 'user');
    config()->set('suppliers.autozitania.password', 'secret');
    Cache::put('autodelta.token', new AutoDeltaToken('KEY', 'USER', now()->addDay()), now()->addDay());
    $search = json_decode((string) file_get_contents(base_path('tests/Fixtures/AutoDelta/search-by-number.json')), true);
    $prices = json_decode((string) file_get_contents(base_path('tests/Fixtures/AutoDelta/trade-prices.json')), true);
    Http::fakeSequence('cat.test/*')->push($search['response'])->push($prices['response']);
    Process::fake(['*' => Process::result(output: (string) file_get_contents(base_path('tests/Fixtures/AutoZitania/search-output.json')))]);

    $this->actingAs(User::factory()->create(['email_verified_at' => now()]));
    $page = visit('/identify');
    $page->waitForEvent('networkidle');
    $page->fill('input[placeholder="Pedido do cliente"]', 'filtro de óleo para Golf')
        ->fill('input[placeholder="VIN"]', 'WVWZZZ1JZXW000001')
        ->press('Identificar');
    $page->waitForText('Preço')->assertSee('Fornecedor');
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `PAO_DISABLE=1 php artisan test --compact tests/Browser/Identify/IdentifyPageTest.php`
Expected: FAIL (page missing).

- [ ] **Step 3: Create `resources/js/components/identify/identify-form.tsx`**

```tsx
import { useHttp } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    ResultsTable,
    type ResultRow,
} from '@/components/parts/results-table';
import { store as identifyStore } from '@/routes/identify';

type IdentifyForm = { request: string; vin: string };

const SUPPLIER_LABELS: Record<App.Enums.Supplier, string> = {
    autodelta: 'Auto Delta',
    autozitania: 'Auto Zitânia',
};

export function IdentifyForm() {
    const http = useHttp<IdentifyForm, App.Data.IdentifyResult>({
        request: '',
        vin: '',
    });
    const [result, setResult] = useState<App.Data.IdentifyResult | null>(null);
    const [error, setError] = useState<string | null>(null);

    async function run() {
        if (!http.data.request.trim() || !http.data.vin.trim()) return;
        setError(null);
        try {
            setResult(await http.post(identifyStore.url()));
        } catch {
            setResult(null);
            setError('Falha na identificação. Tente novamente.');
        }
    }

    const rows: ResultRow[] = (result?.results ?? []).flatMap((r) =>
        r.variants.map((variant) => ({
            variant,
            supplier: variant.warehouse !== '' ? SUPPLIER_LABELS.autodelta : SUPPLIER_LABELS.autozitania,
            stockMode: variant.warehouse !== '' ? 'quantity' : 'availability',
            price: variant.purchasePrice ?? variant.retailPrice,
        })),
    );

    return (
        <div className="space-y-4">
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    void run();
                }}
                className="flex flex-col gap-2 sm:flex-row"
            >
                <Input
                    value={http.data.request}
                    onChange={(e) => http.setData('request', e.target.value)}
                    placeholder="Pedido do cliente"
                    autoFocus
                />
                <Input
                    value={http.data.vin}
                    onChange={(e) => http.setData('vin', e.target.value)}
                    placeholder="VIN"
                    className="sm:max-w-56"
                />
                <Button type="submit" disabled={http.processing}>
                    {http.processing ? 'A identificar…' : 'Identificar'}
                </Button>
            </form>

            {error !== null && <p className="text-sm text-destructive">{error}</p>}

            {result?.understanding.clarifyingQuestion != null && (
                <p className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                    {result.understanding.clarifyingQuestion}
                </p>
            )}

            {rows.length > 0 && <ResultsTable rows={rows} />}
        </div>
    );
}
```

- [ ] **Step 4: Create `resources/js/pages/identify/index.tsx`**

```tsx
import { Head } from '@inertiajs/react';
import { IdentifyForm } from '@/components/identify/identify-form';

export default function IdentifyIndex() {
    return (
        <>
            <Head title="Identificar peça" />
            <div className="mx-auto w-full max-w-5xl p-4">
                <h1 className="mb-4 text-lg font-semibold">Identificar peça</h1>
                <IdentifyForm />
            </div>
        </>
    );
}
```

- [ ] **Step 5: Build + run the browser test**

Run: `bun run build`
Run: `PAO_DISABLE=1 php artisan test --compact tests/Browser/Identify/IdentifyPageTest.php`
Expected: PASS. (Confirm the `supplier` split heuristic in the form matches how you want mixed rows labelled; adjust if needed.)

- [ ] **Step 6: Lint, typecheck, commit**

```bash
bun run lint && bun run test:types
git add resources/js/pages/identify resources/js/components/identify tests/Browser/Identify
git commit -m "feat: /identify page"
```

---

## Task 9: Full quality gate + progress log

- [ ] **Step 1: Run the gate**

Run: `bin/quality-gate.sh`
Expected: all green. Fix per the exit-code map if not (rector/pint/phpstan/coverage).

- [ ] **Step 2: Progress log + commit**

Append an entry to `docs/agent/progress.md` (Built / Next / Blocked / Decisions), then:

```bash
git add docs/agent/progress.md
git commit -m "docs: progress entry for /identify (Plan 1)"
```

---

## Deferred to Plan 2 (spike-gated)

- Real `PartsLink24HttpClient implements PartsLink24Catalog`: auth (`POST /auth/ext/api/1.1/login` `{account, user, password}` -> token, cached like Auto Delta; single-session handling), then VIN -> vehicle (brand catalog) -> category -> OE parts. Exact authenticated endpoints + category taxonomy captured in a short spike when a session is free (dedicated account).
- Swap the `AppServiceProvider` binding from `FakePartsLink24Catalog` to the real client.
- Fixtures captured from the spike drive the client's `Http::fake` tests.

## Spec coverage check

- xAI default provider → Task 1. Grok understanding + clarifying question → Task 3. VIN-required → Task 7 (`IdentifyRequest`). PartsLink24 identifier (contract now, real client Plan 2) → Tasks 4/5 + Deferred. Phase 1 pricing reuse → Task 6. `/identify` page, `/parts` untouched → Tasks 7-8. Error states (no VIN, clarifying question, failure) → Tasks 6-8. Testing + gate → all tasks + Task 9.
