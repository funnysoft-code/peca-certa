# Async Streaming /identify Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `/identify` return instantly by moving the slow Grok + PartsLink24 + supplier pricing off the web request onto queued jobs that persist their results and broadcast them over Reverb, so the page streams results in progressively and reloads them from the DB on refresh (fixing the 30s timeout).

**Architecture:** `POST /identify` creates a persisted `SearchRun` and dispatches `Bus::chain([UnderstandRequestJob, IdentifyOePartsJob])`; the identify job fans out one `PriceSupplierJob` per (OE candidate x supplier) onto per-supplier Horizon queues (Auto Delta parallel, Zitania serialized via `WithoutOverlapping`). Jobs write rows and broadcast two events (`SearchRunAdvanced`, `SupplierResultReady`) on a private `search-run.{id}` channel. The React run page seeds from persisted DB props and merges broadcasts via `@laravel/echo-react`.

**Tech Stack:** Laravel 13, Horizon (redis queues), Reverb + `@laravel/echo-react`, Inertia 3 + React 19, Pest 5, PHPStan max.

## Global Constraints

- `declare(strict_types=1)` at the top of every PHP file. All classes `final` (`final readonly` for stateless DTOs/Actions; Jobs and Models are `final` not readonly; Controllers `final` not readonly).
- Jobs use `handle()` (never `execute()` — that is Actions). Actions use `execute()`. Models/relationships per `app/Models/CLAUDE.md`: `HasUuids`, `casts()` method (never `$casts`), `foreignUuid(...)->constrained()->cascadeOnDelete()`.
- Enums: TitleCase keys, backed by lowercase string values, `#[TypeScript]` when the frontend consumes them.
- DTOs: `final readonly implements JsonSerializable`, `#[TypeScript]`, `jsonSerialize()` key-by-key (PHPStan max).
- `env()` only inside `config/`; everywhere else `config()` (typed `config()->string(...)` etc.). Never log secrets.
- Octane-safe: jobs hold no request-scoped state; resolve Actions inside `handle()` via method injection or `app(...)`.
- No em dashes anywhere (code, comments, commits, PT copy). Use commas/parentheses.
- Broadcasting events implement `ShouldBroadcast`, `broadcastOn(): PrivateChannel("search-run.{$run->id}")`.
- Migrations: anonymous class (`return new class extends Migration`), PostgreSQL-friendly types, `$table->uuid('id')->primary()`.
- After controller/route changes run `php artisan wayfinder:generate --with-form`; after `#[TypeScript]` enum/DTO changes run `php artisan typescript:transform`; both regenerated outputs committed.
- Every task ends with the FULL gate green: `PAO_DISABLE=1 bin/quality-gate.sh` exit 0 (rector, pint, phpstan max, wayfinder drift, bun lint/types, 100% code + type coverage, Pest browser). Run tests with `PAO_DISABLE=1`.
- Any NEW `app/` subdirectory containing PHP files needs a `CLAUDE.md` (arch rule): `app/Jobs` and `app/Events` already have one or need one; `app/Data` and `app/Enums` exist.

---

### Task 1: Run/lookup enums

**Files:**
- Create: `app/Enums/SearchRunKind.php`, `app/Enums/SearchRunStatus.php`, `app/Enums/SupplierLookupStatus.php`
- Test: `tests/Unit/Enums/SearchEnumsTest.php`

**Interfaces (Produces):** three `#[TypeScript]` string enums consumed by the models (Task 2), DTOs (Task 3), jobs (Tasks 5-6), and frontend types.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\SearchRunKind;
use App\Enums\SearchRunStatus;
use App\Enums\SupplierLookupStatus;

