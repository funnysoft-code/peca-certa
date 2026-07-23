# Agent Instructions

Agent-facing rules for long-running autonomous sessions on this repo.
For framework-level rules see root `CLAUDE.md` (Boost auto-managed — do not edit by hand).

## Core Principles

- **READ before WRITE.** Never hallucinate state, column names, or route shapes.
- Concise, technical, logical. No fluff.
- Use Laravel Boost MCP tools for discovery before making changes.
- Run commands directly: `php artisan`, `composer`, `bun` (no prefix needed).
- Prefer editing existing files over creating new ones.
- Commits: Conventional Commits format, descriptive, one logical change each.

## Stack (pinned)

PHP ^8.5 · Laravel 13 · Inertia 3 + React 19 · Tailwind 4 · shadcn/ui (new-york) · Pest 5 · PHPStan max · Rector · Pint · PostgreSQL primary · Bun ≥1.2 · vite-plus.

## Safety Rails — NEVER touch without asking

Enforced deterministically by `.claude/hooks/safety-rails.sh` (PreToolUse hook on Edit/Write/NotebookEdit). The hook exits 2 — the call is **blocked**, not warned. Update both the hook and this list when adding new rails.

Hard-blocked paths (irreversible or sensitive):

- `.env`, `.env.*` — secrets and env-specific state. **`.env.example` is allowed.**
- `composer.lock`, `bun.lock` — dependency locks; only tools should modify.
- `vendor/`, `node_modules/`, `bootstrap/cache/` — generated/vendored trees.
- `_ide_helper*.php`, `.phpstorm.meta.php` — auto-generated IDE stubs; never hand-edit.
- `bin/deploy.sh` — production deploy script.
- `database/migrations/*.php` already run (`php artisan migrate:status` confirms). New migration files pass through.

`config/*.php`, `composer.json`, and `package.json` are **not** blocked — they are normal, reversible edits required during scaffolding and dependency work.

## Workflow: Backend Changes

1. Run `application-info` and `database-schema` (Boost MCP) first.
2. Run `php artisan route:list` to verify endpoints.
3. Use `php artisan tinker` to test queries before writing code.
4. On 500 errors: STOP and run `last-error` (Boost MCP) immediately.

## Workflow: Frontend / UI

1. Search shadcn registry before creating components (`@radix-ui/*` primitives already installed).
2. Use `browser-logs` (Boost MCP) to check JS errors after changes.
3. Inertia pages live in `resources/js/pages/`. Wayfinder generates typed route fns in `@/actions/` + `@/routes/`.
4. After any controller change: run `php artisan wayfinder:generate --with-form` to keep types in sync.
5. If UI change not reflected: ask user to run `bun run dev` or `composer run dev`.

## Workflow: Debugging

1. Backend errors: `last-error` or `read-log-entries` (Boost MCP).
2. Frontend errors: `browser-logs` (Boost MCP).
3. Visual issues: use `dev-browser` skill to screenshot the real app.

## Workflow: Documentation Lookup

- Laravel ecosystem: `search-docs` (Boost MCP) — returns version-pinned docs.
- Third-party packages: Context7 `npx ctx7@latest library <name>` → `npx ctx7@latest docs <id>`.
- Business/domain context: Obsidian vault at `~/Code/obsidian-vault/projects/` — read before product decisions.

## Workflow: Code Quality (PHP)

Before declaring any implementation task complete, run `bin/quality-gate.sh`. Exit 0 = done; non-zero = NOT done. Surface raw output; do not paraphrase; do not commit.

Order after modifying any PHP file:

1. `vendor/bin/rector --no-diffs` — auto-fix code quality.
2. `vendor/bin/pint --dirty --format agent` — auto-fix code style.
3. `vendor/bin/phpstan analyse --memory-limit=2G` — static analysis, zero errors required.

End-of-task verification: `bin/quality-gate.sh` (verify-only; fail-fast over Rector --dry-run, Pint --test, PHPStan, `wayfinder:generate` drift, bun test:lint, bun test:types, Pest non-browser parallel, then Pest browser serial). Exit codes 2..9 map to the failing step. Non-zero MUST block any completion claim, commit, push, or PR. Or invoke `/qg` slash command.

**Enforced before push (do not bypass without explicit user reason):**

1. **Git (vite-plus hooks)** — `core.hooksPath=.vite-hooks/_`; `.vite-hooks/pre-push` runs `bin/quality-gate.sh`. `.vite-hooks/pre-commit` runs `vp staged` + `bin/quality-gate.sh --fast`. Bypass only with reason: `VITE_GIT_HOOKS=0`.
2. **Harness** — PreToolUse on shell tools runs `.claude/hooks/require-quality-gate-before-push.sh` and denies `git push` if the gate fails (Claude / Cursor / Grok).

