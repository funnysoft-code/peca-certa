# Phase 2 slice — VIN-based part identification (`/identify`)

**Date:** 2026-07-15
**Status:** Approved design, pending spec review
**Linear:** FUN-35 (Phase 2), FUN-46 (LLM understanding), FUN-47 (PartsLink24 adapter)

## Goal

Let the operator turn a vague customer request plus a VIN into priced part
candidates, without leaving Peça Certa. Grok 4.3 understands the free-text
request, PartsLink24 resolves the VIN to the genuine (OE) part reference, and
the existing Phase 1 sourcing prices it across Auto Delta + Auto Zitânia.

## Scope

**In scope (this slice):**

- New page `/identify`: free-text request + VIN (VIN required to run identification).
- Grok 4.3 (via `laravel/ai`) understands the request → part category + keywords, and asks one clarifying question when ambiguous.
- PartsLink24 client: VIN → vehicle → category → OE part reference(s).
- Feed the identified OE reference(s) into the existing `SearchAutoDeltaParts` / `SearchAutoZitaniaParts` fan-out → same results table.
- `xai` as the app-wide default AI provider.

**Deferred / out of scope:**

- Plate → VIN lookup (FUN-43/45) — the operator supplies the VIN; if there is no VIN, they use the existing `/parts` number search instead.
- Auto Delta TecDoc aftermarket-identification path — unnecessary once PartsLink24 gives the OE number, which Phase 1 already cross-references to aftermarket brands.
- Make → portal routing table (FUN-44/48) — Grok routes intent from the request; the static routing table is not needed for this slice.
- Photo/vision identification (Phase 3) — Grok 4.3 is multimodal, so this is a later extension, not now.

## Two-page structure

- **`/parts`** (exists) — direct exact-reference search across suppliers. Used when the customer gives a part number, or when no VIN is available.
- **`/identify`** (new) — request text + VIN → identified, priced OE candidates.

## Flow

1. Operator opens `/identify`, enters the customer request (PT free text) and the VIN. VIN is required to submit.
2. `UnderstandPartRequest` (Grok 4.3, structured output) → `{ category, keywords, clarifyingQuestion?, confidence }`.
3. If Grok returns a clarifying question (too ambiguous to pick a category), show it; the operator answers, and step 2 re-runs with that context. Single clarification, never a forced guess.
4. `IdentifyOeParts` (PartsLink24) — VIN → vehicle (brand catalog) → category → OE part reference(s).
5. Each OE reference is fed into the Phase 1 sourcing fan-out (`SearchAutoDeltaParts` + `SearchAutoZitaniaParts`) → the existing merged results table (Fornecedor / Marca / Artigo / Preço / Stock + "open in" buttons).

## Recon findings (2026-07-15)

- **PartsLink24 is a JSON REST API**, not a scrape: `POST /auth/ext/api/1.1/login` with `{account, user, password}` → session token; catalogs under `/pl24-*/ext/api/1.0/`. Same automation class as Auto Delta.
- Credentials valid (only error was a session limit, not auth).
- **Per-brand OE catalogs** ("P5" architecture, one catalog service per manufacturer), VIN-gated behind auth. The public `manufacturers` endpoint lists them.
- **Single concurrent session limit** (like Auto Zitânia): the app and the operator compete for one session unless a dedicated PartsLink24 account is provisioned.
- The exact authenticated VIN → parts endpoints and PartsLink24's category taxonomy were not walked (session occupied; will not force-evict the operator). These are finalized in a short build-time spike with a free session — **approved as a build-time item, not a design blocker.**

## Components

**Backend:**

- `config/ai.php`: add an `xai` provider block (`grok-4.3`, image `grok-imagine-image`, `key => env('XAI_API_KEY')`); set `default` and `default_for_images` to `xai`.
- `App\Services\PartsLink24\PartsLink24Client` — auth (login → token), single-session handling, cached token (mirrors `AutoDeltaClient`'s 24h token cache pattern, Octane-safe), VIN → vehicle → OE parts.
- `App\Services\PartsLink24\PartsLink24Token` — value object for the cached token + expiry.
- `App\Actions\UnderstandPartRequest` — Grok structured output → `PartRequestUnderstanding`.
- `App\Actions\IdentifyOeParts` — orchestrates PartsLink24 VIN → OE reference(s).
- `App\Actions\IdentifyAndSourceParts` — glue: understand → identify → price via Phase 1 actions → `PartSearchResult`.
- DTOs: `PartRequestUnderstanding` (`category`, `keywords`, `clarifyingQuestion`, `confidence`), `IdentifiedVehicle`, `OePart`. Reuse `PartVariant` / `PartSearchResult` for priced output.
- `config/suppliers.php`: `partslink24` block (`account`, `username`, `password`, `base_url`, timeouts).

**Frontend:**

- `App\Http\Controllers\IdentifyController` (`index` + `store`), routes `identify.index` (GET `/identify`) + `identify.store` (POST `/identify`).
- `resources/js/pages/identify/index.tsx` — request + VIN form, clarifying-question step, reused `ResultsTable`.

## Data / interfaces

- Grok output is a validated JSON schema via `laravel/ai` structured output — no free-text parsing.
- Category from Grok maps to PartsLink24's category taxonomy; the exact mapping (direct id vs fuzzy match) is settled in the build-time spike once the taxonomy is known.
- PartsLink24 token cached like the Auto Delta apiKey; on `session-limit-exceeded`, surface a clear operator error (see Error handling) rather than silently forcing eviction.

## Error handling

- No VIN → block identification, point the operator to `/parts` number search.
- Grok failure / timeout → retry message, no partial results.
- VIN not resolvable by PartsLink24 → "VIN não reconhecido", let the operator retry or switch to `/parts`.
- PartsLink24 session limit → explain the single-session constraint and that the account is in use elsewhere.
- No OE parts / no candidates → empty state.

## Testing

- Feature tests: `UnderstandPartRequest` with faked `laravel/ai` responses; `PartsLink24Client` + `IdentifyOeParts` with `Http::fake` + fixtures captured from the build-time spike; `IdentifyAndSourceParts` end-to-end with all providers faked.
- Browser test: `/identify` happy path (request + VIN → candidates) and the clarifying-question step.
- Quality gate green (rector, pint, phpstan max, wayfinder, ts, 100% coverage, browser).

## Operational follow-ups (not code)

- **Dedicated PartsLink24 account** (single-session) — same ask as the dedicated Auto Zitânia account. Without it, the app's identify calls and the operator's own PartsLink24 use will evict each other.
- `XAI_API_KEY` provisioned in `.env` (and rotated if the shared key has been exposed).

## Risks

- PartsLink24 VIN decoding / category taxonomy differs per brand catalog — the spike must cover at least the makes R2CZ handles most.
- Single-session contention on both PartsLink24 and Zitânia during real use until dedicated accounts exist.
- Grok category → PartsLink24 taxonomy mapping accuracy — mitigated by the clarifying-question step and by showing candidates (not false precision).