it('exposes the search run and lookup enum values', function (): void {
    expect(SearchRunKind::Identify->value)->toBe('identify')
        ->and(SearchRunKind::Parts->value)->toBe('parts')
        ->and(SearchRunStatus::cases())->toHaveCount(4)
        ->and(SearchRunStatus::Pending->value)->toBe('pending')
        ->and(SupplierLookupStatus::Empty->value)->toBe('empty')
        ->and(SupplierLookupStatus::cases())->toHaveCount(5);
});
```

- [ ] **Step 2: Run it to verify it fails** — `php artisan test --compact tests/Unit/Enums/SearchEnumsTest.php` → FAIL (enums missing).

- [ ] **Step 3: Create the enums**

```php
<?php
// app/Enums/SearchRunKind.php
declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum SearchRunKind: string
{
    case Identify = 'identify';
    case Parts = 'parts';
}
```

```php
<?php
// app/Enums/SearchRunStatus.php
declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum SearchRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
}
```

```php
<?php
// app/Enums/SupplierLookupStatus.php
declare(strict_types=1);

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum SupplierLookupStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
    case Empty = 'empty';
}
```

- [ ] **Step 4: Run test (PASS) + `php artisan typescript:transform`** and confirm the three enums appear in `resources/js/types/generated.d.ts`.

- [ ] **Step 5: Commit**

```bash
git add app/Enums/SearchRunKind.php app/Enums/SearchRunStatus.php app/Enums/SupplierLookupStatus.php tests/Unit/Enums/SearchEnumsTest.php resources/js/types/generated.d.ts
git commit -m "feat: search run + supplier lookup enums"
```

---

### Task 2: Migrations, models, factories

**Files:**
- Create: `database/migrations/XXXX_create_search_runs_table.php`, `database/migrations/XXXX_create_supplier_lookups_table.php`
- Create: `app/Models/SearchRun.php`, `app/Models/SupplierLookup.php`
- Create: `database/factories/SearchRunFactory.php`, `database/factories/SupplierLookupFactory.php`
- Test: `tests/Feature/Models/SearchRunTest.php`

**Interfaces:**
- Consumes: the Task 1 enums, `App\Enums\Supplier`.
- Produces:
  - `SearchRun` (`HasUuids`, `HasFactory`): columns `id, user_id, kind, request_text, vin, reference, understanding (array), oe_parts (array), status, timestamps`; `casts()` maps `kind→SearchRunKind`, `status→SearchRunStatus`, `understanding→'array'`, `oe_parts→'array'`; relations `user(): BelongsTo<User,$this>`, `lookups(): HasMany<SupplierLookup,$this>`.
  - `SupplierLookup` (`HasUuids`, `HasFactory`): columns `id, search_run_id, supplier, query, oe_description, status, result (array), error, timestamps`; `casts()` maps `supplier→Supplier`, `status→SupplierLookupStatus`, `result→'array'`; relation `run(): BelongsTo<SearchRun,$this>`.
- These are consumed by Tasks 3-8.

- [ ] **Step 1: Create migrations** (`php artisan make:migration create_search_runs_table` etc., then replace bodies):

```php
<?php
// create_search_runs_table
declare(strict_types=1);

use App\Enums\SearchRunStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->text('request_text')->nullable();
            $table->string('vin')->nullable();
            $table->string('reference')->nullable();
            $table->json('understanding')->nullable();
            $table->json('oe_parts')->nullable();
            $table->string('status')->default(SearchRunStatus::Pending->value);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_runs');
    }
};
```

```php
<?php
// create_supplier_lookups_table
declare(strict_types=1);

use App\Enums\SupplierLookupStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_lookups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('search_run_id')->constrained()->cascadeOnDelete();
            $table->string('supplier');
            $table->string('query');
            $table->string('oe_description')->nullable();
            $table->string('status')->default(SupplierLookupStatus::Pending->value);
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['search_run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_lookups');
    }
};
```

- [ ] **Step 2: Create the models**

```php
<?php
// app/Models/SearchRun.php
declare(strict_types=1);

namespace App\Models;

use App\Enums\SearchRunKind;
use App\Enums\SearchRunStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $user_id
 * @property array<string, mixed>|null $understanding
 * @property list<array<string, mixed>>|null $oe_parts
 */
final class SearchRun extends Model
{
    /** @use HasFactory<\Database\Factories\SearchRunFactory> */
    use HasFactory;
    use HasUuids;

    protected $guarded = [];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<SupplierLookup, $this> */
    public function lookups(): HasMany
    {
        return $this->hasMany(SupplierLookup::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => SearchRunKind::class,
            'status' => SearchRunStatus::class,
            'understanding' => 'array',
            'oe_parts' => 'array',
        ];
    }
}
```

```php
<?php
// app/Models/SupplierLookup.php
declare(strict_types=1);

namespace App\Models;

use App\Enums\Supplier;
use App\Enums\SupplierLookupStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $search_run_id
 * @property array<string, mixed>|null $result
 */
final class SupplierLookup extends Model
{
    /** @use HasFactory<\Database\Factories\SupplierLookupFactory> */
    use HasFactory;
    use HasUuids;

    protected $guarded = [];

