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
