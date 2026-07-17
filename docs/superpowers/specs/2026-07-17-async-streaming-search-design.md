# Async Streaming Search (queue + Reverb) — design

**Date:** 2026-07-17
**Status:** Approved design, pending spec review
**Supersedes (runtime shape of):** synchronous `IdentifyAndSourceParts` fan-out and the `/parts` client-side two-request fan-out.

## Problem

`/identify` now uses the real PartsLink24 client, which returns several OE candidates. The synchronous controller prices each candidate across Auto Delta (fast JSON) and Auto Zitania (a ~30s headless-browser scrape), serially, inside one web request. Five candidates times ~30s of Zitania blows PHP's 30s limit and 500s the request ("Falha na identificacao"), discarding the already-paid Grok + PartsLink24 work. The fake catalog returned one part, so this never surfaced before.

The fix is architectural: move every slow external call off the request onto queued jobs, persist results as they land, and stream them to the browser over Reverb/Echo. This also generalizes: any slow AI or supplier call becomes a job that persists and broadcasts.

## Goal

Turn `/identify` and `/parts` into run-based, progressively-streaming searches: the request returns instantly with a persisted run; jobs price candidates across suppliers in the background (respecting each supplier's session limits) and broadcast each result as it completes; the page renders results as they arrive and reloads them from the DB on refresh.

## Decisions (locked)

- **Scope:** full sweep. Build the reusable job + broadcast pattern and apply it to `/identify`, `/parts`, and every AI/supplier call (Grok understanding, Auto Delta, Auto Zitania, PartsLink24 identification).
- **Persistence:** persist runs + results in the DB. Jobs write rows, then broadcast; the page reads/merges and reloads from the DB on refresh (survives refresh and the connect-after-job-finished race).
- **Orchestration:** `Bus::chain` for the sequential part (understand then identify); the identify job then fans out one independent pricing job per (candidate x supplier).
- **Zitania coverage:** price ALL OE candidates via Zitania (serialized, one at a time). Because it is async the ~30s-each trickle is acceptable; Auto Delta results land in seconds.
- **Existing Actions unchanged:** jobs wrap the existing `UnderstandPartRequest`, `IdentifyOeParts`, `SearchAutoDeltaParts`, `SearchAutoZitaniaParts` actions. Actions stay pure and synchronous; jobs own persistence + broadcasting + orchestration.

## Infrastructure (already installed, currently unused)

- `QUEUE_CONNECTION=redis`, Horizon installed + `HorizonServiceProvider` registered.
- `BROADCAST_CONNECTION=reverb`, Reverb configured (`:8080`), `routes/channels.php` present (only the default `App.Models.User.{id}` channel).
- Frontend: `resources/js/echo.ts`, `app.tsx`, and a scaffolded `echo-listener.tsx`.
- No `app/Jobs` or `app/Events` yet. This design creates them.

## Architecture

```
POST /identify  -> validate -> create SearchRun(kind=identify, status=pending)
                -> Bus::chain([UnderstandRequestJob, IdentifyOePartsJob]) -> redirect to /identify/{run}

UnderstandRequestJob            (queue: ai)          calls UnderstandPartRequest
   -> writes run.understanding, broadcasts UnderstandingReady
   -> if needsClarification: run.status=done, stop (no pricing)
IdentifyOePartsJob              (queue: partslink24) calls IdentifyOeParts
   -> writes run.oe_parts, broadcasts CandidatesReady
   -> creates a SupplierLookup row per (OE part x supplier), dispatches PriceSupplierJob per row
PriceSupplierJob                (queue: autodelta | zitania)  calls the supplier action
   -> writes the lookup row (done | empty | failed), broadcasts SupplierResultReady
   -> if all lookups for the run are terminal: run.status=done, broadcasts RunCompleted

POST /parts     -> create SearchRun(kind=parts) + SupplierLookup per (reference x selected suppliers)
                -> dispatch PriceSupplierJob per row -> redirect to /parts/{run}
                (no Grok / no PartsLink24 steps)
```

Frontend: `/identify/{run}` and `/parts/{run}` render immediately with the run and any persisted rows (initial + deferred Inertia props), subscribe to the run's private channel, and merge each broadcast into state.

## Data model (new)

- `search_runs`: `id (uuid, HasUuids)`, `user_id (fk)`, `kind (SearchRunKind: identify|parts)`, `request_text (nullable)`, `vin (nullable)`, `reference (nullable)`, `understanding (json nullable)`, `oe_parts (json nullable)`, `status (SearchRunStatus: pending|running|done|failed)`, timestamps.
- `supplier_lookups`: `id (uuid)`, `search_run_id (fk, cascade)`, `supplier (Supplier enum)`, `query (string: the OE number or reference)`, `oe_description (nullable)`, `status (SupplierLookupStatus: pending|running|done|failed|empty)`, `result (json nullable: the existing PartSearchResult DTO)`, `error (nullable)`, timestamps.
- Enums: reuse `App\Enums\Supplier`; add `SearchRunKind`, `SearchRunStatus`, `SupplierLookupStatus` (all `#[TypeScript]` where the frontend needs them).
- Models: `SearchRun` (HasUuids, hasMany lookups, belongsTo user), `SupplierLookup` (belongsTo run). Factories + a route-key on the uuid.

The `understanding` and `oe_parts` JSON columns hold the existing `PartRequestUnderstanding` / `OePart[]` DTOs; `supplier_lookups.result` holds `PartSearchResult`. No DTO shapes change.

## Jobs (new, `app/Jobs`)

All jobs: `HasUuids`-free plain jobs, `handle()` method (Job convention, distinct from Actions' `execute()`), `#[Tries]`/`#[Timeout]`/`#[Backoff]` attributes sized per queue, `failed(Throwable)` marks the row/run `failed` and broadcasts. Injectable Actions resolved from the container in `handle()`.

- `UnderstandRequestJob(SearchRun $run)` — queue `ai`, timeout ~60s. Calls `UnderstandPartRequest`; writes `run.understanding`; broadcasts. On `needsClarification()`, sets `run.status=done` and does not continue the chain (the chained `IdentifyOePartsJob` is skipped via an early `$this->delete()`/guard or by not chaining when clarification is possible — resolved in the plan).
- `IdentifyOePartsJob(SearchRun $run)` — queue `partslink24`, `WithoutOverlapping` keyed to the account, timeout ~60s. Calls `IdentifyOeParts` with `run.understanding->searchTerm`; writes `run.oe_parts`; broadcasts; creates `SupplierLookup` rows and dispatches `PriceSupplierJob` per (OE x supplier).
- `PriceSupplierJob(SupplierLookup $lookup)` — queue is the supplier's (`autodelta` or `zitania`); Zitania adds `WithoutOverlapping` keyed to the supplier; timeout per supplier (autodelta ~30s, zitania ~90s). Calls the supplier action with `lookup.query`; writes `done` (with `result`) / `empty` / `failed`; broadcasts; then atomically checks whether all lookups for the run are terminal and, if so, marks the run `done` and broadcasts `RunCompleted`.

Run-completion check is a transaction-guarded count so the last-finishing job (in any order) closes the run exactly once.

## Supplier concurrency (Horizon queues)

Slow work off the request is not enough; supplier session limits cap real parallelism. Horizon supervisors:

| Queue | Max processes | Rationale |
|---|---|---|
| `ai` | ~3 | xAI API, parallel-safe |
| `autodelta` | ~3 | TecAlliance JSON, 24h token cache, parallel-safe |
| `zitania` | **1** + `WithoutOverlapping` | one browser session on the account; parallel runs evict each other |
| `partslink24` | **1** + `WithoutOverlapping` | single session / `squeezeOut`; serialize to avoid self-eviction |
| `default` | ~3 | everything else |

Auto Delta prices for all candidates land in ~seconds (parallel); Zitania trickles one-at-a-time (~30s each). `config/horizon.php` supervisors updated accordingly.

## Broadcasting

- Private channel `search-run.{id}`, authorized in `routes/channels.php` to `SearchRun::find($id)?->user_id === $user->id`.
- Events (all `ShouldBroadcast`, `broadcastOn(): PrivateChannel("search-run.{$run->id}")`, carrying the changed row as `broadcastWith`): `UnderstandingReady`, `CandidatesReady`, `SupplierResultReady`, `RunCompleted`, `RunFailed`.

## Frontend

- `IdentifyController`: `create` (GET `/identify`, the form + recent runs), `store` (POST -> create run, dispatch chain, redirect to `show`), `show` (GET `/identify/{run}` -> Inertia render with the run, its understanding/oe_parts, and lookups; heavy lists via `Inertia::defer`).
- `PartSearchController`: same `create`/`store`/`show` shape for `/parts/{run}` (reference input; no Grok/PartsLink24).
- The run page subscribes with `useEcho('search-run.{id}', [...events], handler)` (the scaffolded `echo-listener.tsx` pattern), seeding state from the initial DB props and merging each broadcast. Per-section states: pending (skeleton) -> streaming -> done/failed. Reuse the existing `results-table.tsx` / available-vs-unavailable + "Abrir em" provider links (this also delivers the deferred Plan 1 UI-parity items).
- A lightweight "recent searches" list on each create page (the user's last N runs), enabled by persistence.
- Wayfinder regenerated for the new routes; `#[TypeScript]` enums/DTOs regenerated.

## Error handling

- Per-job isolation: a `PriceSupplierJob` failure marks only its lookup `failed` and broadcasts; the run and sibling results survive (this resolves the deferred Plan 1 finding I2).
- The Grok understanding is persisted the instant it returns, so a later failure never loses it.
- `$tries` + backoff per job; `failed()` writes a terminal state and broadcasts so the UI never hangs on a spinner.
- Job timeouts sized per supplier; a job timeout marks the row `failed`, not the whole run.
- Clarification path: understanding with a clarifying question ends the run cleanly (done, no pricing); the operator answers and submits a new run (carrying the prior request as context is a later refinement).

## AI + other requests as jobs

The Grok understanding is the first "AI as a job" case (`UnderstandRequestJob` on the `ai` queue, reusing `UnderstandPartRequest`). The same shape (job wraps action, persists, broadcasts) is the reusable pattern for any future slow external call. `/parts` reuses `PriceSupplierJob` directly.

## Dev / ops runtime

- Extend the `composer run dev` script to boot Horizon + `reverb:start` alongside Octane + Vite so local dev streams end-to-end.
- Production (Forge, follow-up, not code): a Horizon worker daemon, a Reverb daemon behind TLS/WebSockets, and supervisor entries. Documented as operational follow-ups.

## Testing

- Job feature tests: each job writes the right rows and dispatches the right next step (`Bus::fake` to assert the chain/fan-out; `Event::fake`/broadcasting assertions for the events); run-completion closes exactly once; per-supplier failure isolates.
- Channel authorization test (owner allowed, non-owner denied).
- Controller tests: `store` creates a run and dispatches the chain (`Bus::fake`); `show` renders persisted rows.
- Browser test: submit `/identify`, with suppliers + Grok faked, assert the run page renders the persisted results (loaded from the DB via the deferred prop). Live socket streaming is covered by the job/event unit tests, not the browser (Reverb e2e in a headless test is out of scope).
- Full gate green: rector, pint, phpstan max, wayfinder drift, ts types, 100% code + type coverage, Pest browser.

## Risks

- Operator-vs-app supplier contention persists (single sessions); serialized queues fix app-internal contention only. Dedicated PartsLink24 + Zitania accounts remain the operational fix.
- Reverb + Horizon must run in prod; new deploy/ops surface (Forge daemons).
- Reverb streaming is not covered end-to-end by browser tests; correctness rests on job/event unit tests plus the persisted-render test.
- Octane long-running process interactions with queued jobs and broadcasting (keep jobs stateless; no request-scoped state).
- Zitania pricing all candidates means the slowest run finishes streaming in ~2.5min; acceptable because async and progressive, but the UI must make in-progress state obvious.

## Operational follow-ups (not code)

- Forge: Horizon worker + Reverb daemon + supervisor + TLS WebSocket endpoint.
- Dedicated PartsLink24 and Auto Zitania accounts (single-session contention).
- Rotate the PartsLink24 password (shared in chat during the spike).
