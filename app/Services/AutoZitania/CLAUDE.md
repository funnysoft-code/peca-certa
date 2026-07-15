# AutoZitania Service

## Conventions

- `final readonly class` for all service classes.
- `declare(strict_types=1)` at the top of every file.
- `AutoZitaniaClient` shells out to the Playwright sidecar (`bin/zitania-search.ts`, run with Bun) because the portal is a DVSE/TOPMOTIVE WebForms catalog with no JSON API.
- The portal allows a single concurrent session per account; every sidecar run may evict an interactive session using the same login.
- The catalog exposes retail (P.V.P.) prices and binary availability only, no purchase prices and no stock quantities.
- Config values accessed via `config('suppliers.autozitania.*')`, never `env()` directly.
- Credentials are passed to the sidecar through explicit `Process` env vars, never as CLI arguments.
