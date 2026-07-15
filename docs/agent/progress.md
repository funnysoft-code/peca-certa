# Progress

Rolling log of what the harness has built, what's next, what's blocked, and non-obvious decisions.
Every session reads this **first**, along with `git log --oneline -20`, before touching code.

## Discipline

- **Update every commit.** One entry per commit, newest on top.
- **Four fields, no free-form essays:**
  - **Built** — what this commit actually shipped (not what you hoped for).
  - **Next** — the single next concrete step. Not a wish list.
  - **Blocked** — what's stuck + why + who/what unblocks it. `—` if nothing.
  - **Decisions** — non-obvious choices made this commit. `—` if none.
- **Be brutal.** If a test was skipped, say so. If a mock hid a real bug, say so. Future sessions rely on honesty here more than on prose.
- Short. Bullet points. Fragments OK.
- Link relevant files with repo-relative paths.
- Reference the active feature-list file (`docs/agent/features/<slug>.md`) if one is in play.

## Format

```markdown
## YYYY-MM-DD — `<short-sha>` — <one-line summary>

- **Built:** …
- **Next:** …
- **Blocked:** —
- **Decisions:** —
```

## Entries

<!-- newest entry goes here -->

## 2026-07-15 — `ecab00d` — Phase 2 Plan 1: VIN part identification (/identify)

- **Built:** `feat/vin-identification` (subagent-driven, 9 tasks + final Opus review, all gate-green). `/identify` page: PT free-text request + VIN (required) → Grok 4.3 (xAI, now app-wide default) structures request into category/keywords/clarifying-question → `PartsLink24Catalog` contract resolves VIN→OE parts (FAKE for now) → priced via existing Phase 1 fan-out → results table. New: xai config, `PartRequestUnderstanding`/`OePart`/`IdentifyResult` DTOs, `PartRequestUnderstander` agent, `UnderstandPartRequest`/`IdentifyOeParts`/`IdentifyAndSourceParts` actions, PartsLink24 contract+fake, `IdentifyController`+routes+page+form. Full gate green (phpstan max, 100% cov, browser).
- **Next:** Plan 2 (spike-gated): real `PartsLink24HttpClient` (JSON REST, `POST /auth/ext/api/1.1/login` {account,user,password}→token, single-session limit; VIN→parts endpoints need authed spike w/ free session) swapped for the fake (one binding change). Then UI parity pass (see deferred).
- **Blocked:** PartsLink24 authed spike needs a free session / dedicated account (single-session, occupied). `XAI_API_KEY` not yet in `.env` (tests fake Grok, so build didn't need it).
- **Decisions:** PartsLink24 = identifier, Phase 1 = pricer; TecDoc aftermarket-ID path deferred. xAI app-wide default (`grok-4.3`, verified vs live docs). Deferred to Plan 2 (final-review triage): I2 per-supplier failure isolation + concurrency; M2 surface 422 field errors + /parts fallback hint; M3 wire clarify re-run with carried context; M4 available/unavailable split + "Abrir em" provider links (searchUrl in payload, unused); SUPPLIER_LABELS dedupe. None block merge.

## 2026-07-15 — `7d89012` — merge Auto Zitânia adapter to main

- **Built:** merged `feat/autozitania-adapter` → main (ff, 13 commits): Zitânia DVSE Playwright sidecar, supplier fan-out, merged results table (single Preço column, collapsed Indisponíveis), Leiria-branch availability, provider "open in" buttons, sqlite WAL concurrency fix. Gate green. Branch deleted.
- **Next:** deploy prep — the Zitânia sidecar needs bun + Playwright/Chromium on the prod server (+ `AUTOZITANIA_BUN_BINARY` abs path). Then Phase 2 discovery (FUN-43 plate→VIN, FUN-44 make→portal, both "ask the uncle").
- **Blocked:** dedicated Auto Zitânia account (single-session; operator vs sidecar) — operational, uncle to request. Europeças (FUN-39/41) deferred, no creds.
- **Decisions:** —

## 2026-07-15 — Zitânia availability keyed to Leiria branch

- **Built:** Zitânia stock now reflects the LEIRIA warehouse specifically, not "available anywhere". Sidecar calls the portal's `ErpAppWSVC.GetErpInfosAL` JS proxy in-browser and reads per-branch `AvailState` for Leiria. Regenerated fixture from live Leiria run; gate green; live PHP action = 11/29 available at Leiria.
- **Next:** commit + still pending user sign-off to merge `feat/autozitania-adapter`.
- **Blocked:** —
- **Decisions:** Exact unit quantities CONFIRMED unavailable for account 125200C — dug to the raw web-service JSON: `Quantity` field exists per branch but is zeroed even on available parts (supplier entitlement). Only fix is Zitânia enabling stock qty on the account. Per-branch availability (Leiria) is the finest real signal. Service 500s on ERP batches >~7 items → sidecar chunks at 5, failed chunks fall back to overall availability. Warehouse via `AUTOZITANIA_WAREHOUSE` env (default LEIRIA), no PHP config plumbing.

## 2026-07-15 — `9342544` — merged supplier table + collapsed unavailable rows

- **Built:** single results table w/ Fornecedor column (sorted by brand, so same-brand offers sit together), out-of-stock rows from both suppliers under an "Indisponíveis (N)" collapsible, per-supplier pending hint (Auto Delta shows while Zitânia's ~30s run finishes). Verified live in the real app via headless browser. Also fixed prod-path bug: Herd PHP couldn't find `bun` (added `AUTOZITANIA_BUN_BINARY` env, set to absolute path in .env).
- **Next:** merge `feat/autozitania-adapter` to main when user confirms UX.
- **Blocked:** —
- **Decisions:** Zitania quantities NOT displayable: catalog list only exposes binary availability to this account (tooltip is a static template, no lazy ERP call on hover/click). Re-verify if uncle's view shows numbers; may be an account permission.

## 2026-07-15 — `0ece840` — Auto Zitania adapter (Playwright sidecar) + supplier fan-out

- **Built:** Phase 1 verified live w/ rotated creds and merged to main (FUN-33/37 done). Auto Zitania adapter (FUN-40/42): `bin/zitania-search.ts` Playwright sidecar (login + single-session takeover + scrape), `AutoZitaniaClient` via Process, `SearchAutoZitaniaParts`, `Supplier` enum + `supplier` param on `/parts/search`, UI fires both suppliers in parallel w/ per-section loading. Full gate green; verified live (29 variants `OC 90`). Also fixed gate browser step (pao exit-code) and added `bin/**/*.ts` to tsconfig.
- **Next:** ask uncle for a dedicated Zitania account (single-session limit: each app search evicts his portal session); then FUN-43/44 discovery for Phase 2.
- **Blocked:** Europeças (FUN-39/41) deferred, no credentials.
- **Decisions:** no adapter interface, exhaustive `match()` on `Supplier` enum (FUN-42 comment). Zitania exposes retail P.V.P. + binary availability only, no purchase price/quantities; UI renders Disponivel/Indisponivel for it. Zitania portal has PT plate+VIN search, candidate plate resolver for Phase 2 (noted FUN-45).