**Exit-code → fix map:** `2` → `pint --dirty`; `5` → `vp lint`; `6` → `tsc`; `7` → `wayfinder:generate`; `9` → `rector`.

## Workflow: Code Quality (JS/TS)

After modifying any `.ts` / `.tsx` / `.js` file:

1. `bun run lint` — vite-plus fmt + lint --fix.
2. `bun run test:types` — `tsc --noEmit`, zero errors required.

## Workflow: Tests

- **All tests (parallel, compact):** `php artisan test --parallel --compact`
- **Single file:** `php artisan test --compact tests/Feature/Foo/BarTest.php`
- **Filter by name:** `php artisan test --compact --filter=testName`
- **Coverage:** `XDEBUG_MODE=coverage php artisan test --parallel --coverage --exactly=100.0 --exclude-testsuite=Browser`
- **Type coverage:** `vendor/bin/pest --type-coverage --min=100`
- **Feature > Unit** — most tests should hit the HTTP layer.
- Pest 5 browser tests via `pestphp/pest-plugin-browser` + Playwright.
- **Never delete a test without explicit approval.**

## Workflow: Database

- **Fresh + seed (dev):** `php artisan migrate:fresh --seed` — ⚠️ drops all tables.
- **Seed specific:** `php artisan db:seed --class=SeederName`
- **Status:** `php artisan migrate:status`
- **Rollback last batch:** `php artisan migrate:rollback` — only if migration not yet in prod.
- Migration conventions live in `database/migrations/CLAUDE.md`.

## Subagents

Project-scoped subagents under `.claude/agents/`:

- `db-schema-explorer` — wraps Boost MCP `database-schema` + `php artisan route:list --json` (via Bash) + a folded read of `app/Models/<Name>.php` into one call. Use as the read-before-write step for any backend change.
- `dto-typescript-syncer` — runs `php artisan typescript:transform` and reports drift on `resources/js/types/generated.d.ts`. Use after touching `app/Data/`, `app/Enums/`, or any `#[TypeScript]`-annotated class. (Available when `--ts-transformer` module is installed.)

## Emergency Protocol

Stuck after 2 attempts: delegate to a specialized subagent or ask the user. **Do not spiral.**

## Distributed Context

Directory-specific conventions live in sibling `CLAUDE.md` files; Claude Code loads them automatically when editing files in that directory.

Key ones:
- `app/CLAUDE.md` — `final` classes, `declare(strict_types=1)`, `casts()` method, `execute()` action pattern.
- `app/Actions/CLAUDE.md` — `final readonly`, single `execute()`, `DB::transaction`, `#[\SensitiveParameter]`.
- `app/Http/Controllers/CLAUDE.md` — `final readonly`, method injection, `#[CurrentUser]`, `to_route()` + flash.
- `app/Models/CLAUDE.md` — `HasUuids`, `#[Hidden]`, `casts()`, `getRouteKeyName`.
- `app/Jobs/CLAUDE.md` — `handle()` (distinct from Actions), `#[Timeout]/#[Tries]/#[Backoff]` attributes, `failed()`.
- `database/migrations/CLAUDE.md` — anonymous class syntax, PostgreSQL types, column-modify gotcha.
- `tests/CLAUDE.md` — Pest 5 conventions, `networkidle` browser waits.

## Editor Parity

- `AGENTS.md` at repo root + inside directories is a symlink to `CLAUDE.md` (same file) for cross-agent parity (Cursor and other AGENTS.md-aware tools). Edit one, both update.
- `.cursor/AGENTS.md` symlinks to `.claude/CLAUDE.md` for Cursor CLI.
- MCP servers: Boost (local, always on). See `.mcp.json`.

## Replies

Be concise. Focus on what matters, not what's obvious.

## Octane Safety Rails

Octane keeps the application in memory. Violating these rules causes request bleed:

- NEVER store mutable state in static properties on service classes or repositories.
- NEVER bind a stateful object as a singleton in the container unless it is explicitly
  reset via `$this->app->forgetScopedInstances()` or the `octane:flush` list.
- NEVER cache `request()`, `auth()->user()`, or any per-request object outside the
  request lifecycle (i.e. not in a property assigned at __construct time on a singleton).
- ALWAYS inject the `Request` object via method parameters — never capture it at
  service-provider / constructor time.
- ALWAYS check: does this class hold any property that changes per-request? If yes,
  it must NOT be a singleton.
