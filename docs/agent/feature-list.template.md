# Feature: <name>

> Planner fills this in before Generator starts. Copy this file to `docs/agent/features/<slug>.md` and work from the copy. The Generator only reads the copy — it never reads this template directly.

## Meta

- **Slug:** `<kebab-case-slug>`
- **Linear / ticket:** `<URL or N/A>`
- **Domain docs:** `<path in obsidian-vault/projects/<app>/ or N/A>`
- **Author (Planner):** `<agent or human>`
- **Generator session target:** `<estimate — small/medium/large>`
- **Evaluator session target:** `<small/medium/large>`

## Problem

One paragraph. What user pain or business need does this address? Cite the domain doc if one exists.

## Goals

- [ ] Goal 1 — observable outcome, not implementation.
- [ ] Goal 2
- [ ] Goal 3

## Non-goals

- Out of scope 1 — state *why* to prevent scope drift.
- Out of scope 2

## User stories

- [ ] As a `<role>`, I can `<action>` so that `<outcome>`.
- [ ] As a `<role>`, I can `<action>` so that `<outcome>`.

## Acceptance criteria

Testable, unambiguous, binary.

- [ ] AC1: Given `<state>`, when `<action>`, then `<result>`.
- [ ] AC2: …
- [ ] AC3: …

## Design decisions

Call out the non-obvious ones up front. Prevents Generator from re-deciding mid-session.

- **Routing:** `<path + method>` (named route `<name>`).
- **Authorization:** policy / gate.
- **Data shape:** DTO or spatie/laravel-data class, key fields.
- **Frontend:** Inertia page vs API response.
- **Queue / async:** yes/no, which queue, retries.
- **Feature flag:** `App\Enums\Flag::<case>` if gated.
- **Trade-offs accepted:** things you deliberately chose not to solve now.

## Files to create / modify

Be specific. Generator treats this as the working set. Anything outside triggers a re-plan.

### Create

- [ ] `app/Actions/...`
- [ ] `app/Models/...`
- [ ] `database/migrations/<timestamp>_...php`
- [ ] `database/factories/...`
- [ ] `resources/js/pages/...`
- [ ] `tests/Feature/...`

### Modify

- [ ] `routes/web.php` — add `<route>`
- [ ] `<existing file>` — `<why>`

## Tests

- [ ] Feature: happy path per AC.
- [ ] Feature: unauthorized / forbidden path.
- [ ] Feature: validation failures.
- [ ] Unit: action-level invariants.
- [ ] Browser (Pest 5): critical UI interaction (if Inertia touchpoint).
- [ ] Regression: existing tests still pass (`bin/quality-gate.sh`).

## Data / migrations

- [ ] Migration reversible (`down()` implemented).
- [ ] Seeder or factory state added if the feature needs test data.
- [ ] No destructive change to tables already in prod.

## Rollout

- [ ] Behind a feature flag? `<name>` — default off.
- [ ] Backfill required? `<yes/no>` — strategy: `<idempotent job / migration / manual>`.
- [ ] Telemetry: events to emit, monitoring tags.

## Risks & unknowns

Surface these so the Generator flags them instead of guessing.

- Risk 1 — mitigation.
- Unknown 1 — how Generator should decide if it comes up (default choice).

## Done criteria

All of these must be ✅ before handoff to Evaluator:

- [ ] All acceptance criteria checked.
- [ ] `bin/quality-gate.sh` exits 0 on the branch.
- [ ] `docs/agent/progress.md` updated with Built / Next / Decisions.
- [ ] Commits are Conventional Commits, one logical change each.
- [ ] PR description includes: problem, goals met, AC checklist, test plan.