    /** @return BelongsTo<SearchRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(SearchRun::class, 'search_run_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'supplier' => Supplier::class,
            'status' => SupplierLookupStatus::class,
            'result' => 'array',
        ];
    }
}
```

- [ ] **Step 3: Create factories** (`SearchRunFactory` → `user_id` via `User::factory()`, `kind` Identify, `status` Pending, `request_text`/`vin` fake; `SupplierLookupFactory` → `search_run_id` via `SearchRun::factory()`, `supplier` AutoDelta, `query` fake, `status` Pending). Follow the existing factory style.

- [ ] **Step 4: Write + run the model test**

```php
<?php

declare(strict_types=1);

use App\Enums\SearchRunKind;
use App\Enums\SupplierLookupStatus;
use App\Models\SearchRun;
use App\Models\SupplierLookup;

it('persists a run with lookups and casts enums + json', function (): void {
    $run = SearchRun::factory()->create(['kind' => SearchRunKind::Identify, 'oe_parts' => [['oeNumber' => 'OC 90']]]);
    $lookup = SupplierLookup::factory()->for($run, 'run')->create(['status' => SupplierLookupStatus::Done, 'result' => ['query' => 'OC 90']]);

    expect($run->kind)->toBe(SearchRunKind::Identify)
        ->and($run->oe_parts)->toBe([['oeNumber' => 'OC 90']])
        ->and($run->lookups)->toHaveCount(1)
        ->and($lookup->status)->toBe(SupplierLookupStatus::Done)
        ->and($lookup->run->is($run))->toBeTrue();
});
```

Run: `php artisan migrate` then `php artisan test --compact tests/Feature/Models/SearchRunTest.php` → PASS.

- [ ] **Step 5: Commit** (`git add` migrations, models, factories, test; message `feat: SearchRun + SupplierLookup models and migrations`).

---

### Task 3: Frontend-facing DTOs (`SearchRunData`, `SupplierLookupData`)

One serialization shape shared by the HTTP props (Task 8) and the broadcast payloads (Task 4), so the run page has a single typed contract.

**Files:**
- Create: `app/Data/SupplierLookupData.php`, `app/Data/SearchRunData.php`
- Test: `tests/Unit/Data/SearchRunDataTest.php`

**Interfaces (Produces):**
- `SupplierLookupData` — `final readonly`, `#[TypeScript]`, from `SupplierLookup`: `{ id, supplier (Supplier), query, oeDescription (?string), status (SupplierLookupStatus), result (?PartSearchResult) }`; static `fromModel(SupplierLookup $lookup): self`.
- `SearchRunData` — `final readonly`, `#[TypeScript]`, from `SearchRun` (with `lookups` loaded): `{ id, kind, status, requestText (?string), vin (?string), reference (?string), understanding (?PartRequestUnderstanding), oeParts (list<OePart>), lookups (list<SupplierLookupData>) }`; static `fromModel(SearchRun $run): self`.
- Consumed by Task 4 (events) and Task 8 (controller props). Note `understanding`/`oeParts` are rehydrated from the model's array columns into the existing `PartRequestUnderstanding`/`OePart` DTOs so the frontend type matches Plan 1/2.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Data\SearchRunData;
use App\Enums\SearchRunStatus;
use App\Models\SearchRun;
use App\Models\SupplierLookup;

it('builds SearchRunData from a run with lookups', function (): void {
    $run = SearchRun::factory()->create([
        'status' => SearchRunStatus::Running,
        'understanding' => ['category' => 'filtro de óleo', 'searchTerm' => 'oil filter', 'keywords' => [], 'clarifyingQuestion' => null, 'confidence' => 0.9],
        'oe_parts' => [['oeNumber' => '11427622446', 'description' => 'oil filter element', 'brand' => 'OE']],
    ]);
    SupplierLookup::factory()->for($run, 'run')->create();

    $data = SearchRunData::fromModel($run->load('lookups'));

    expect($data->status)->toBe(SearchRunStatus::Running)
        ->and($data->understanding?->searchTerm)->toBe('oil filter')
        ->and($data->oeParts)->toHaveCount(1)
        ->and($data->oeParts[0]->oeNumber)->toBe('11427622446')
        ->and($data->lookups)->toHaveCount(1)
        ->and($data->jsonSerialize())->toHaveKeys(['id', 'kind', 'status', 'understanding', 'oeParts', 'lookups']);
});
```

- [ ] **Step 2: Run to verify it fails.**

- [ ] **Step 3: Implement the DTOs.** `SupplierLookupData::fromModel` maps fields and rebuilds `PartSearchResult` from `$lookup->result` when present (reuse the existing `PartSearchResult`/`PartVariant` constructors, or a `fromArray` you add — prefer building `PartSearchResult` from the stored array shape which already matches `jsonSerialize()`). `SearchRunData::fromModel` rebuilds `PartRequestUnderstanding` from `$run->understanding` and maps `$run->oe_parts` to `OePart[]`, and maps `$run->lookups` to `SupplierLookupData[]`. Full key-by-key `jsonSerialize()` on both.

Note for the implementer: `PartSearchResult`/`PartVariant`/`PartRequestUnderstanding`/`OePart` currently have no `fromArray()`. Add minimal static `fromArray(array): self` factories to those four DTOs (pure, typed, PHPStan-safe) so the stored JSON round-trips back into typed DTOs. Keep `jsonSerialize()` unchanged. This is the only change to the Plan 1/2 DTOs.

- [ ] **Step 4: Run test (PASS) + `php artisan typescript:transform`**; confirm `App.Data.SearchRunData` + `App.Data.SupplierLookupData` in generated types.

- [ ] **Step 5: Commit** (`feat: SearchRunData + SupplierLookupData DTOs`).

---

### Task 4: Broadcast events + channel authorization

**Files:**
- Create: `app/Events/SearchRunAdvanced.php`, `app/Events/SupplierResultReady.php`
- Modify: `routes/channels.php`
- Create (if missing): `app/Events/CLAUDE.md`
- Test: `tests/Feature/Events/SearchRunBroadcastTest.php`

**Interfaces (Produces):**
- `SearchRunAdvanced(SearchRun $run)` — `ShouldBroadcast`; `broadcastOn(): PrivateChannel("search-run.{$run->id}")`; `broadcastAs(): 'run.advanced'`; `broadcastWith(): ['run' => SearchRunData::fromModel($run->load('lookups'))->jsonSerialize()]`.
- `SupplierResultReady(SupplierLookup $lookup)` — `ShouldBroadcast`; same channel using `$lookup->search_run_id`; `broadcastAs(): 'lookup.ready'`; `broadcastWith(): ['lookup' => SupplierLookupData::fromModel($lookup)->jsonSerialize()]`.
- Channel `search-run.{id}` authorized to the owning user.
- Consumed by Tasks 5-6 (dispatch) and Task 8 (frontend subscribes).

- [ ] **Step 1: Write the failing test** (fake broadcasting, assert channel + payload)

```php
<?php

declare(strict_types=1);

use App\Events\SearchRunAdvanced;
use App\Models\SearchRun;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;

it('broadcasts run advances on the private run channel', function (): void {
    Event::fake();
    $run = SearchRun::factory()->create();

    event(new SearchRunAdvanced($run));

    Event::assertDispatched(SearchRunAdvanced::class, function (SearchRunAdvanced $e) use ($run): bool {
        $channels = $e->broadcastOn();
        return $channels[0] instanceof PrivateChannel
            && $channels[0]->name === 'private-search-run.'.$run->id;
    });
});

it('authorizes the run channel only for the owner', function (): void {
    $owner = User::factory()->create();
    $run = SearchRun::factory()->for($owner)->create();

    $this->actingAs($owner)
        ->post('/broadcasting/auth', ['channel_name' => 'private-search-run.'.$run->id, 'socket_id' => '1234.5678'])
        ->assertOk();

    $this->actingAs(User::factory()->create())
        ->post('/broadcasting/auth', ['channel_name' => 'private-search-run.'.$run->id, 'socket_id' => '1234.5678'])
        ->assertForbidden();
});
```

The `/broadcasting/auth` route is registered by the framework; the two `actingAs` calls exercise the real channel closure (owner authorized, non-owner forbidden). If the repo's Reverb/broadcasting setup names the auth route differently, confirm via `php artisan route:list --path=broadcasting` and use that path.

- [ ] **Step 2: Run to verify it fails.**

- [ ] **Step 3: Implement the events** (`ShouldBroadcast`, `Dispatchable`, `SerializesModels`; `broadcastOn`/`broadcastAs`/`broadcastWith` as specified). Add `app/Events/CLAUDE.md` if the directory lacks one (describe: events are past-tense, `ShouldBroadcast`, broadcast on the private run channel; no em dashes).

- [ ] **Step 4: Authorize the channel** in `routes/channels.php`:

```php
use App\Models\SearchRun;

Broadcast::channel('search-run.{id}', fn (User $user, string $id): bool => SearchRun::query()->whereKey($id)->value('user_id') === $user->id);
```

- [ ] **Step 5: Run tests (PASS) + full gate.** Commit (`feat: SearchRun broadcast events + channel auth`).

---

### Task 5: `PriceSupplierJob` (per-supplier pricing, run completion)

**Files:**
- Create: `app/Jobs/PriceSupplierJob.php`
- Create (if missing): `app/Jobs/CLAUDE.md` (exists per repo; confirm it documents `handle()`, attributes, `failed()`)
- Test: `tests/Feature/Jobs/PriceSupplierJobTest.php`

**Interfaces:**
- Consumes: `SupplierLookup`, `SearchAutoDeltaParts::execute(string): PartSearchResult`, `SearchAutoZitaniaParts::execute(string): PartSearchResult`, `Supplier`, `SupplierLookupStatus`, events (Task 4).
- Produces: `PriceSupplierJob(SupplierLookup $lookup)` — `ShouldQueue`; queue chosen by supplier (`autodelta` or `zitania`) in the constructor via `$this->onQueue(...)`; `$timeout` set per supplier (autodelta 30, zitania 90); `$tries = 2`; `middleware()` returns `[new WithoutOverlapping('zitania')]` for Zitania only (serialize the single browser session). `handle()` runs the supplier action, writes the lookup (`Done` with `result`, or `Empty` when no variants, or lets `failed()` handle exceptions), broadcasts `SupplierResultReady`, then runs the shared run-completion check. `failed(Throwable $e)` writes `Failed` + `error`, broadcasts, and runs the completion check.
- Consumed by Task 6 (dispatched in the fan-out).

- [ ] **Step 1: Write failing tests**

```php
<?php

declare(strict_types=1);

use App\Enums\Supplier;
use App\Enums\SearchRunStatus;
use App\Enums\SupplierLookupStatus;
use App\Jobs\PriceSupplierJob;
use App\Actions\SearchAutoDeltaParts;
use App\Data\PartSearchResult;
use App\Data\PartVariant;
use App\Events\SearchRunAdvanced;
use App\Events\SupplierResultReady;
use App\Models\SearchRun;
use App\Models\SupplierLookup;
use Illuminate\Support\Facades\Event;

it('prices a lookup, stores the result, broadcasts, and completes the run when last', function (): void {
    Event::fake([SupplierResultReady::class, SearchRunAdvanced::class]);
    $run = SearchRun::factory()->create(['status' => SearchRunStatus::Running]);
    $lookup = SupplierLookup::factory()->for($run, 'run')->create(['supplier' => Supplier::AutoDelta, 'query' => 'OC 90', 'status' => SupplierLookupStatus::Pending]);

    $this->mock(SearchAutoDeltaParts::class)
        ->shouldReceive('execute')->once()->with('OC 90')
        ->andReturn(new PartSearchResult('OC 90', [new PartVariant('MANN', 'OC 90', 'OC 90', 3.5, null, 'EUR', 2, true, 'W1')]));

    (new PriceSupplierJob($lookup))->handle(app(SearchAutoDeltaParts::class), app(\App\Actions\SearchAutoZitaniaParts::class));

    $lookup->refresh();
    $run->refresh();
    expect($lookup->status)->toBe(SupplierLookupStatus::Done)
        ->and($lookup->result['query'])->toBe('OC 90')
        ->and($run->status)->toBe(SearchRunStatus::Done); // only lookup -> run completes
    Event::assertDispatched(SupplierResultReady::class);
    Event::assertDispatched(SearchRunAdvanced::class); // completion
});

it('marks the lookup empty when no variants come back', function (): void {
    $run = SearchRun::factory()->create(['status' => SearchRunStatus::Running]);
    $lookup = SupplierLookup::factory()->for($run, 'run')->create(['supplier' => Supplier::AutoDelta, 'query' => 'X']);
    $this->mock(SearchAutoDeltaParts::class)->shouldReceive('execute')->andReturn(new PartSearchResult('X', []));

    (new PriceSupplierJob($lookup))->handle(app(SearchAutoDeltaParts::class), app(\App\Actions\SearchAutoZitaniaParts::class));

    expect($lookup->refresh()->status)->toBe(SupplierLookupStatus::Empty);
});

it('does not complete the run while a sibling lookup is still pending', function (): void {
    $run = SearchRun::factory()->create(['status' => SearchRunStatus::Running]);
    $done = SupplierLookup::factory()->for($run, 'run')->create(['supplier' => Supplier::AutoDelta, 'query' => 'A']);
    SupplierLookup::factory()->for($run, 'run')->create(['supplier' => Supplier::AutoZitania, 'query' => 'A', 'status' => SupplierLookupStatus::Pending]);
    $this->mock(SearchAutoDeltaParts::class)->shouldReceive('execute')->andReturn(new PartSearchResult('A', []));

    (new PriceSupplierJob($done))->handle(app(SearchAutoDeltaParts::class), app(\App\Actions\SearchAutoZitaniaParts::class));

    expect($run->refresh()->status)->toBe(SearchRunStatus::Running);
});
```

- [ ] **Step 2: Run to verify they fail.**

- [ ] **Step 3: Implement `PriceSupplierJob`.** Constructor sets `$this->onQueue($lookup->supplier === Supplier::AutoZitania ? 'zitania' : 'autodelta')` and `$this->timeout` accordingly. `handle(SearchAutoDeltaParts $autoDelta, SearchAutoZitaniaParts $autoZitania)`:
  - mark lookup `Running` (optional), pick the action by `$this->lookup->supplier`, call `execute($this->lookup->query)` → `PartSearchResult`.
  - write `result` (the DTO `jsonSerialize()`), status `Empty` when `variants` empty else `Done`; `oe_description` already set at creation.
  - `event(new SupplierResultReady($this->lookup))`.
  - call `private completeRunIfFinished()`.
  - `completeRunIfFinished()`: in a `DB::transaction`, `SearchRun::whereKey($runId)->lockForUpdate()`, count lookups whose status is `Pending` or `Running`; if zero and run not already terminal, set run `Done`, save, then `event(new SearchRunAdvanced($run))` (outside or after commit).
  - `middleware(): array` returns `[new WithoutOverlapping('zitania')]` only when supplier is Zitania (Auto Delta has no overlap constraint).
  - `failed(Throwable $e)`: set lookup `Failed`, `error = $e->getMessage()` (do not include secrets), broadcast, run completion check.

- [ ] **Step 4: Run tests (PASS) + full gate.** Commit (`feat: PriceSupplierJob with per-supplier queue + run completion`).

---

### Task 6: `UnderstandRequestJob` + `IdentifyOePartsJob` (chain + fan-out)

**Files:**
- Create: `app/Jobs/UnderstandRequestJob.php`, `app/Jobs/IdentifyOePartsJob.php`
- Test: `tests/Feature/Jobs/IdentifyChainTest.php`

**Interfaces:**
- Consumes: `SearchRun`, `UnderstandPartRequest::execute(string): PartRequestUnderstanding`, `IdentifyOeParts::execute(string $vin, string $searchTerm, array $keywords): list<OePart>`, `Supplier`, `PriceSupplierJob` (Task 5), events (Task 4).
- Produces:
  - `UnderstandRequestJob(SearchRun $run)` — queue `ai`, `$tries = 1`, timeout ~120. `handle(UnderstandPartRequest $understand)`: set run `Running`; run understand on `$run->request_text`; store `understanding` (DTO `jsonSerialize()`); broadcast `SearchRunAdvanced`. If `needsClarification()`, set run `Done` and broadcast (the chained identify job will no-op).
  - `IdentifyOePartsJob(SearchRun $run)` — queue `partslink24`, `WithoutOverlapping('partslink24')`, `$tries = 2`, timeout ~90. `handle(IdentifyOeParts $identify)`: reload run; if run is already `Done`/`Failed` (clarification or abort), return. Rebuild the `PartRequestUnderstanding` from `$run->understanding`; run identify with its `searchTerm` + `keywords`; store `oe_parts`; broadcast `SearchRunAdvanced`. For each OE part create two `SupplierLookup` rows (AutoDelta, AutoZitania) with `query = oeNumber`, `oe_description = description`, and dispatch a `PriceSupplierJob` per row. If there are no OE parts, set run `Done` and broadcast (nothing to price).
- Consumed by Task 8 (controller chains these two).

- [ ] **Step 1: Write failing tests** — cover: happy path creates N*2 lookups + dispatches N*2 `PriceSupplierJob` (`Bus::fake()`, assert `Bus::assertDispatched(PriceSupplierJob::class, ...)` count) and stores understanding/oe_parts; clarification path sets run `Done` and the identify job no-ops (dispatches nothing); no-OE-parts path sets run `Done`.

```php
// sketch (implementer completes assertions)
Bus::fake([PriceSupplierJob::class]);
PartRequestUnderstander::fake([[ 'category' => 'filtro de óleo', 'searchTerm' => 'oil filter', 'keywords' => ['óleo'], 'clarifyingQuestion' => null, 'confidence' => 0.9 ]]);
$this->mock(PartsLink24Catalog::class)->shouldReceive('resolveOeParts')->andReturn([new OePart('11427622446', 'oil filter element', 'OE')]);
$run = SearchRun::factory()->create(['request_text' => 'filtro de óleo', 'vin' => 'WMWSU91010T717700']);

(new UnderstandRequestJob($run))->handle(app(UnderstandPartRequest::class));
(new IdentifyOePartsJob($run))->handle(app(IdentifyOeParts::class));

expect($run->refresh()->oe_parts)->toHaveCount(1)
    ->and($run->lookups()->count())->toBe(2); // AutoDelta + AutoZitania
Bus::assertDispatchedTimes(PriceSupplierJob::class, 2);
```

Plus a clarification test:
```php
PartRequestUnderstander::fake([[ 'category' => '', 'searchTerm' => '', 'keywords' => [], 'clarifyingQuestion' => 'Qual o carro?', 'confidence' => 0.1 ]]);
$run = SearchRun::factory()->create(['request_text' => 'uma peça']);
(new UnderstandRequestJob($run))->handle(app(UnderstandPartRequest::class));
(new IdentifyOePartsJob($run))->handle(app(IdentifyOeParts::class));
expect($run->refresh()->status)->toBe(SearchRunStatus::Done)->and($run->lookups()->count())->toBe(0);
```

- [ ] **Step 2: Run to verify they fail.**

- [ ] **Step 3: Implement both jobs** per the Interfaces. Use `app(...)` or method injection for the actions. Rebuild `PartRequestUnderstanding` via the `fromArray()` added in Task 3. Dispatch `PriceSupplierJob` normally (not chained) so the fan-out runs in parallel across the supplier queues.

- [ ] **Step 4: Run tests (PASS) + full gate.** Commit (`feat: understand + identify jobs (chain + supplier fan-out)`).

---

### Task 7: Horizon supervisors + dev runtime

**Files:**
- Modify: `config/horizon.php`
- Modify: `composer.json` (`scripts.dev`)
- Modify: `.env.example`
- Test: `tests/Feature/Config/HorizonQueuesTest.php`

**Interfaces (Produces):** Horizon supervisors that serve the `autodelta`, `zitania` (1 process), `partslink24` (1 process) queues in addition to the existing `default`/`ai`/`media`. `composer run dev` boots Horizon instead of `queue:listen`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

it('defines serialized supervisors for the single-session suppliers', function (): void {
    $defaults = config('horizon.defaults');

    expect($defaults)->toHaveKeys(['supervisor-autodelta', 'supervisor-zitania', 'supervisor-partslink24'])
        ->and($defaults['supervisor-zitania']['queue'])->toBe(['zitania'])
        ->and($defaults['supervisor-zitania']['maxProcesses'])->toBe(1)
        ->and($defaults['supervisor-partslink24']['maxProcesses'])->toBe(1)
        ->and($defaults['supervisor-autodelta']['queue'])->toBe(['autodelta']);
});
```

- [ ] **Step 2: Run to verify it fails.**

- [ ] **Step 3: Add the supervisors** to `config/horizon.php` `defaults` (and matching `environments.production`/`local` entries):
  - `supervisor-autodelta`: `queue => ['autodelta']`, `balance => 'auto'`, `maxProcesses => 3` (prod) / 2 (local), `timeout => 60`, `tries => 2`.
  - `supervisor-zitania`: `queue => ['zitania']`, `balance => 'simple'`, `maxProcesses => 1`, `timeout => 120`, `tries => 2`.
  - `supervisor-partslink24`: `queue => ['partslink24']`, `balance => 'simple'`, `maxProcesses => 1`, `timeout => 120`, `tries => 2`.

- [ ] **Step 4: Swap the dev script** in `composer.json`: replace the `"php artisan queue:listen --tries=1"` entry with `"php artisan horizon"` and rename it `queue`→`horizon` in `--names` (keep `reverb:start` and `bun run dev`). Add a short comment-free note in `.env.example` confirming `QUEUE_CONNECTION=redis` (Horizon requires redis).

- [ ] **Step 5: Run test (PASS) + full gate.** Commit (`feat: horizon supervisors for supplier queues + dev boots horizon`).

---

### Task 8: `IdentifyController` (create/store/show) + routes

**Files:**
- Modify: `app/Http/Controllers/IdentifyController.php`
- Modify: `routes/web.php`
- Modify: `app/Http/Requests/IdentifyRequest.php` (unchanged rules; confirm helpers)
- Test: `tests/Feature/Identify/IdentifyEndpointTest.php` (rewrite for the run flow)

**Interfaces:**
- Consumes: `SearchRun`, `SearchRunData` (Task 3), `UnderstandRequestJob` + `IdentifyOePartsJob` (Task 6), `IdentifyRequest`.
- Produces:
  - `create()` GET `identify.create` (`/identify`) → `Inertia::render('identify/index', ['recentRuns' => <last 5 identify runs of the user as SearchRunData>])`.
  - `store(IdentifyRequest)` POST `identify.store` (`/identify`) → create `SearchRun` (kind Identify, `request_text`, `vin`, status Pending, `user_id`), `Bus::chain([new UnderstandRequestJob($run), new IdentifyOePartsJob($run)])->dispatch()`, `to_route('identify.show', $run)`.
  - `show(Request, SearchRun $run)` GET `identify.show` (`/identify/{run}`) → `abort_unless($run->user_id === $request->user()->id, 403)`, `Inertia::render('identify/show', ['run' => SearchRunData::fromModel($run->load('lookups'))])`.
  - Routes named `identify.create`, `identify.store`, `identify.show`; keep `throttle:10,1` on store.

- [ ] **Step 1: Rewrite the endpoint test** to the run flow (use `Bus::fake()`): `store` creates a `SearchRun` and dispatches the chain; `store` redirects to `identify.show`; `show` authorizes the owner (200 for owner, 403 for another user); `show` renders `identify/show` with a `run` prop. Keep faking Grok/suppliers out of the controller test (jobs are faked). Do not delete other tests; this file is rewritten in place.

- [ ] **Step 2: Run to verify it fails.**

- [ ] **Step 3: Implement the controller + routes.** Use `#[CurrentUser]` or `$request->user()` per repo convention (controllers are `final`, method injection). Run `php artisan wayfinder:generate --with-form`.

- [ ] **Step 4: Run tests (PASS) + full gate** (wayfinder drift must be clean). Commit (`feat: identify run controller (create/store/show)`).

---

### Task 9: Streaming run page + form, browser test, retire the sync path

**Files:**
- Create: `resources/js/pages/identify/show.tsx`
- Rewrite: `resources/js/pages/identify/index.tsx`, `resources/js/components/identify/identify-form.tsx`
- Create: `resources/js/components/identify/run-results.tsx` (streaming results view)
- Delete (superseded, this plan authorizes it): `app/Actions/IdentifyAndSourceParts.php`, `app/Data/IdentifyResult.php`, `tests/Feature/Identify/IdentifyAndSourcePartsTest.php`, `tests/Unit/Data/IdentifyResultTest.php`
- Test: `tests/Browser/Identify/IdentifyPageTest.php` (rewrite for the run page)

**Interfaces:**
- Consumes: `identify.create/store/show` (Wayfinder), `App.Data.SearchRunData`, `App.Data.SupplierLookupData`, the enums, `results-table.tsx`, `@laravel/echo-react` `useEcho`, the scaffolded `echo-listener.tsx` pattern.

- [ ] **Step 1: Rewrite the form (`identify/index.tsx` + `identify-form.tsx`)** to a `useForm`/`router.post` that submits `{ request, vin }` to `identify.store` and follows the redirect to `identify.show` (Inertia handles the redirect; no `useHttp` result handling). Add `.layout = { breadcrumbs: [...] }` (fixing the missing-breadcrumbs inconsistency). Render the user's `recentRuns` as links to `identify.show`.

- [ ] **Step 2: Build the run page (`identify/show.tsx` + `run-results.tsx`).** Seed state from the `run` prop (`SearchRunData`). Subscribe to `search-run.{run.id}` (private) via `useEcho` for events `.run.advanced` and `.lookup.ready`, merging: `run.advanced` replaces run-level fields (status, understanding, oeParts) and upserts any lookups in its payload; `lookup.ready` upserts that one lookup by id. Render:
  - the understanding + clarifying question (if any),
  - the OE candidates,
  - a merged results table (reuse `results-table.tsx` `ResultRow` shape) built from `lookups` whose `result` is present, split into available/unavailable (collapsible) with per-supplier "Abrir em" links (`result.searchUrl`),
  - per-section skeletons while `status`/lookup statuses are `pending`/`running`, and a clear failed state for `failed` lookups.
  - Guard Echo with the hydration pattern from `echo-listener.tsx` (render results from props first; subscribe after mount).

- [ ] **Step 3: Delete the superseded sync path** (the four files listed). This plan explicitly authorizes deleting `IdentifyAndSourcePartsTest.php` and `IdentifyResultTest.php` because the job chain + `SearchRunData` replace `IdentifyAndSourceParts`/`IdentifyResult`. Remove any remaining references.

- [ ] **Step 4: Rewrite the browser test** (`tests/Browser/Identify/IdentifyPageTest.php`): fake Grok + suppliers (as today), `Bus`-run the jobs synchronously OR drive the real jobs via `queue:work --once`/sync so the run reaches `done`, then `visit('/identify')`, `waitForEvent('networkidle')`, fill `request`/`vin`, submit, land on `/identify/{run}`, and assert the persisted results render (`assertSee('Fornecedor')`, a priced OE row). Live socket streaming is out of browser-test scope; assert the persisted-render path (seeded from `show` props). Follow `tests/CLAUDE.md` (networkidle wait; selectors match the rewritten form).

- [ ] **Step 5: Regenerate + full gate.** `php artisan typescript:transform`, `php artisan wayfinder:generate --with-form`, `bun run build` if needed, then `PAO_DISABLE=1 bin/quality-gate.sh` green (100% coverage, browser). Commit (`feat: streaming /identify run page + retire sync fan-out`).

---

## Post-Plan Verification (controller-run, not a task)

With Horizon + Reverb running locally (`composer run dev`), submit `/identify` with `filtro de óleo` + VIN `WMWSU91010T717700`: the page should land instantly on `/identify/{run}`, show the understanding, then the OE candidates, then Auto Delta prices within seconds and Zitania availability trickling in, with no timeout. Refresh mid-run: results reload from the DB.

## Follow-up plans (not this plan)

- **`/parts` migration** onto the same run + `PriceSupplierJob` machinery (`SearchRunKind::Parts`, no Grok/PartsLink24 steps, controller creates lookups per reference x selected suppliers). Reuses Tasks 2-5 wholesale.
- **Production ops** (not code): Forge Horizon worker daemon, Reverb daemon behind TLS WebSockets, supervisor entries; dedicated PartsLink24 + Zitania accounts; rotate the PartsLink24 password.
